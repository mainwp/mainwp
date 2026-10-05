<?php
/**
 * MainWP Database Knowledge
 *
 * Storage, provenance and permissions for site and client knowledge records.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_DB_Knowledge
 *
 * @package MainWP\Dashboard
 */
class MainWP_DB_Knowledge extends MainWP_DB { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, Generic.Metrics.CyclomaticComplexity -- unprepared SQL ok, accessing the database directly to custom database functions.

    /**
     * Save origin for edits made by a person in the Dashboard UI.
     *
     * @var string
     */
    const ORIGIN_UI = 'ui';

    /**
     * Save origin for edits made through an ability (AI agents, API clients).
     *
     * @var string
     */
    const ORIGIN_ABILITY = 'ability';

    /**
     * Allowed scope types.
     *
     * @var string[]
     */
    const SCOPE_TYPES = array( 'agency', 'site', 'client' );

    /**
     * Allowed record types.
     *
     * @var string[]
     */
    const RECORD_TYPES = array( 'context', 'skill', 'memory' );

    /**
     * Maximum title length in characters.
     *
     * @var int
     */
    const TITLE_MAX_LENGTH = 200;

    /**
     * Maximum body length in characters.
     *
     * @var int
     */
    const BODY_MAX_LENGTH = 10000;

    /**
     * Maximum skill description length in characters.
     *
     * @var int
     */
    const DESCRIPTION_MAX_LENGTH = 1024;

    /**
     * Number of body characters returned for table previews.
     *
     * @var int
     */
    const BODY_PREVIEW_LENGTH = 100;

    /**
     * Maximum caller note length in characters.
     *
     * @var int
     */
    const CALLER_NOTE_MAX_LENGTH = 200;

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_DB_Knowledge
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
    public function __construct() {
        parent::__construct();
        add_filter( 'mainwp_db_install_tables', array( $this, 'hook_db_install_tables' ), 10, 3 );
    }

    /**
     * Method hook_db_install_tables()
     *
     * Get the query to install db tables.
     *
     * @param array  $sql input filter.
     * @param string $currentVersion Current db Version.
     * @param string $charset_collate charset collate.
     *
     * @return array $sql queries.
     */
    public function hook_db_install_tables( $sql, $currentVersion, $charset_collate ) {
        $tbl = 'CREATE TABLE ' . $this->table_name( 'knowledge' ) . " (
    id bigint(20) unsigned NOT NULL auto_increment,
    scope_type enum('agency','site','client') NOT NULL,
    scope_id bigint(20) unsigned NOT NULL,
    `type` enum('context','skill','memory') NOT NULL,
    title varchar(200) NOT NULL,
    description varchar(1024) NOT NULL DEFAULT '',
    body longtext NOT NULL,
    created_by bigint(20) unsigned NOT NULL DEFAULT 0,
    updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
    created_at int(11) unsigned NOT NULL DEFAULT 0,
    updated_at int(11) unsigned NOT NULL DEFAULT 0,
    revision int(11) unsigned NOT NULL DEFAULT 1,
    source enum('ui','ability') NOT NULL,
    caller_note varchar(200) NOT NULL DEFAULT '',
    verified tinyint(1) NOT NULL DEFAULT 0,
    required tinyint(1) NOT NULL DEFAULT 0,
    KEY scope_type_id_type (scope_type,scope_id,`type`)";

        // dbDelta cannot re-add a primary key to an existing table, so only the run that creates it declares one. Keyed on the table, not the stored schema version: an install whose version already passed this table's release (a beta line with a higher number, or a run whose dbDelta failed) would otherwise never get the key, and MySQL refuses an auto_increment column that is not a key.
        $exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table_name( 'knowledge' ) ) ) );
        if ( null === $exists ) {
            $tbl .= ',
    PRIMARY KEY  (id)';
        }

        $tbl  .= ') ' . $charset_collate;
        $sql[] = $tbl;

        return $sql;
    }

    /**
     * Whether the current user can read a record.
     *
     * @param object $record Record, or a stub carrying scope_type and scope_id.
     *
     * @return bool|\WP_Error True when allowed, WP_Error otherwise.
     */
    public function can_read( object $record ) {
        return $this->check_record_access( $record, 'site' === $record->scope_type ? 'access_individual_dashboard' : 'manage_clients' );
    }

    /**
     * Whether the current user can create, update or delete a record.
     *
     * @param object $record Record, or a stub carrying scope_type and scope_id.
     *
     * @return bool|\WP_Error True when allowed, WP_Error otherwise.
     */
    public function can_write( object $record ) {
        return $this->check_record_access( $record, 'site' === $record->scope_type ? 'edit_sites' : 'manage_clients', true );
    }

    /**
     * Scope leg first, then the Team Control right.
     *
     * Out-of-scope objects keep the helper's 404 so they remain indistinguishable
     * from missing ones. Partial client coverage uses the same denial as a
     * missing write right because client records affect every assigned site.
     *
     * @param object $record                Record or stub.
     * @param string $right                 Dashboard right slug.
     * @param bool   $require_full_coverage Whether access must cover every client site.
     *
     * @return bool|\WP_Error True when allowed, WP_Error otherwise.
     */
    private function check_record_access( object $record, string $right, bool $require_full_coverage = false ) {
        $scope_id = isset( $record->scope_id ) ? (int) $record->scope_id : 0;
        $type     = $record->scope_type ?? '';

        if ( 'agency' === $type ) {
            $base = MainWP_Abilities_Util::check_rest_api_permission();
            if ( is_wp_error( $base ) || ! $require_full_coverage ) {
                return $base;
            }
            $scope = MainWP_Abilities_Util::check_unrestricted_access();
        } elseif ( 'site' === $type ) {
            $scope = MainWP_Abilities_Util::check_site_access( $scope_id );
        } elseif ( 'client' === $type ) {
            $scope = $this->guard_read( static fn() => MainWP_Abilities_Util::check_client_access( $scope_id, null, $require_full_coverage ) );
        } else {
            return $this->invalid( 'scope_type' );
        }

        if ( is_wp_error( $scope ) ) {
            $partial_client_write = 'client' === $type && $require_full_coverage && 'mainwp_access_denied' === $scope->get_error_code();
            if ( ! $partial_client_write ) {
                return $scope;
            }
        }

        if ( is_wp_error( $scope ) || ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
            return new \WP_Error(
                'mainwp_access_denied',
                __( 'You do not have permission to access this knowledge record.', 'mainwp' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Site and client IDs whose records the current user can read.
     *
     * Sites run check_site_access() per candidate, so the per-site
     * mainwp_check_site_access filter applies to lists as it does to single
     * reads. Clients have no per-client filter, so their any-overlap rule is
     * resolved in one query.
     *
     * @return array{sites: int[], clients: int[]}|\WP_Error Scopes, or a read error rather than a partial set.
     */
    public function visible_scopes() {
        $sites   = array();
        $clients = array();

        if ( MainWP_Abilities_Util::current_user_has_operation_right( 'access_individual_dashboard' ) ) {
            $candidates = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
            if ( 'all' === $candidates ) {
                $this->wpdb->last_error = '';
                $candidates             = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT id FROM %i', $this->table_name( 'wp' ) ) );
                if ( '' !== $this->wpdb->last_error ) {
                    return $this->db_read_error();
                }
            }
            foreach ( (array) $candidates as $site_id ) {
                $site_id = (int) $site_id;
                if ( $site_id > 0 && true === MainWP_Abilities_Util::check_site_access( $site_id ) ) {
                    $sites[] = $site_id;
                }
            }
        }

        // Every candidate goes through check_client_access() itself, so the list and count see exactly the clients a single-record read would allow; a cheaper id map would drift from that contract (it resolves client sites without the wp_sync join the contract uses).
        if ( MainWP_Abilities_Util::current_user_has_operation_right( 'manage_clients' ) ) {
            $this->wpdb->last_error = '';
            $candidates             = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT client_id FROM %i', $this->table_name( 'wp_clients' ) ) );
            if ( '' !== $this->wpdb->last_error ) {
                return $this->db_read_error();
            }
            foreach ( (array) $candidates as $client_id ) {
                $client_id = (int) $client_id;
                if ( $client_id > 0 ) {
                    $access = $this->guard_read( static fn() => MainWP_Abilities_Util::check_client_access( $client_id ) );
                    if ( is_wp_error( $access ) && 'mainwp_knowledge_db_error' === $access->get_error_code() ) {
                        return $access;
                    }
                    if ( true === $access ) {
                        $clients[] = $client_id;
                    }
                }
            }
        }

        return array(
            'agency'  => true === MainWP_Abilities_Util::check_rest_api_permission(),
            'sites'   => array_values( array_unique( $sites ) ),
            'clients' => array_values( array_unique( $clients ) ),
        );
    }

    /**
     * SQL condition limiting rows to the current user's visible scopes.
     *
     * Deliberately not the DB layer's allowed-sites clause: that one is
     * skipped under REST, cron and CLI. Fails closed to 1=0.
     *
     * @return string SQL condition.
     */
    public function scope_where_sql(): string {
        $where = $this->scope_where_template();
        if ( is_wp_error( $where ) ) {
            return '1=0';
        }
        list( $template, $values ) = $where;

        // nosec -- the template contains only fixed strings and counted placeholders; every value is bound.
        return empty( $values ) ? $template : $this->wpdb->prepare( $template, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the template contains only fixed strings and counted placeholders; every value is bound.
    }

    /**
     * Keep scope placeholders separate from values until the query is complete.
     *
     * @return array|\WP_Error SQL template and ordered values, or the scope read error.
     */
    private function scope_where_template() {
        $scopes = $this->visible_scopes();
        if ( is_wp_error( $scopes ) ) {
            return $scopes;
        }
        $parts  = array();
        $values = array();

        if ( $scopes['agency'] ) {
            $parts[]  = '(scope_type = %s AND scope_id = %d)';
            $values[] = 'agency';
            $values[] = 0;
        }

        $lists = array(
            'site'   => $scopes['sites'],
            'client' => $scopes['clients'],
        );
        foreach ( $lists as $scope_type => $ids ) {
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $parts[]      = "(scope_type = %s AND scope_id IN ({$placeholders}))";
                $values[]     = $scope_type;
                $values       = array_merge( $values, array_map( 'intval', $ids ) );
            }
        }

        return array( empty( $parts ) ? '1=0' : '(' . implode( ' OR ', $parts ) . ')', $values );
    }

    /**
     * List records visible to the current user.
     *
     * @param array $args Optional scope_type, scope_id, type, search, limit, offset, with_body (default true), and with_body_preview.
     *
     * @return object[]|\WP_Error Records, or a read error.
     */
    public function get_records( array $args = array() ) {
        $where = $this->records_where_template( $args );
        if ( is_wp_error( $where ) ) {
            return $where;
        }
        list( $template, $values ) = $where;
        return $this->get_records_from_template( $template, $values, $args );
    }

    /**
     * List and count with one visibility walk for the current user.
     *
     * @param array $args Optional scope_type, scope_id, type, search, limit, offset, with_body (default true), and with_body_preview.
     *
     * @return array{items: object[], total: int}|\WP_Error Records and total before pagination, or a read error.
     */
    public function get_records_page( array $args = array() ) {
        $where = $this->records_where_template( $args );
        if ( is_wp_error( $where ) ) {
            return $where;
        }
        list( $template, $values ) = $where;

        $items = $this->get_records_from_template( $template, $values, $args );
        if ( is_wp_error( $items ) ) {
            return $items;
        }
        $total = $this->count_records_from_template( $template, $values );
        if ( is_wp_error( $total ) ) {
            return $total;
        }

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    /**
     * Apply projection and pagination to a prepared WHERE template.
     *
     * @param string $template WHERE template from records_where_template().
     * @param array  $values   Ordered WHERE values.
     * @param array  $args     Projection and pagination options.
     *
     * @return object[]|\WP_Error Records, or a read error.
     */
    private function get_records_from_template( string $template, array $values, array $args ) {
        // nosec -- the template contains only fixed strings and counted placeholders; every value is bound below.
        $sql    = 'SELECT ' . $this->records_columns( $args ) . ' FROM %i WHERE ' . $template . ' ORDER BY updated_at DESC, id DESC';
        $values = array_merge( array( $this->table_name( 'knowledge' ) ), $values );

        $limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 0;
        if ( $limit > 0 ) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $values[] = $limit;
            $values[] = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
        }

        $this->wpdb->last_error = '';
        $rows                   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the template contains only fixed strings and counted placeholders; every value is bound.
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }

        return is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array();
    }

    /**
     * List the records of one scope.
     *
     * Authorized by can_read(), the check a single-record read makes, so a
     * caller cannot reach this query without it and the list skips the walk
     * over every site and client in visible_scopes().
     *
     * @param string $scope_type Site or client.
     * @param int    $scope_id   Scope identifier.
     * @param array  $args       Optional type, with_body (default true), and with_body_preview.
     *
     * @return object[]|\WP_Error Records, or the scope's access or read error.
     */
    public function get_scope_records( string $scope_type, int $scope_id, array $args = array() ) {
        $access = $this->can_read(
            (object) array(
                'scope_type' => $scope_type,
                'scope_id'   => $scope_id,
            )
        );
        if ( is_wp_error( $access ) ) {
            return $access;
        }
        // can_read() passes an unrestricted user for an id no site or client has; visible_scopes() never lists one, and rows left under such an id stay unlisted here too.
        $exists = $this->scope_exists( $scope_type, $scope_id );
        if ( true !== $exists ) {
            return is_wp_error( $exists ) ? $exists : array();
        }

        // Decide the filter in PHP, as records_where_template() does; testing the cast value against '' in SQL would list every type for false.
        $has_type               = isset( $args['type'] ) && '' !== $args['type'];
        $this->wpdb->last_error = '';
        $rows                   = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT ' . $this->records_columns( $args ) . ' FROM %i WHERE scope_type = %s AND scope_id = %d AND ( %d = 0 OR `type` = %s ) ORDER BY updated_at DESC, id DESC',
                $this->table_name( 'knowledge' ),
                $scope_type,
                $scope_id,
                (int) $has_type,
                $has_type ? (string) $args['type'] : ''
            )
        );
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }

        return is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array();
    }

    /**
     * Count a rendered set together so widgets and tables do not count per row.
     *
     * @param string $scope_type Site or client.
     * @param int[]  $scope_ids  Scope identifiers on the current page.
     *
     * @return array|\WP_Error Counts keyed by readable scope id, or a read error.
     */
    public function get_scope_counts( string $scope_type, array $scope_ids ) {
        if ( ! in_array( $scope_type, array( 'site', 'client' ), true ) ) {
            return $this->invalid( 'scope_type' );
        }
        $scope_ids = array_unique( array_map( 'intval', array_filter( $scope_ids, 'is_numeric' ) ) );
        $counts    = array();
        foreach ( $scope_ids as $scope_id ) {
            if ( $scope_id < 1 ) {
                continue;
            }
            $access = $this->can_read(
                (object) array(
                    'scope_type' => $scope_type,
                    'scope_id'   => $scope_id,
                )
            );
            if ( is_wp_error( $access ) && 'mainwp_knowledge_db_error' === $access->get_error_code() ) {
                return $access;
            }
            if ( true === $access ) {
                $counts[ $scope_id ] = array(
                    'context'    => 0,
                    'skill'      => 0,
                    'memory'     => 0,
                    'total'      => 0,
                    'unverified' => 0,
                );
            }
        }
        if ( empty( $counts ) ) {
            return $counts;
        }

        $placeholders           = implode( ',', array_fill( 0, count( $counts ), '%d' ) );
        $sql                    = "SELECT scope_id, SUM(type = 'context') AS context, SUM(type = 'skill') AS skill, SUM(type = 'memory') AS memory, COUNT(*) AS total, SUM(verified = 0) AS unverified FROM %i WHERE scope_type = %s AND scope_id IN ({$placeholders}) GROUP BY scope_id";
        $values                 = array_merge( array( $this->table_name( 'knowledge' ), $scope_type ), array_keys( $counts ) );
        $this->wpdb->last_error = '';
        $rows                   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ) );
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }
        foreach ( (array) $rows as $row ) {
            foreach ( array_keys( $counts[ (int) $row->scope_id ] ) as $key ) {
                $counts[ (int) $row->scope_id ][ $key ] = (int) $row->$key;
            }
        }
        return $counts;
    }

    /**
     * Keep both list paths on the same projection.
     *
     * @param array $args Optional with_body and with_body_preview.
     *
     * @return string Fixed column list.
     */
    private function records_columns( array $args ): string {
        if ( ! isset( $args['with_body'] ) || false !== $args['with_body'] ) {
            return '*';
        }

        $preview = ! empty( $args['with_body_preview'] )
            ? ', LEFT(body, ' . ( self::BODY_PREVIEW_LENGTH + 1 ) . ') AS body_preview'
            : '';

        return 'id, scope_type, scope_id, `type`, title, description' . $preview . ', created_by, updated_by, created_at, updated_at, revision, source, caller_note, verified, required';
    }

    /**
     * Count records visible to the current user, with the same filters as get_records().
     *
     * @param array $args Optional scope_type, scope_id, type, search.
     *
     * @return int|\WP_Error Total, or a read error.
     */
    public function count_records( array $args = array() ) {
        $where = $this->records_where_template( $args );
        if ( is_wp_error( $where ) ) {
            return $where;
        }
        list( $template, $values ) = $where;
        return $this->count_records_from_template( $template, $values );
    }

    /**
     * Count matching rows independently of projection and pagination.
     *
     * @param string $template WHERE template from records_where_template().
     * @param array  $values   Ordered WHERE values.
     *
     * @return int|\WP_Error Total, or a read error.
     */
    private function count_records_from_template( string $template, array $values ) {
        $values = array_merge( array( $this->table_name( 'knowledge' ) ), $values );

        $this->wpdb->last_error = '';
        // nosec -- the template contains only fixed strings and counted placeholders; every value is bound.
        $total = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $template, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the template contains only fixed strings and counted placeholders; every value is bound.
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }

        return (int) $total;
    }

    /**
     * Keep listing and counting on the same filters and value order.
     *
     * @param array $args Filters.
     *
     * @return array|\WP_Error SQL template and ordered values, or the scope read error.
     */
    private function records_where_template( array $args ) {
        $scope = $this->scope_where_template();
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        list( $template, $values ) = $scope;
        $where                     = array( $template );

        if ( isset( $args['scope_type'] ) && '' !== $args['scope_type'] ) {
            $where[]  = 'scope_type = %s';
            $values[] = (string) $args['scope_type'];
        }
        if ( isset( $args['scope_id'] ) && '' !== $args['scope_id'] ) {
            $where[]  = 'scope_id = %d';
            $values[] = (int) $args['scope_id'];
        }
        if ( isset( $args['type'] ) && '' !== $args['type'] ) {
            $where[]  = '`type` = %s';
            $values[] = (string) $args['type'];
        }
        if ( array_key_exists( 'verified', $args ) ) {
            $where[]  = 'verified = %d';
            $values[] = (int) $args['verified'];
        }
        if ( isset( $args['search'] ) && is_string( $args['search'] ) && '' !== $args['search'] ) {
            $like     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(title LIKE %s OR description LIKE %s OR body LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        return array( implode( ' AND ', $where ), $values );
    }

    /**
     * Read one record for the current user.
     *
     * @param int $id Record ID.
     *
     * @return object|\WP_Error Record, or WP_Error.
     */
    public function get_record( int $id ): object {
        $row = $this->get_by_id( $id );
        if ( null === $row ) {
            return $this->not_found( $id );
        }
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $access = $this->can_read( $row );
        if ( is_wp_error( $access ) ) {
            return $this->mask_not_found( $access, $id );
        }

        return $row;
    }

    /**
     * Load a record without any permission check.
     *
     * @param int $id Record ID.
     *
     * @return object|\WP_Error|null Record, a read error, or null when no row has this ID.
     */
    public function get_by_id( int $id ): ?object {
        if ( $id <= 0 ) {
            return null;
        }

        $this->wpdb->last_error = '';
        $row                    = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . esc_sql( $this->table_name( 'knowledge' ) ) . ' WHERE id = %d', $id ) );
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }

        return $row ? $this->normalize_row( $row ) : null;
    }

    /**
     * Create or update a record. The only code path that writes the table.
     *
     * @param array    $input             Record fields; `id` present means update.
     * @param string   $origin            self::ORIGIN_UI or self::ORIGIN_ABILITY.
     * @param int|null $expected_revision Stored revision the caller edited; required for updates.
     *
     * @return object|\WP_Error Saved record, or WP_Error.
     */
    public function save( array $input, string $origin, ?int $expected_revision = null ): object {
        if ( self::ORIGIN_UI !== $origin && self::ORIGIN_ABILITY !== $origin ) {
            return $this->invalid( 'origin' );
        }

        $row = null;
        if ( array_key_exists( 'id', $input ) ) {
            $id = filter_var( $input['id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( false === $id || is_bool( $input['id'] ) ) {
                return $this->invalid( 'id' );
            }
            $row = $this->get_by_id( $id );
            if ( null === $row ) {
                return $this->not_found( $id );
            }
            if ( is_wp_error( $row ) ) {
                return $row;
            }

            // Authorize before normalizing so a validation error cannot reveal a hidden record's type or description.
            $access = $this->can_write( $row );
            if ( is_wp_error( $access ) ) {
                return $this->mask_not_found( $access, $id );
            }
        }

        $required_access = $this->check_required_write( $input, $origin, $row );
        if ( is_wp_error( $required_access ) ) {
            return $required_access;
        }

        $data = $this->validate_input( $this->normalize_input( $input, $row ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // Only a person in the UI vouches for content; any ability write, including an edit of a verified row, drops it back to unverified.
        $data['source']     = $origin;
        $data['verified']   = self::ORIGIN_UI === $origin ? 1 : 0;
        $data['updated_by'] = get_current_user_id();
        $data['updated_at'] = time();

        $table = $this->table_name( 'knowledge' );

        if ( ! array_key_exists( 'id', $input ) ) {
            $access = $this->can_write( (object) $data );
            if ( is_wp_error( $access ) ) {
                return $access;
            }

            // Access helpers do not check existence, so an unrestricted caller could otherwise attach a record to an id nobody can ever list.
            $exists = $this->scope_exists( $data['scope_type'], $data['scope_id'] );
            if ( true !== $exists ) {
                return is_wp_error( $exists ) ? $exists : $this->invalid( 'scope_id' );
            }

            $data['created_by'] = $data['updated_by'];
            $data['created_at'] = $data['updated_at'];
            $data['revision']   = 1;

            $inserted = $this->wpdb->insert(
                $table,
                $data,
                array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
            );
            if ( false === $inserted ) {
                return $this->db_error();
            }

            $id    = (int) $this->wpdb->insert_id;
            $saved = $this->get_by_id( $id );
            // The INSERT landed: a failed re-read answered as an error would have the caller retry and create the record twice.
            if ( is_wp_error( $saved ) ) {
                return $this->normalize_row( (object) ( array( 'id' => $id ) + $data ) );
            }
            return $saved ?? $this->not_found( $id );
        }

        if ( $row->scope_type !== $data['scope_type'] || $row->scope_id !== $data['scope_id'] ) {
            return $this->invalid( 'scope_id' );
        }

        if ( null === $expected_revision ) {
            return $this->invalid( 'expected_revision' );
        }
        if ( $row->revision !== $expected_revision ) {
            return $this->conflict( $row->revision );
        }

        // The revision guard lives in the WHERE so two concurrent saves of the same revision cannot both win.
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                // nosec -- only the table identifier from table_name() is concatenated; every value, including the origin pair, is a bound placeholder.
                'UPDATE ' . esc_sql( $table ) . ' SET `type` = %s, title = %s, description = %s, body = %s, caller_note = %s, source = %s, verified = %d, required = %d, updated_by = %d, updated_at = %d, revision = revision + 1 WHERE id = %d AND revision = %d AND ( %s = %s OR required = 0 )',
                $data['type'],
                $data['title'],
                $data['description'],
                $data['body'],
                $data['caller_note'],
                $data['source'],
                $data['verified'],
                $data['required'],
                $data['updated_by'],
                $data['updated_at'],
                $id,
                $expected_revision,
                $origin,
                self::ORIGIN_UI
            )
        );
        if ( false === $updated ) {
            return $this->db_error();
        }
        if ( 0 === (int) $updated ) {
            return $this->stale_write_error( $id, $origin );
        }

        // A concurrent delete can land between the UPDATE and this read.
        $saved = $this->get_by_id( $id );
        // The UPDATE landed: answer with what was written, not an error the caller would retry into a conflict.
        if ( is_wp_error( $saved ) ) {
            return $this->normalize_row( (object) array_merge( (array) $row, $data, array( 'revision' => $expected_revision + 1 ) ) );
        }
        return $saved ?? $this->not_found( $id );
    }

    /**
     * Delete a record the current user can write.
     *
     * @param int      $id                Record ID.
     * @param int|null $expected_revision Revision the caller last saw; null skips the check.
     * @param string   $origin            Trusted UI origin, otherwise an ability write.
     *
     * @return true|\WP_Error True on success, WP_Error otherwise.
     */
    public function delete( int $id, ?int $expected_revision = null, string $origin = self::ORIGIN_ABILITY ) {
        $row = $this->get_by_id( $id );
        if ( null === $row ) {
            return $this->not_found( $id );
        }
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $access = $this->can_write( $row );
        if ( is_wp_error( $access ) ) {
            return $this->mask_not_found( $access, $id );
        }

        $required_access = $this->check_required_write( array(), $origin, $row );
        if ( is_wp_error( $required_access ) ) {
            return $required_access;
        }

        // The revision rides in the WHERE so a delete issued from a stale list
        // cannot remove an edit another writer saved after the list was drawn.
        $where  = array( 'id' => $id );
        $format = array( '%d' );
        if ( null !== $expected_revision ) {
            if ( (int) $row->revision !== $expected_revision ) {
                return $this->conflict( (int) $row->revision );
            }
            $where['revision'] = $expected_revision;
            $format[]          = '%d';
        }

        if ( self::ORIGIN_UI !== $origin ) {
            // This also protects an originless delete if another writer makes the row required after the read.
            $where['required'] = 0;
            $format[]          = '%d';
        }

        $deleted = $this->wpdb->delete( $this->table_name( 'knowledge' ), $where, $format );
        if ( false === $deleted ) {
            return $this->db_error();
        }
        if ( 0 === $deleted ) {
            return $this->stale_write_error( $id, $origin );
        }

        return true;
    }

    /**
     * Explain a guarded write that matched no row: the row is gone, or another writer moved its revision.
     *
     * @param int    $id     Record ID.
     * @param string $origin Trusted write origin.
     *
     * @return \WP_Error Not-found, conflict, or the read error of the re-read.
     */
    private function stale_write_error( int $id, string $origin ): \WP_Error {
        $current = $this->get_by_id( $id );
        if ( null === $current ) {
            return $this->not_found( $id );
        }
        if ( is_wp_error( $current ) ) {
            return $current;
        }
        $required_access = $this->check_required_write( array(), $origin, $current );
        return is_wp_error( $required_access ) ? $required_access : $this->conflict( (int) $current->revision );
    }

    /**
     * Remove every record of a deleted site or client.
     *
     * No permission check: callers are the site and client delete paths,
     * which have already authorised the deletion.
     *
     * @param string $scope_type site|client.
     * @param int    $scope_id   Site or client ID.
     *
     * @return true|\WP_Error True on success, or a database error.
     */
    public function delete_by_scope( string $scope_type, int $scope_id ) {
        $deleted = $this->wpdb->delete(
            $this->table_name( 'knowledge' ),
            array(
                'scope_type' => $scope_type,
                'scope_id'   => $scope_id,
            ),
            array( '%s', '%d' )
        );

        return false === $deleted ? $this->db_error() : true;
    }

    /**
     * Preserve omitted fields while applying the type's description rule to a raw patch.
     *
     * @param array       $input  Raw writable fields.
     * @param object|null $record Authorized existing record, or null for a create.
     * @return array Resulting fields, before validation.
     */
    public function normalize_input( array $input, ?object $record = null ): array {
        $description_supplied = array_key_exists( 'description', $input );
        $fields               = array_merge( (array) $record, array( 'caller_note' => '' ), $input );
        // An omitted description must not carry skill-only text into a type the UI cannot display it for.
        if ( null !== $record && 'skill' === $record->type && isset( $input['type'] ) && 'skill' !== $input['type'] && ! $description_supplied ) {
            $fields['description'] = '';
        }
        if ( 'agency' === ( $fields['scope_type'] ?? '' ) && ! array_key_exists( 'scope_id', $input ) ) {
            unset( $fields['scope_id'] );
        }
        return $fields;
    }

    /**
     * Validate and normalise the writable fields.
     *
     * @param array $input Raw input.
     *
     * @return array|\WP_Error Clean fields in table column order, or WP_Error naming the bad field.
     */
    public function validate_input( array $input ) {
        $scope_type = $input['scope_type'] ?? null;
        if ( ! is_string( $scope_type ) || ! in_array( $scope_type, self::SCOPE_TYPES, true ) ) {
            return $this->invalid( 'scope_type' );
        }

        if ( 'agency' === $scope_type ) {
            if ( array_key_exists( 'scope_id', $input ) ) {
                return $this->invalid( 'scope_id', __( 'Agency records apply to every site, so leave out scope_id.', 'mainwp' ) );
            }
            $scope_id = 0;
        } else {
            if ( ! array_key_exists( 'scope_id', $input ) ) {
                return $this->invalid( 'scope_id', __( 'Site and client records need a scope_id, the site or client the record belongs to.', 'mainwp' ) );
            }
            $scope_id = $input['scope_id'];
            $scope_id = ( is_int( $scope_id ) || is_string( $scope_id ) )
                ? filter_var( $scope_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) )
                : false;
            if ( false === $scope_id ) {
                return $this->invalid( 'scope_id' );
            }
        }

        $type = $input['type'] ?? null;
        if ( ! is_string( $type ) || ! in_array( $type, self::RECORD_TYPES, true ) ) {
            return $this->invalid( 'type' );
        }

        $required = $input['required'] ?? false;
        if ( ! in_array( $required, array( true, false, 0, 1, '0', '1' ), true ) ) {
            return $this->invalid( 'required' );
        }
        $required = (int) $required;
        if ( $required && ( 'agency' !== $scope_type || 'memory' === $type ) ) {
            return $this->invalid( 'required', __( 'Only agency context and agency skills can be required.', 'mainwp' ) );
        }

        // Plain text kept verbatim like the body: sanitize_text_field() stored "<" as "&lt;" and dropped tag-shaped text.
        $title = $input['title'] ?? null;
        // \p{Cc} covers C0, DEL and the C1 range (U+0080-U+009F, e.g. NEXT LINE), which a byte-range class misses.
        if ( ! is_string( $title ) || ! $this->is_valid_utf8( $title ) || preg_match( '/\p{Cc}/u', $title ) ) {
            return $this->invalid( 'title' );
        }
        $title = trim( $title );
        $len   = mb_strlen( $title );
        if ( $len < 1 || $len > self::TITLE_MAX_LENGTH ) {
            return $this->invalid( 'title' );
        }

        $description = array_key_exists( 'description', $input ) ? $input['description'] : '';
        if ( ! is_string( $description ) || ! $this->is_valid_utf8( $description ) ) {
            return $this->invalid( 'description' );
        }
        if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
            return $this->invalid( 'description', __( 'The description can be at most 1024 characters.', 'mainwp' ) );
        }
        if ( 'skill' === $type && '' === trim( $description ) ) {
            return $this->invalid( 'description', __( 'A skill needs a description that says when an AI agent should use it.', 'mainwp' ) );
        }
        if ( 'skill' !== $type && '' !== $description ) {
            return $this->invalid( 'description', __( 'Only skills have a description. Remove it, or change the type to Skill.', 'mainwp' ) );
        }

        // Body is plain text kept byte for byte; escaping belongs to whoever renders it.
        $body = $input['body'] ?? null;
        // Invalid UTF-8 would pass the length check and then fail in wpdb's charset stripping as a 500.
        if ( ! is_string( $body ) || ! $this->is_valid_utf8( $body ) ) {
            return $this->invalid( 'body' );
        }
        $len = mb_strlen( $body );
        if ( $len < 1 || $len > self::BODY_MAX_LENGTH ) {
            return $this->invalid( 'body' );
        }

        $caller_note = $input['caller_note'] ?? '';
        if ( ! is_string( $caller_note ) ) {
            return $this->invalid( 'caller_note' );
        }
        $caller_note = sanitize_text_field( $caller_note );
        if ( mb_strlen( $caller_note ) > self::CALLER_NOTE_MAX_LENGTH ) {
            return $this->invalid( 'caller_note' );
        }

        return array(
            'scope_type'  => $scope_type,
            'scope_id'    => (int) $scope_id,
            'type'        => $type,
            'title'       => $title,
            'description' => $description,
            'body'        => $body,
            'caller_note' => $caller_note,
            'required'    => $required,
        );
    }

    /**
     * Required rules need a person's review; previews must enforce the same restriction as writes.
     *
     * @param array       $input  Raw input, before stored fields are merged.
     * @param string      $origin Trusted write origin.
     * @param object|null $record Stored row, when updating or deleting.
     * @return true|\WP_Error Whether this origin may touch Required.
     */
    public function check_required_write( array $input, string $origin, ?object $record = null ) {
        if ( self::ORIGIN_UI !== $origin && array_key_exists( 'required', $input ) ) {
            return $this->invalid( 'required', __( 'Leave out required. Only a person using the Dashboard can mark a record as required.', 'mainwp' ) );
        }
        if ( ! empty( $record->required ) && self::ORIGIN_UI !== $origin ) {
            return $this->invalid( 'required', __( 'This is a required agency record. Only a person using the Dashboard can change or delete it.', 'mainwp' ) );
        }
        $required_changed = array_key_exists( 'required', $input ) && (int) ( $record->required ?? 0 ) !== (int) $input['required'];
        if ( $required_changed || ! empty( $record->required ) ) {
            return MainWP_Abilities_Util::check_unrestricted_access();
        }
        return true;
    }

    /**
     * Servers without mbstring must still reject malformed text before storage.
     *
     * @param string $value Text to validate.
     * @return bool Whether the bytes form valid UTF-8.
     */
    private function is_valid_utf8( string $value ): bool {
        return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $value, 'UTF-8' ) : 1 === preg_match( '//u', $value );
    }

    /**
     * Whether the site or client a new record points at exists.
     *
     * @param string $scope_type site|client.
     * @param int    $scope_id   Site or client ID.
     *
     * @return bool|\WP_Error Whether it exists, or a read error.
     */
    private function scope_exists( string $scope_type, int $scope_id ) {
        if ( 'agency' === $scope_type ) {
            return 0 === $scope_id;
        }
        if ( 'site' === $scope_type ) {
            $sql = 'SELECT id FROM ' . esc_sql( $this->table_name( 'wp' ) ) . ' WHERE id = %d';
        } elseif ( 'client' === $scope_type ) {
            $sql = 'SELECT client_id FROM ' . esc_sql( $this->table_name( 'wp_clients' ) ) . ' WHERE client_id = %d';
        } else {
            return false;
        }

        $this->wpdb->last_error = '';
        $found                  = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $scope_id ) );
        if ( '' !== $this->wpdb->last_error ) {
            return $this->db_read_error();
        }

        return null !== $found;
    }

    /**
     * Cast numeric columns so callers can compare strictly.
     *
     * @param object $row Raw row.
     *
     * @return object Row.
     */
    private function normalize_row( object $row ): object {
        $row->description = $row->description ?? '';
        $row->required    = $row->required ?? 0;
        foreach ( array( 'id', 'scope_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'revision', 'verified', 'required' ) as $field ) {
            if ( isset( $row->$field ) ) {
                $row->$field = (int) $row->$field;
            }
        }
        return $row;
    }

    /**
     * Restricted callers get 404 from the access helpers; answer with the
     * same error a missing id gets so the two cannot be told apart.
     *
     * @param \WP_Error $error Access error.
     * @param int       $id    Record ID the caller asked for.
     *
     * @return \WP_Error
     */
    private function mask_not_found( \WP_Error $error, int $id ): \WP_Error {
        $data = $error->get_error_data();
        if ( is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'] ) {
            return $this->not_found( $id );
        }
        return $error;
    }

    /**
     * Not-found error for a record ID.
     *
     * @param int $id Record ID.
     *
     * @return \WP_Error
     */
    private function not_found( int $id ): \WP_Error {
        return new \WP_Error(
            'mainwp_knowledge_not_found',
            /* translators: %d: knowledge record ID */
            sprintf( __( 'No knowledge record found with ID %d.', 'mainwp' ), $id ),
            array( 'status' => 404 )
        );
    }

    /**
     * Validation error naming the offending field.
     *
     * @param string $field   Field name.
     * @param string $message Optional explanation of the type rule.
     *
     * @return \WP_Error
     */
    private function invalid( string $field, string $message = '' ): \WP_Error {
        return new \WP_Error(
            'mainwp_knowledge_invalid',
            /* translators: %s: field name */
            '' !== $message ? $message : sprintf( __( 'Invalid knowledge record field: %s.', 'mainwp' ), $field ),
            array( 'status' => 400 )
        );
    }

    /**
     * Stale-revision error carrying the stored revision so the caller can reload.
     *
     * @param int $revision Stored revision.
     *
     * @return \WP_Error
     */
    private function conflict( int $revision ): \WP_Error {
        return new \WP_Error(
            'mainwp_knowledge_conflict',
            __( 'This knowledge record was changed by someone else. Reload it and try again.', 'mainwp' ),
            array(
                'status'   => 409,
                'revision' => $revision,
            )
        );
    }

    /**
     * Database failure error.
     *
     * @return \WP_Error
     */
    private function db_error(): \WP_Error {
        return new \WP_Error(
            'mainwp_knowledge_db_error',
            __( 'The knowledge record could not be written to the database.', 'mainwp' ),
            array( 'status' => 500 )
        );
    }

    /**
     * Failed scope lookups must not pass for missing sites or clients.
     *
     * @param callable $read Database lookup.
     * @return mixed|\WP_Error Lookup result, or a database read error.
     */
    public function guard_read( callable $read ) {
        $this->wpdb->last_error = '';
        $result                 = $read();
        return '' !== $this->wpdb->last_error ? $this->db_read_error() : $result;
    }

    /**
     * Database read failure. Same code as a write failure: a failed read must never pass for an empty result.
     *
     * Readers clear last_error first because wpdb resets it only when a query runs; after a prepare()
     * failure an earlier, unrelated error would still be sitting there.
     *
     * @return \WP_Error
     */
    private function db_read_error(): \WP_Error {
        return new \WP_Error(
            'mainwp_knowledge_db_error',
            __( 'Knowledge could not be read from the database. Try again.', 'mainwp' ),
            array( 'status' => 500 )
        );
    }
}
