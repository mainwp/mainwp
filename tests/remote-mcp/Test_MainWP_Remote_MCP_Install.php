<?php
/**
 * Remote MCP installer and uninstall tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Install
 *
 * These tests keep the harness's temporary-table rewrite: nothing here joins a table to itself,
 * so the tables vanish with the connection and DDL never commits the test transaction.
 */
class Test_MainWP_Remote_MCP_Install extends \WP_UnitTestCase {

	/**
	 * Expected columns per table: name => [ type without integer display width, nullable ].
	 *
	 * @var array
	 */
	private const COLUMNS = array(
		'requests'    => array(
			'id'              => array( 'bigint unsigned', false ),
			'request_key'     => array( 'char(43)', false ),
			'user_id'         => array( 'bigint unsigned', false ),
			'client_id'       => array( 'varchar(512)', false ),
			'client_host'     => array( 'varchar(255)', false ),
			'redirect_uri'    => array( 'text', false ),
			'scope'           => array( 'varchar(255)', false ),
			'resource'        => array( 'varchar(512)', false ),
			'code_challenge'  => array( 'char(43)', false ),
			'state'           => array( 'text', true ),
			'status'          => array( 'varchar(16)', false ),
			'decided_at'      => array( 'int unsigned', true ),
			'user_generation' => array( 'int unsigned', false ),
			'site_generation' => array( 'int unsigned', false ),
			'created_at'      => array( 'int unsigned', false ),
			'expires_at'      => array( 'int unsigned', false ),
			'approved_scope'  => array( 'varchar(255)', true ),
			'approval_policy' => array( 'varchar(16)', true ),
		),
		'codes'       => array(
			'id'              => array( 'bigint unsigned', false ),
			'code_hash'       => array( 'char(64)', false ),
			'user_id'         => array( 'bigint unsigned', false ),
			'client_id'       => array( 'varchar(512)', false ),
			'redirect_uri'    => array( 'text', false ),
			'scope'           => array( 'varchar(255)', false ),
			'resource'        => array( 'varchar(512)', false ),
			'code_challenge'  => array( 'char(43)', false ),
			'request_id'      => array( 'bigint unsigned', false ),
			'grant_id'        => array( 'bigint unsigned', true ),
			'status'          => array( 'varchar(16)', false ),
			'user_generation' => array( 'int unsigned', false ),
			'site_generation' => array( 'int unsigned', false ),
			'created_at'      => array( 'int unsigned', false ),
			'expires_at'      => array( 'int unsigned', false ),
			'redeemed_at'     => array( 'int unsigned', true ),
			'approval_policy' => array( 'varchar(16)', false ),
		),
		'grants'      => array(
			'id'                  => array( 'bigint unsigned', false ),
			'user_id'             => array( 'bigint unsigned', false ),
			'client_id'           => array( 'varchar(512)', false ),
			'client_host'         => array( 'varchar(255)', false ),
			'scope'               => array( 'varchar(255)', false ),
			'resource'            => array( 'varchar(512)', false ),
			'status'              => array( 'varchar(16)', false ),
			'version'             => array( 'int unsigned', false ),
			'pair_seq'            => array( 'int unsigned', false ),
			'current_pair'        => array( 'int unsigned', false ),
			'current_used'        => array( 'tinyint unsigned', false ),
			'sibling_pair'        => array( 'int unsigned', true ),
			'previous_pair'       => array( 'int unsigned', true ),
			'previous_rotated_at' => array( 'int unsigned', true ),
			'grace_used'          => array( 'tinyint unsigned', false ),
			'revoke_reason'       => array( 'varchar(32)', true ),
			'user_generation'     => array( 'int unsigned', false ),
			'site_generation'     => array( 'int unsigned', false ),
			'created_at'          => array( 'int unsigned', false ),
			'session_expires_at'  => array( 'int unsigned', false ),
			'last_used_at'        => array( 'int unsigned', false ),
			'revoked_at'          => array( 'int unsigned', true ),
			'approval_policy'     => array( 'varchar(16)', false ),
			'policy_version'      => array( 'int unsigned', false ),
			'client_kind'         => array( 'varchar(16)', false ),
			'label'               => array( 'varchar(64)', true ),
			'last_ip'             => array( 'varchar(45)', true ),
			'last_failure'        => array( 'varchar(64)', true ),
			'last_failure_at'     => array( 'int unsigned', true ),
		),
		'generations' => array(
			'subject_id' => array( 'bigint unsigned', false ),
			'generation' => array( 'int unsigned', false ),
		),
		'tokens'      => array(
			'id'             => array( 'bigint unsigned', false ),
			'token_hash'     => array( 'char(64)', false ),
			'token_type'     => array( 'varchar(8)', false ),
			'grant_id'       => array( 'bigint unsigned', false ),
			'pair'           => array( 'int unsigned', false ),
			'scope'          => array( 'varchar(255)', false ),
			'resource'       => array( 'varchar(512)', false ),
			'secret_version' => array( 'smallint unsigned', false ),
			'created_at'     => array( 'int unsigned', false ),
			'expires_at'     => array( 'int unsigned', false ),
		),
	);

	/**
	 * Expected indexes per table: key name => [ unique, columns ].
	 *
	 * @var array
	 */
	private const INDEXES = array(
		'requests'    => array(
			'PRIMARY'     => array( true, array( 'id' ) ),
			'request_key' => array( true, array( 'request_key' ) ),
		),
		'codes'       => array(
			'PRIMARY'    => array( true, array( 'id' ) ),
			'code_hash'  => array( true, array( 'code_hash' ) ),
			'request_id' => array( true, array( 'request_id' ) ),
		),
		'grants'      => array(
			'PRIMARY'            => array( true, array( 'id' ) ),
			'user_client_status' => array( false, array( 'user_id', 'client_id', 'status' ) ),
			'status_session'     => array( false, array( 'status', 'session_expires_at' ) ),
		),
		'generations' => array(
			'PRIMARY' => array( true, array( 'subject_id' ) ),
		),
		'tokens'      => array(
			'PRIMARY'         => array( true, array( 'id' ) ),
			'token_hash'      => array( true, array( 'token_hash' ) ),
			'grant_type_pair' => array( false, array( 'grant_id', 'token_type', 'pair' ) ),
		),
	);

	/**
	 * Start every test without module state.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! class_exists( Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		require_once MAINWP_PLUGIN_DIR . 'modules/remote-mcp/uninstall.php';
		$this->drop_tables();
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
	}

	/**
	 * Drop this test's temporary tables while the harness rewrite is still attached, so they don't
	 * outlive the test on this connection and shadow the store tests' real tables.
	 */
	public function tear_down() {
		if ( class_exists( Remote_MCP_Install::class ) ) {
			$this->drop_tables();
		}
		parent::tear_down();
	}

	/**
	 * Drop module tables (rewritten to DROP TEMPORARY TABLE by the harness).
	 */
	private function drop_tables(): void {
		global $wpdb;
		foreach ( array_merge( Remote_MCP_Install::TABLES, array( 'approvals', 'jobs', 'clients' ) ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . Remote_MCP_Install::table( $name ) . '`' );
		}
	}

	/**
	 * Whether a table (temporary or not) exists on this connection.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_results( "DESCRIBE `{$table}`" );
		$wpdb->suppress_errors( $suppress );
		return ! empty( $columns );
	}

	/**
	 * Column map of a table: name => [ type, nullable ].
	 *
	 * @param string $table Full table name.
	 * @return array
	 */
	private function columns( string $table ): array {
		global $wpdb;
		$columns = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $column ) {
			$type                        = preg_replace( '/^(tinyint|smallint|int|bigint)\(\d+\)/', '$1', strtolower( $column['Type'] ) );
			$columns[ $column['Field'] ] = array( $type, 'YES' === $column['Null'] );
		}
		return $columns;
	}

	/**
	 * Index map of a table: key name => [ unique, columns ].
	 *
	 * @param string $table Full table name.
	 * @return array
	 */
	private function indexes( string $table ): array {
		global $wpdb;
		$indexes = array();
		foreach ( $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) as $row ) {
			$name                          = $row['Key_name'];
			$indexes[ $name ][0]           = '0' === (string) $row['Non_unique'];
			$indexes[ $name ][1][ (int) $row['Seq_in_index'] - 1 ] = $row['Column_name'];
		}
		foreach ( $indexes as $name => $index ) {
			ksort( $indexes[ $name ][1] );
			$indexes[ $name ][1] = array_values( $indexes[ $name ][1] );
		}
		return $indexes;
	}

	/**
	 * Columns of the tables the read and write release adds.
	 *
	 * @var array
	 */
	private const NEW_COLUMNS = array(
		'approvals' => array(
			'id'                   => array( 'bigint unsigned', false ),
			'handle_hash'          => array( 'char(64)', false ),
			'grant_id'             => array( 'bigint unsigned', false ),
			'user_id'              => array( 'bigint unsigned', false ),
			'client_id'            => array( 'varchar(512)', false ),
			'ability'              => array( 'varchar(191)', false ),
			'tier'                 => array( 'varchar(16)', false ),
			'policy_version'       => array( 'int unsigned', false ),
			'args_digest'          => array( 'char(64)', false ),
			'target_ids'           => array( 'text', true ),
			'plan_digest'          => array( 'char(64)', true ),
			'native_preview_token' => array( 'text', true ),
			'summary'              => array( 'text', true ),
			'result_summary'       => array( 'text', true ),
			'status'               => array( 'varchar(16)', false ),
			'decided_by'           => array( 'bigint unsigned', true ),
			'decided_via'          => array( 'varchar(8)', true ),
			'call_plan'            => array( 'mediumtext', true ),
			'user_generation'      => array( 'int unsigned', false ),
			'site_generation'      => array( 'int unsigned', false ),
			'created_at'           => array( 'int unsigned', false ),
			'expires_at'           => array( 'int unsigned', false ),
			'decided_at'           => array( 'int unsigned', true ),
			'consumed_at'          => array( 'int unsigned', true ),
			'gate_values'          => array( 'text', true ),
		),
		'jobs'      => array(
			'id'             => array( 'bigint unsigned', false ),
			'approval_id'    => array( 'bigint unsigned', true ),
			'grant_id'       => array( 'bigint unsigned', false ),
			'user_id'        => array( 'bigint unsigned', false ),
			'policy_version' => array( 'int unsigned', false ),
			'call_plan'      => array( 'mediumtext', false ),
			'next_entry'     => array( 'int unsigned', false ),
			'status'         => array( 'varchar(16)', false ),
			'last_error'     => array( 'varchar(64)', true ),
			'created_at'     => array( 'int unsigned', false ),
			'updated_at'     => array( 'int unsigned', false ),
		),
		'clients'   => array(
			'id'                 => array( 'bigint unsigned', false ),
			'client_id'          => array( 'varchar(64)', false ),
			'kind'               => array( 'varchar(16)', false ),
			'client_name'        => array( 'varchar(64)', true ),
			'redirect_uris'      => array( 'text', false ),
			'secret_hash'        => array( 'char(64)', true ),
			'created_by'         => array( 'bigint unsigned', true ),
			'created_ip'         => array( 'varchar(45)', true ),
			'created_at'         => array( 'int unsigned', false ),
			'last_used_at'       => array( 'int unsigned', true ),
			'first_consented_at' => array( 'int unsigned', true ),
			'status'             => array( 'varchar(16)', false ),
		),
	);

	/**
	 * The module schema at db version 1.
	 *
	 * @return array CREATE TABLE statements.
	 */
	private function v1_schema(): array {
		global $wpdb;
		$p       = $wpdb->prefix . 'mainwp_oauth_';
		$collate = $wpdb->get_charset_collate();
		return array(
			"CREATE TABLE {$p}requests (
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
PRIMARY KEY  (id),
UNIQUE KEY request_key (request_key)
) {$collate};",
			"CREATE TABLE {$p}codes (
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
PRIMARY KEY  (id),
UNIQUE KEY code_hash (code_hash),
UNIQUE KEY request_id (request_id)
) {$collate};",
			"CREATE TABLE {$p}grants (
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
PRIMARY KEY  (id),
KEY user_client_status (user_id,client_id(191),status),
KEY status_session (status,session_expires_at)
) {$collate};",
			"CREATE TABLE {$p}generations (
subject_id bigint(20) unsigned NOT NULL,
generation int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (subject_id)
) {$collate};",
			"CREATE TABLE {$p}tokens (
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
		);
	}

	/**
	 * Install the db version 1 schema with one live connection's rows, and record that version.
	 *
	 * @return array Ids of the request, code and grant rows.
	 */
	private function install_v1_with_rows(): array {
		global $wpdb;
		foreach ( $this->v1_schema() as $statement ) {
			$wpdb->query( $statement );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (subject_id, generation) VALUES (0, 0)', Remote_MCP_Install::table( 'generations' ) ) );
		$wpdb->insert(
			Remote_MCP_Install::table( 'requests' ),
			array(
				'request_key'    => str_repeat( 'k', 43 ),
				'user_id'        => 1,
				'client_id'      => 'https://claude.ai/oauth/claude-code-client-metadata',
				'client_host'    => 'claude.ai',
				'redirect_uri'   => 'http://localhost/callback',
				'scope'          => 'mainwp:read offline_access',
				'resource'       => 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp',
				'code_challenge' => str_repeat( 'c', 43 ),
				'status'         => 'approved',
				'created_at'     => 1800000000,
				'expires_at'     => 1800000600,
			)
		);
		$request_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Remote_MCP_Install::table( 'grants' ),
			array(
				'user_id'            => 1,
				'client_id'          => 'https://claude.ai/oauth/claude-code-client-metadata',
				'client_host'        => 'claude.ai',
				'scope'              => 'mainwp:read offline_access',
				'resource'           => 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp',
				'status'             => 'live',
				'version'            => 1,
				'pair_seq'           => 1,
				'current_pair'       => 1,
				'created_at'         => 1800000000,
				'session_expires_at' => 1802592000,
			)
		);
		$grant_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Remote_MCP_Install::table( 'codes' ),
			array(
				'code_hash'      => str_repeat( 'h', 64 ),
				'user_id'        => 1,
				'client_id'      => 'https://claude.ai/oauth/claude-code-client-metadata',
				'redirect_uri'   => 'http://localhost/callback',
				'scope'          => 'mainwp:read offline_access',
				'resource'       => 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp',
				'code_challenge' => str_repeat( 'c', 43 ),
				'request_id'     => $request_id,
				'grant_id'       => $grant_id,
				'status'         => 'redeemed',
				'created_at'     => 1800000000,
				'expires_at'     => 1800000120,
			)
		);
		$code_id = (int) $wpdb->insert_id;
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, '1', false );
		return array( $request_id, $code_id, $grant_id );
	}

	/**
	 * Default of one column, as SHOW COLUMNS reports it.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 * @return string|null
	 */
	private function column_default( string $table, string $column ): ?string {
		global $wpdb;
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $row ) {
			if ( $column === $row['Field'] ) {
				return $row['Default'];
			}
		}
		return null;
	}

	public function test_migration_adds_scope_and_policy_columns_and_tables() {
		global $wpdb;
		list( $request_id, $code_id, $grant_id ) = $this->install_v1_with_rows();

		$this->assertTrue( Remote_MCP_Install::install() );
		$this->assertSame( '3', Remote_MCP_Install::DB_VERSION );
		$this->assertSame( '3', get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );

		// The migrated tables equal a fresh install, column for column and index for index.
		foreach ( array( 'requests', 'codes', 'grants' ) as $name ) {
			$this->assertSame( self::COLUMNS[ $name ], $this->columns( Remote_MCP_Install::table( $name ) ), $name . ' columns after migration' );
		}
		$this->assertSame( '1', $this->column_default( Remote_MCP_Install::table( 'grants' ), 'policy_version' ) );
		foreach ( array( 'approvals', 'jobs' ) as $name ) {
			$table = Remote_MCP_Install::table( $name );
			$this->assertSame( $wpdb->prefix . 'mainwp_remote_mcp_' . $name, $table );
			$this->assertSame( self::NEW_COLUMNS[ $name ], $this->columns( $table ), $name . ' columns' );
		}
		$approval_indexes = $this->indexes( Remote_MCP_Install::table( 'approvals' ) );
		$this->assertSame( array( true, array( 'handle_hash' ) ), $approval_indexes['handle_hash'] );
		$this->assertSame( array( true, array( 'approval_id' ) ), $this->indexes( Remote_MCP_Install::table( 'jobs' ) )['approval_id'], 'one job per approval' );

		// Existing rows keep their data and get the strictest policy: they predate any choice.
		$grant = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Remote_MCP_Install::table( 'grants' ), $grant_id ), ARRAY_A );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertSame( 'mainwp:read offline_access', $grant['scope'] );
		$this->assertSame( 'strict', $grant['approval_policy'] );
		$this->assertSame( '1', $grant['policy_version'] );
		$code = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Remote_MCP_Install::table( 'codes' ), $code_id ), ARRAY_A );
		$this->assertSame( 'strict', $code['approval_policy'] );
		$this->assertSame( 'mainwp:read offline_access', $code['scope'] );
		$request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Remote_MCP_Install::table( 'requests' ), $request_id ), ARRAY_A );
		$this->assertNull( $request['approved_scope'] );
		$this->assertNull( $request['approval_policy'] );

		// Running it again changes nothing.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->assertSame( array(), dbDelta( Remote_MCP_Install::schema(), false ) );
		$wpdb->last_error = '';
		$this->assertTrue( Remote_MCP_Install::install() );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_migration_adds_clients_table_and_grant_kind_and_label() {
		global $wpdb;
		list( , , $grant_id ) = $this->install_v1_with_rows();

		$this->assertTrue( Remote_MCP_Install::install() );

		$grants = Remote_MCP_Install::table( 'grants' );
		$this->assertSame( array( 'varchar(16)', false ), $this->columns( $grants )['client_kind'] );
		$this->assertSame( 'bundled', $this->column_default( $grants, 'client_kind' ) );
		$this->assertSame( array( 'varchar(64)', true ), $this->columns( $grants )['label'] );
		$grant = $wpdb->get_row( $wpdb->prepare( 'SELECT client_kind, label FROM %i WHERE id = %d', $grants, $grant_id ), ARRAY_A );
		$this->assertSame( 'bundled', $grant['client_kind'], 'db version 1 connections all came from bundled clients' );
		$this->assertNull( $grant['label'] );

		$clients = Remote_MCP_Install::table( 'clients' );
		$this->assertSame( $wpdb->prefix . 'mainwp_remote_mcp_clients', $clients );
		$this->assertSame( self::NEW_COLUMNS['clients'], $this->columns( $clients ) );
		$this->assertSame( array( true, array( 'client_id' ) ), $this->indexes( $clients )['client_id'] );

		// The token type column takes the new connect type unchanged.
		$tokens = Remote_MCP_Install::table( 'tokens' );
		$this->assertSame( array( 'varchar(8)', false ), $this->columns( $tokens )['token_type'] );
		$wpdb->insert(
			$tokens,
			array(
				'token_hash'     => str_repeat( 't', 64 ),
				'token_type'     => 'connect',
				'grant_id'       => $grant_id,
				'pair'           => 1,
				'scope'          => 'mainwp:read',
				'resource'       => 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp',
				'secret_version' => 1,
				'created_at'     => 1800000000,
				'expires_at'     => 1800086400,
			)
		);
		$this->assertSame( 'connect', $wpdb->get_var( $wpdb->prepare( 'SELECT token_type FROM %i WHERE grant_id = %d', $tokens, $grant_id ) ) );
	}

	public function test_migration_backfill_failure_records_no_db_version() {
		global $wpdb;
		$this->install_v1_with_rows();

		$suppress = $wpdb->suppress_errors( true );
		$filter   = $this->fail_query_containing( "SET approval_policy = 'strict'" );
		try {
			$installed = Remote_MCP_Install::install();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertFalse( $installed, 'a failed Strict backfill is a failed install' );
		$this->assertSame( '1', get_option( Remote_MCP_Settings::OPTION_DB_VERSION ), 'the version stays where it was, so the next run retries' );

		// A backfill that reports success but changes nothing is caught by reading the rows back.
		$swallow = function ( $query ) {
			return false !== strpos( $query, "SET approval_policy = 'strict'" ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $swallow );
		try {
			$this->assertFalse( Remote_MCP_Install::install() );
		} finally {
			remove_filter( 'query', $swallow );
		}
		$this->assertSame( '1', get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );

		$this->assertTrue( Remote_MCP_Install::install() );
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE approval_policy = ''", Remote_MCP_Install::table( 'grants' ) ) ) );
	}

	public function test_migration_v3_adds_gate_values_and_drops_exec_payload() {
		global $wpdb;
		list( , , $grant_id ) = $this->install_v1_with_rows();
		// The approvals table as db version 2 created it, with one row.
		$approvals = Remote_MCP_Install::table( 'approvals' );
		$wpdb->query(
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
exec_payload mediumtext NULL,
user_generation int(10) unsigned NOT NULL DEFAULT 0,
site_generation int(10) unsigned NOT NULL DEFAULT 0,
created_at int(10) unsigned NOT NULL,
expires_at int(10) unsigned NOT NULL,
decided_at int(10) unsigned NULL,
consumed_at int(10) unsigned NULL,
PRIMARY KEY  (id),
UNIQUE KEY handle_hash (handle_hash),
KEY grant_status (grant_id,status),
KEY retry_lookup (grant_id,ability,args_digest),
KEY status_expires (status,expires_at)
) " . $wpdb->get_charset_collate()
		);
		$wpdb->insert(
			$approvals,
			array(
				'handle_hash'    => str_repeat( 'a', 64 ),
				'grant_id'       => $grant_id,
				'user_id'        => 1,
				'client_id'      => 'https://claude.ai/oauth/claude-code-client-metadata',
				'ability'        => 'mainwp/suspend-site-v1',
				'tier'           => 'dashboard',
				'policy_version' => 1,
				'args_digest'    => str_repeat( 'b', 64 ),
				'status'         => 'pending',
				'exec_payload'   => 'v1:sealed',
				'created_at'     => 1800000000,
				'expires_at'     => 1800001800,
			)
		);
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, '2', false );

		$this->assertTrue( Remote_MCP_Install::install() );

		$this->assertSame( '3', get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
		$columns = $this->columns( $approvals );
		$this->assertArrayNotHasKey( 'exec_payload', $columns, 'the run-from-page payload column is gone' );
		$this->assertSame( array( 'text', true ), $columns['gate_values'] );
		$this->assertSame( self::NEW_COLUMNS['approvals'], $columns, 'migrated equals fresh' );
		$row = $wpdb->get_row( "SELECT * FROM `{$approvals}`", ARRAY_A );
		$this->assertSame( 'pending', $row['status'], 'existing rows survive' );
		$this->assertNull( $row['gate_values'] );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->assertSame( array(), dbDelta( Remote_MCP_Install::schema(), false ) );
		$this->assertTrue( Remote_MCP_Install::install(), 'idempotent' );
	}

	public function test_uninstall_drops_approval_job_and_client_tables() {
		$uninstall = '\MainWP\Dashboard\Remote_MCP\uninstall';
		Remote_MCP_Install::install();
		update_option( Remote_MCP_Settings::OPTION_COMPETING_RULES, array( 'openid' => true ) );
		update_option( 'mainwp_remote_mcp_counters', array( 'unknown_client' => array( 'count' => 1, 'since' => 1 ) ), false );
		foreach ( array( 'approvals', 'jobs', 'clients' ) as $name ) {
			$this->assertTrue( $this->table_exists( Remote_MCP_Install::table( $name ) ), $name . ' installed' );
		}

		$uninstall();

		foreach ( Remote_MCP_Install::all_tables() as $name ) {
			$this->assertFalse( $this->table_exists( Remote_MCP_Install::table( $name ) ), $name . ' dropped' );
		}
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_COMPETING_RULES ) );
		$this->assertFalse( get_option( 'mainwp_remote_mcp_counters' ), 'the 24-hour counters go too' );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
	}

	public function test_install_creates_five_tables_with_expected_columns() {
		global $wpdb;

		Remote_MCP_Install::install();

		$this->assertSame( array( 'requests', 'codes', 'grants', 'generations', 'tokens' ), Remote_MCP_Install::TABLES );
		foreach ( self::COLUMNS as $name => $expected ) {
			$table = $wpdb->prefix . 'mainwp_oauth_' . $name;
			$this->assertSame( $table, Remote_MCP_Install::table( $name ) );
			$this->assertTrue( $this->table_exists( $table ), $name . ' exists' );
			$this->assertSame( $expected, $this->columns( $table ), $name . ' columns' );
			$indexes = $this->indexes( $table );
			ksort( $indexes );
			$expected_indexes = self::INDEXES[ $name ];
			ksort( $expected_indexes );
			$this->assertSame( $expected_indexes, $indexes, $name . ' indexes' );
		}

		// The site generation row exists from the start.
		$site = $wpdb->get_results( 'SELECT subject_id, generation FROM `' . Remote_MCP_Install::table( 'generations' ) . '`', ARRAY_A );
		$this->assertSame(
			array(
				array(
					'subject_id' => '0',
					'generation' => '0',
				),
			),
			$site
		);
	}

	public function test_install_is_idempotent_and_records_own_db_version() {
		global $wpdb;
		$core_version = get_site_option( 'mainwp_db_version' );
		$generations  = Remote_MCP_Install::table( 'generations' );

		Remote_MCP_Install::install();
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
		$this->assertSame( $core_version, get_site_option( 'mainwp_db_version' ), 'core db version untouched' );

		// A bumped site generation must survive a reinstall: resetting it to 0 could revive old rows.
		$wpdb->query( "UPDATE `{$generations}` SET generation = 4 WHERE subject_id = 0" );

		// dbDelta in dry-run mode reports any statement it would still run against the installed schema.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->assertSame( array(), dbDelta( Remote_MCP_Install::schema(), false ) );

		$wpdb->last_error = '';
		Remote_MCP_Install::install();
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
		$this->assertSame( '4', $wpdb->get_var( "SELECT generation FROM `{$generations}` WHERE subject_id = 0" ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$generations}`" ) );
		$this->assertSame( $core_version, get_site_option( 'mainwp_db_version' ) );
	}

	public function test_installed_store_available_on_http_home_when_tables_exist() {
		global $wpdb;
		$services = new Remote_MCP_Services( array(), new Remote_MCP_Urls( 'http://dash.example.org' ) );
		$this->assertNull( $services->installed_store(), 'no installed store before tables exist' );
		$this->assertNull( $services->store(), 'HTTP cannot serve MCP before installation' );

		$this->assertTrue( Remote_MCP_Install::install() );
		$store = $services->installed_store();
		$this->assertInstanceOf( OAuth_Store::class, $store, 'HTTP still permits installed-store invalidation' );
		$this->assertSame( $store, $services->installed_store() );
		$this->assertSame( 0, $store->bump_site_generation( 'home_changed' ), 'invalidation completes with no grants' );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT generation FROM `' . Remote_MCP_Install::table( 'generations' ) . '` WHERE subject_id = 0' ), 'invalidation persists over HTTP' );
		$this->assertNull( $services->store(), 'installed-store lookup must not enable HTTP serving' );

		$secure = new Remote_MCP_Services( array(), new Remote_MCP_Urls( 'https://dash.example.org' ) );
		$this->assertInstanceOf( OAuth_Store::class, $secure->installed_store() );
		$this->assertInstanceOf( OAuth_Store::class, $secure->store(), 'HTTPS serving keeps its existing store' );

		\MainWP\Dashboard\Remote_MCP\uninstall();
		$this->assertNull( $services->installed_store(), 'a cached store is unavailable after uninstall' );
		$this->assertNull( $secure->installed_store() );
	}

	public function test_is_installed_tracks_db_version_marker() {
		$this->assertFalse( Remote_MCP_Install::is_installed(), 'no marker before first enable' );
		$this->assertTrue( Remote_MCP_Install::install() );
		$this->assertTrue( Remote_MCP_Install::is_installed() );
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, '1', false );
		$this->assertTrue( Remote_MCP_Install::is_installed(), 'an older installed schema still counts' );
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, '', false );
		$this->assertTrue( Remote_MCP_Install::is_installed(), 'presence is independent of marker value' );
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		$this->assertFalse( Remote_MCP_Install::is_installed() );
	}

	public function test_uninstall_drops_tables_secret_and_options() {
		$core_version = get_site_option( 'mainwp_db_version' );
		$uninstall    = '\MainWP\Dashboard\Remote_MCP\uninstall';

		Remote_MCP_Install::install();
		update_option( Remote_MCP_Settings::OPTION_SECRET, array( 'secret' ), false );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, 1 );
		update_option( Remote_MCP_Settings::OPTION_BOUND_HOME, 'https://dash.example.org' );
		$options = array( 'mainwp_remote_mcp_home_changed', 'mainwp_remote_mcp_notice_state', 'mainwp_remote_mcp_job_tick_1', 'mainwp_remote_mcp_job_tick_42', 'mainwp_remote_mcp_job_tick_retry' );
		$unrelated = array( 'mainwp_remote_mcp_job_tick', 'mainwp_remote_mcp_job_tickish_1', 'mainwpXremoteXmcpXjobXtickX1', 'other_job_tick_1' );
		foreach ( array_merge( $options, $unrelated ) as $option ) {
			update_option( $option, 'kept until uninstall', false );
		}
		wp_schedule_event( time() + 3600, 'daily', 'mainwp_remote_mcp_purge' );
		wp_schedule_single_event( time() + 60, 'mainwp_remote_mcp_job_tick', array( 42 ) );

		// Without the module's own db version option the uninstall touches nothing.
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		$uninstall();
		$this->assertTrue( $this->table_exists( Remote_MCP_Install::table( 'grants' ) ), 'tables kept without db version' );
		$this->assertSame( array( 'secret' ), get_option( Remote_MCP_Settings::OPTION_SECRET ) );
		foreach ( $options as $option ) {
			$this->assertSame( 'kept until uninstall', get_option( $option ), $option . ' kept without marker' );
		}

		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, Remote_MCP_Install::DB_VERSION );
		$uninstall();

		foreach ( Remote_MCP_Install::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( Remote_MCP_Install::table( $name ) ), $name . ' dropped' );
		}
		foreach ( array( Remote_MCP_Settings::OPTION_SECRET, Remote_MCP_Settings::OPTION_ENABLED, Remote_MCP_Settings::OPTION_BOUND_HOME, Remote_MCP_Settings::OPTION_DB_VERSION ) as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' deleted' );
		}
		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' deleted' );
		}
		foreach ( $unrelated as $option ) {
			$this->assertSame( 'kept until uninstall', get_option( $option ), $option . ' untouched' );
		}
		$this->assertFalse( wp_next_scheduled( 'mainwp_remote_mcp_purge' ) );
		$this->assertFalse( wp_next_scheduled( 'mainwp_remote_mcp_job_tick', array( 42 ) ), 'per-job ticks are unscheduled too' );
		$this->assertSame( $core_version, get_site_option( 'mainwp_db_version' ), 'core db version untouched' );
	}

	/**
	 * Replace every statement containing $needle with one that fails, until the returned callback is removed.
	 *
	 * @param string $needle Substring identifying the targeted statement.
	 * @return callable The filter, for remove_filter().
	 */
	private function fail_query_containing( string $needle ): callable {
		$filter = function ( $query ) use ( $needle ) {
			return false === strpos( $query, $needle ) ? $query : 'SELECT 1 FROM `remote_mcp_injected_failure`';
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	public function test_install_failure_records_no_db_version() {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );

		// One table whose CREATE fails.
		$filter = $this->fail_query_containing( Remote_MCP_Install::table( 'tokens' ) . ' (' );
		try {
			$installed = Remote_MCP_Install::install();
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertFalse( $installed );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );

		// Every table, but no site generation row.
		$this->drop_tables();
		$filter = $this->fail_query_containing( 'INSERT IGNORE INTO `' . Remote_MCP_Install::table( 'generations' ) . '`' );
		try {
			$installed = Remote_MCP_Install::install();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertFalse( $installed );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );

		$this->assertTrue( Remote_MCP_Install::install() );
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
	}

	public function test_uninstall_keeps_db_version_when_a_drop_fails() {
		global $wpdb;
		$uninstall = '\MainWP\Dashboard\Remote_MCP\uninstall';
		Remote_MCP_Install::install();
		update_option( Remote_MCP_Settings::OPTION_SECRET, array( 'secret' ), false );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, 1 );

		$suppress = $wpdb->suppress_errors( true );
		$filter   = $this->fail_query_containing( 'IF EXISTS `' . Remote_MCP_Install::table( 'grants' ) . '`' );
		try {
			$uninstall();
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ), 'kept so the next uninstall retries' );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_SECRET ), 'the secret always goes' );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_ENABLED ) );
		$this->assertTrue( $this->table_exists( Remote_MCP_Install::table( 'grants' ) ) );
		$this->assertFalse( $this->table_exists( Remote_MCP_Install::table( 'tokens' ) ) );

		$uninstall();
		$this->assertFalse( $this->table_exists( Remote_MCP_Install::table( 'grants' ) ) );
		$this->assertFalse( get_option( Remote_MCP_Settings::OPTION_DB_VERSION ) );
	}

	public function test_uninstall_keeps_parent_tables_while_a_child_table_survives() {
		global $wpdb;
		$uninstall = '\MainWP\Dashboard\Remote_MCP\uninstall';
		$parents   = array( 'grants', 'requests', 'generations' );

		foreach ( array( 'tokens', 'codes' ) as $child ) {
			Remote_MCP_Install::install();
			$suppress = $wpdb->suppress_errors( true );
			$filter   = $this->fail_query_containing( 'IF EXISTS `' . Remote_MCP_Install::table( $child ) . '`' );
			try {
				$uninstall();
			} finally {
				remove_filter( 'query', $filter );
				$wpdb->suppress_errors( $suppress );
			}

			// A reinstall would restart grant ids and generations under the surviving rows, so a
			// leftover token or code could attach to another user's new grant.
			$this->assertTrue( $this->table_exists( Remote_MCP_Install::table( $child ) ), $child . ' survived' );
			foreach ( $parents as $parent ) {
				$this->assertTrue( $this->table_exists( Remote_MCP_Install::table( $parent ) ), $parent . ' is kept while ' . $child . ' survives' );
			}

			$uninstall();
			foreach ( Remote_MCP_Install::TABLES as $name ) {
				$this->assertFalse( $this->table_exists( Remote_MCP_Install::table( $name ) ), $name . ' dropped on retry' );
			}
		}
	}

	public function test_uninstall_deletes_all_module_transients() {
		global $wpdb;
		Remote_MCP_Install::install();
		$keys = array( 'mainwp_remote_mcp_reg_abc', 'mainwp_remote_mcp_reg_limited', 'mainwp_rmcp_rl_abc', 'mainwp_remote_mcp_kc_abc', 'mainwp_remote_mcp_probe_abc', 'mainwp_remote_mcp_reg_orphan_timeout', 'mainwp_rmcp_rl_orphan_value' );
		foreach ( $keys as $key ) {
			set_transient( $key, 1, 3600 );
		}
		delete_option( '_transient_mainwp_remote_mcp_reg_orphan_timeout' );
		delete_option( '_transient_timeout_mainwp_rmcp_rl_orphan_value' );
		set_transient( 'unrelated_transient', 1, 3600 );
		\MainWP\Dashboard\Remote_MCP\uninstall();
		foreach ( $keys as $key ) {
			$this->assertFalse( get_transient( $key ), $key );
			foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $prefix . $key ) ), $prefix . $key );
			}
		}
		$this->assertSame( 1, get_transient( 'unrelated_transient' ) );
	}

}
