<?php
/**
 * Remote MCP installer.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Creates the five OAuth tables and the approval, job and client tables. The uninstall routine
 * lives in the module's uninstall.php, which must stay loadable on PHP 7.4.
 */
class Remote_MCP_Install {

    /**
     * Module schema version.
     *
     * @var string
     */
    const DB_VERSION = '3';

    /**
     * OAuth table short names, `{prefix}mainwp_oauth_*`. uninstall.php repeats this list.
     *
     * @var array
     */
    const TABLES = array( 'requests', 'codes', 'grants', 'generations', 'tokens' );

    /**
     * Approval, job and client table short names, `{prefix}mainwp_remote_mcp_*`. uninstall.php
     * repeats this list.
     *
     * @var array
     */
    const MODULE_TABLES = array( 'approvals', 'jobs', 'clients' );

    /**
     * Columns versions 2 and 3 add to existing tables, checked after an install.
     *
     * @var array
     */
    const MIGRATED_COLUMNS = array(
        'requests'  => array( 'approved_scope', 'approval_policy' ),
        'codes'     => array( 'approval_policy' ),
        'grants'    => array( 'approval_policy', 'policy_version', 'client_kind', 'label', 'last_ip', 'last_failure', 'last_failure_at' ),
        'approvals' => array( 'gate_values' ),
    );

    /**
     * Columns a later db version removed, table => columns. dbDelta never drops a column.
     *
     * @var array
     */
    const DROPPED_COLUMNS = array(
        // Version 3: the call plan is the only execution source, so no separate payload is kept.
        'approvals' => array( 'exec_payload' ),
    );

    /**
     * Whether the module's db-version marker is present, including an older schema version.
     *
     * @return bool
     */
    public static function is_installed(): bool {
        return false !== get_option( Remote_MCP_Settings::OPTION_DB_VERSION, false );
    }

    /**
     * Every table short name.
     *
     * @return array
     */
    public static function all_tables(): array {
        return array_merge( self::TABLES, self::MODULE_TABLES );
    }

    /**
     * Full table name.
     *
     * @param string $name Short name from TABLES or MODULE_TABLES.
     * @return string
     */
    public static function table( string $name ): string {
        global $wpdb;
        $prefix = in_array( $name, self::MODULE_TABLES, true ) ? 'mainwp_remote_mcp_' : 'mainwp_oauth_';
        return $wpdb->prefix . $prefix . $name;
    }

    /**
     * CREATE TABLE statements in dbDelta format: one definition per line, two spaces after PRIMARY KEY.
     *
     * The client_id indexes use a 191-character prefix, the WordPress convention that keeps a
     * utf8mb4 key inside the 767-byte limit of older InnoDB row formats.
     *
     * @return array
     */
    public static function schema(): array {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();

        $requests  = self::table( 'requests' );
        $codes     = self::table( 'codes' );
        $grants    = self::table( 'grants' );
        $gens      = self::table( 'generations' );
        $tokens    = self::table( 'tokens' );
        $approvals = self::table( 'approvals' );
        $jobs      = self::table( 'jobs' );
        $clients   = self::table( 'clients' );

        return array(
            "CREATE TABLE {$requests} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
request_key char(43) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(512) NOT NULL,
client_host varchar(255) NOT NULL,
redirect_uri text NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(512) NOT NULL,
code_challenge char(43) NOT NULL,
state text NULL,
status varchar(16) NOT NULL,
decided_at int(10) unsigned NULL,
user_generation int(10) unsigned NOT NULL DEFAULT 0,
site_generation int(10) unsigned NOT NULL DEFAULT 0,
created_at int(10) unsigned NOT NULL,
expires_at int(10) unsigned NOT NULL,
approved_scope varchar(255) NULL,
approval_policy varchar(16) NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_key (request_key)
) {$collate};",
            "CREATE TABLE {$codes} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
code_hash char(64) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(512) NOT NULL,
redirect_uri text NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(512) NOT NULL,
code_challenge char(43) NOT NULL,
request_id bigint(20) unsigned NOT NULL,
grant_id bigint(20) unsigned NULL,
status varchar(16) NOT NULL,
user_generation int(10) unsigned NOT NULL DEFAULT 0,
site_generation int(10) unsigned NOT NULL DEFAULT 0,
created_at int(10) unsigned NOT NULL,
expires_at int(10) unsigned NOT NULL,
redeemed_at int(10) unsigned NULL,
approval_policy varchar(16) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY code_hash (code_hash),
UNIQUE KEY request_id (request_id)
) {$collate};",
            "CREATE TABLE {$grants} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(512) NOT NULL,
client_host varchar(255) NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(512) NOT NULL,
status varchar(16) NOT NULL,
version int(10) unsigned NOT NULL DEFAULT 0,
pair_seq int(10) unsigned NOT NULL DEFAULT 0,
current_pair int(10) unsigned NOT NULL DEFAULT 0,
current_used tinyint(3) unsigned NOT NULL DEFAULT 0,
sibling_pair int(10) unsigned NULL,
previous_pair int(10) unsigned NULL,
previous_rotated_at int(10) unsigned NULL,
grace_used tinyint(3) unsigned NOT NULL DEFAULT 0,
revoke_reason varchar(32) NULL,
user_generation int(10) unsigned NOT NULL DEFAULT 0,
site_generation int(10) unsigned NOT NULL DEFAULT 0,
created_at int(10) unsigned NOT NULL,
session_expires_at int(10) unsigned NOT NULL,
last_used_at int(10) unsigned NOT NULL DEFAULT 0,
revoked_at int(10) unsigned NULL,
approval_policy varchar(16) NOT NULL,
policy_version int(10) unsigned NOT NULL DEFAULT 1,
client_kind varchar(16) NOT NULL DEFAULT 'bundled',
label varchar(64) NULL,
last_ip varchar(45) NULL,
last_failure varchar(64) NULL,
last_failure_at int(10) unsigned NULL,
PRIMARY KEY  (id),
KEY user_client_status (user_id,client_id(191),status),
KEY status_session (status,session_expires_at)
) {$collate};",
            "CREATE TABLE {$gens} (
subject_id bigint(20) unsigned NOT NULL,
generation int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (subject_id)
) {$collate};",
            "CREATE TABLE {$tokens} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
token_hash char(64) NOT NULL,
token_type varchar(8) NOT NULL,
grant_id bigint(20) unsigned NOT NULL,
pair int(10) unsigned NOT NULL,
scope varchar(255) NOT NULL,
resource varchar(512) NOT NULL,
secret_version smallint(5) unsigned NOT NULL,
created_at int(10) unsigned NOT NULL,
expires_at int(10) unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY grant_type_pair (grant_id,token_type,pair)
) {$collate};",
            "CREATE TABLE {$approvals} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
handle_hash char(64) NOT NULL,
grant_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id varchar(512) NOT NULL,
ability varchar(191) NOT NULL,
tier varchar(16) NOT NULL,
policy_version int(10) unsigned NOT NULL,
args_digest char(64) NOT NULL,
target_ids text NULL,
plan_digest char(64) NULL,
native_preview_token text NULL,
summary text NULL,
result_summary text NULL,
status varchar(16) NOT NULL,
decided_by bigint(20) unsigned NULL,
decided_via varchar(8) NULL,
call_plan mediumtext NULL,
user_generation int(10) unsigned NOT NULL DEFAULT 0,
site_generation int(10) unsigned NOT NULL DEFAULT 0,
created_at int(10) unsigned NOT NULL,
expires_at int(10) unsigned NOT NULL,
decided_at int(10) unsigned NULL,
consumed_at int(10) unsigned NULL,
gate_values text NULL,
PRIMARY KEY  (id),
UNIQUE KEY handle_hash (handle_hash),
KEY grant_status (grant_id,status),
KEY retry_lookup (grant_id,ability,args_digest),
KEY status_expires (status,expires_at)
) {$collate};",
            "CREATE TABLE {$jobs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
approval_id bigint(20) unsigned NULL,
grant_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
policy_version int(10) unsigned NOT NULL,
call_plan mediumtext NOT NULL,
next_entry int(10) unsigned NOT NULL DEFAULT 0,
status varchar(16) NOT NULL,
last_error varchar(64) NULL,
created_at int(10) unsigned NOT NULL,
updated_at int(10) unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY approval_id (approval_id),
KEY grant_status (grant_id,status),
KEY status_updated (status,updated_at)
) {$collate};",
            "CREATE TABLE {$clients} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
client_id varchar(64) NOT NULL,
kind varchar(16) NOT NULL,
client_name varchar(64) NULL,
redirect_uris text NOT NULL,
secret_hash char(64) NULL,
created_by bigint(20) unsigned NULL,
created_ip varchar(45) NULL,
created_at int(10) unsigned NOT NULL,
last_used_at int(10) unsigned NULL,
first_consented_at int(10) unsigned NULL,
status varchar(16) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY client_id (client_id),
KEY kind_consented (kind,first_consented_at,created_at)
) {$collate};",
        );
    }

    /**
     * Create or update the tables, create the site generation row and record the module db version.
     * Safe to run repeatedly. The version is recorded only once every table and the site row exist,
     * so a failed install is retried rather than taken as done.
     *
     * Upgrading from an earlier db version is the same run: dbDelta appends the new columns, which
     * the schema lists last in each table, and the dropped columns are removed, so a migrated table
     * matches a fresh one column for column.
     *
     * @return bool Whether the install is complete.
     */
    public static function install(): bool {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( self::schema() );

        // INSERT IGNORE: a reinstall must never reset a bumped site generation, which could revive old
        // rows. Its result is checked below by reading the row back.
        $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (subject_id, generation) VALUES (0, 0)', self::table( 'generations' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- module install.

        // Rows from db version 1 predate the policy choice, and ALTER TABLE filled the new NOT NULL
        // column with ''. An unknown policy must never read as a looser one, so they become Strict.
        // Those connections were granted read only, so nothing they could do changes.
        // The version only advances once the backfill is confirmed, below.
        $backfilled = true;
        foreach ( array( 'codes', 'grants' ) as $name ) {
            if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET approval_policy = 'strict' WHERE approval_policy = ''", self::table( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- module schema migration.
                $backfilled = false;
            }
        }

        $suppress = $wpdb->suppress_errors( true );
        foreach ( self::DROPPED_COLUMNS as $name => $columns ) {
            foreach ( $columns as $column ) {
                if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table( $name ), $column ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- module schema migration.
                    $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', self::table( $name ), $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- module schema migration; the result is read back below.
                }
            }
        }
        $wpdb->suppress_errors( $suppress );

        // dbDelta() reports nothing usable on failure, so read the result back. SELECT ... LIMIT 0 also
        // sees the test harness's temporary tables, which SHOW TABLES doesn't list. A missing table is
        // the failure being detected, so its error isn't printed.
        $suppress = $wpdb->suppress_errors( true );
        $complete = true;
        foreach ( self::all_tables() as $name ) {
            if ( false === $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', self::table( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- install verification.
                $complete = false;
                break;
            }
        }
        // A migration whose ALTER failed leaves the table in place without the new columns.
        foreach ( self::MIGRATED_COLUMNS as $name => $columns ) {
            if ( $complete && false === $wpdb->query( $wpdb->prepare( 'SELECT ' . implode( ', ', $columns ) . ' FROM %i LIMIT 0', self::table( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- the column list is a class constant.
                $complete = false;
            }
        }
        foreach ( self::DROPPED_COLUMNS as $name => $columns ) {
            foreach ( $columns as $column ) {
                if ( $complete && null !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table( $name ), $column ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- install verification.
                    $complete = false;
                }
            }
        }
        // A backfill can report success and still leave rows behind, so count them.
        foreach ( array( 'codes', 'grants' ) as $name ) {
            if ( $complete && '0' !== $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE approval_policy = ''", self::table( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- install verification.
                $complete = false;
            }
        }
        if ( $complete ) {
            $complete = $backfilled && '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE subject_id = 0', self::table( 'generations' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- install verification.
        }
        $wpdb->suppress_errors( $suppress );
        if ( ! $complete ) {
            return false;
        }

        update_option( Remote_MCP_Settings::OPTION_DB_VERSION, self::DB_VERSION, false );
        return self::DB_VERSION === get_option( Remote_MCP_Settings::OPTION_DB_VERSION );
    }
}
