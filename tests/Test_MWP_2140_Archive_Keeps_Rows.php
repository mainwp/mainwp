<?php
/**
 * Network Activity Dismiss must keep rows that were not copied to the archive (MWP-2140).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The captured exit belongs to this regression harness.
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Fixtures exercise production nonce verification.
// phpcs:disable WordPress.WP.AlternativeFunctions -- The source copy lets the legacy die() be captured.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and fixed fixture data.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Connection-local temporary tables isolate the real SQL.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\Module\Log\Log_DB_Archive;
use MainWP\Dashboard\Module\Log\Log_DB_Helper;
use MainWP\Dashboard\Module\Log\Log_Install;
use MainWP\Dashboard\Module\Log\Log_Manage_Insights_Events_Page;
use MainWP\Dashboard\Module\Log\Log_Manager;

/** Thrown by the die handler so the AJAX response can be read. */
class MWP_2140_Response_Stop extends \Error {}

/**
 * Class Test_MWP_2140_Archive_Keeps_Rows
 */
class Test_MWP_2140_Archive_Keeps_Rows extends \WP_UnitTestCase {

    const LOG_ID = 7001;

    const OTHER_LOG_ID = 7002;

    /**
     * Test table prefix.
     *
     * @var string
     */
    private $prefix = '';

    /**
     * Original singleton table prefixes.
     *
     * @var array
     */
    private $db_prefixes = array();

    /**
     * Original wpdb log table aliases.
     *
     * @var array
     */
    private $log_tables = array();

    /**
     * Original log settings, restored in tearDown.
     *
     * @var array|null
     */
    private $settings_options = null;

    /**
     * Error log file for this test, and the previous error_log setting.
     *
     * @var array
     */
    private $error_log = array();

    /**
     * Raw body of the last AJAX response.
     *
     * @var string
     */
    private $last_body = '';

    /**
     * Filters added by this test.
     *
     * @var array
     */
    private $filters = array();

    /** Load a copy of the insights page whose die() calls can be captured. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ( class_exists( '\MainWP\Dashboard\Module\Log\MWP_2140_Insights_Page', false ) ) {
            return;
        }
        $code = '';
        foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/modules/logs/pages/page-log-insights-page.php' ) ) as $token ) {
            if ( is_array( $token ) ) {
                if ( T_EXIT === $token[0] ) {
                    $code .= '\\wp_die';
                } elseif ( T_STRING === $token[0] && 'Log_Insights_Page' === $token[1] ) {
                    $code .= 'MWP_2140_Insights_Page';
                } else {
                    $code .= $token[1];
                }
            } else {
                $code .= $token;
            }
        }
        eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Runs the real migration handler with its die() captured.
    }

    /** Route the log classes to temporary tables. */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        $this->error_log = array( (string) tempnam( sys_get_temp_dir(), 'mwp2140' ), ini_get( 'error_log' ) );
        ini_set( 'error_log', $this->error_log[0] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Captures the archive error log for assertions.

        $this->prefix = $wpdb->prefix . 'mwp2140_';
        $this->temporary_table_like( 'wp_logs' );
        $this->temporary_table_like( 'wp_logs_meta' );

        foreach ( array( Log_DB_Archive::class, Log_DB_Helper::class, Log_Install::class ) as $class ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $this->db_prefixes[ $class ] = $property->getValue( $class::instance() );
            $property->setValue( $class::instance(), $this->prefix );
        }
        foreach ( array(
            'mainwp_tbl_logs'      => $this->table( 'wp_logs' ),
            'mainwp_tbl_logs_meta' => $this->table( 'wp_logs_meta' ),
        ) as $name => $table ) {
            $this->log_tables[ $name ] = $wpdb->$name;
            $wpdb->$name               = $table;
        }
    }

    /** Restore table routing, filters and request state. */
    public function tearDown(): void {
        global $wpdb;
        foreach ( $this->filters as $hook => $callbacks ) {
            foreach ( $callbacks as $callback ) {
                remove_filter( $hook, $callback, 999 );
            }
        }
        $this->filters = array();
        foreach ( array( 'wp_logs', 'wp_logs_meta', 'wp_logs_archive', 'wp_logs_meta_archive' ) as $suffix ) {
            $wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $this->table( $suffix ) );
        }
        foreach ( $this->db_prefixes as $class => $prefix ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $property->setValue( $class::instance(), $prefix );
        }
        foreach ( $this->log_tables as $name => $table ) {
            $wpdb->$name = $table;
        }
        if ( null !== $this->settings_options ) {
            Log_Manager::instance()->settings->options = $this->settings_options;
        }
        ini_set( 'error_log', (string) $this->error_log[1] ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the setting changed in setUp.
        wp_delete_file( $this->error_log[0] );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        parent::tearDown();
    }

    /**
     * A Dismiss on an install whose upgrade skipped the archive tables creates them, then archives.
     */
    public function test_dismiss_creates_missing_archive_tables_then_archives() {
        $this->seed_logs();
        $this->assertFalse( $this->table_exists( 'wp_logs_archive' ), 'Precondition: archive table missing.' );

        $response = $this->dismiss( self::LOG_ID );

        $this->assertArchived( self::LOG_ID );
        $this->assertKept( self::OTHER_LOG_ID );
        $this->assertSame( array( 'success' => 'yes' ), $response );
        $this->assertSame( '{"success":"yes"}', $this->last_body, 'Database errors leaked into the AJAX response.' );
    }

    /**
     * When the archive tables are missing and cannot be created, Dismiss and Dismiss All keep the rows.
     */
    public function test_missing_archive_tables_keep_rows_until_repaired() {
        $this->seed_logs();
        $block = static function ( $sql ) {
            return 0 === strpos( ltrim( $sql ), 'CREATE' ) && false !== strpos( $sql, '_archive' ) ? 'SELECT * FROM mwp2140_no_such_table' : $sql;
        };
        $this->filter( 'query', $block );

        $single = $this->dismiss( self::LOG_ID );
        $all    = $this->dismiss_all();

        $this->assertKept( self::LOG_ID );
        $this->assertKept( self::OTHER_LOG_ID );
        $this->assertArrayHasKey( 'error', $single );
        $this->assertStringContainsString( 'so it was kept', $single['error'] );
        $this->assertArrayHasKey( 'error', $all );
        $this->assertStringContainsString( 'so they were kept', $all['error'] );

        remove_filter( 'query', $block, 999 );

        $this->assertSame( array( 'success' => 'yes' ), $this->dismiss( self::LOG_ID ) );
        $this->assertArchived( self::LOG_ID );
        $this->assertSame( array( 'success' => 'yes' ), $this->dismiss_all() );
        $this->assertArchived( self::OTHER_LOG_ID );
    }

    /**
     * The install step creates the archive tables for a log DB older than 1.0.1.8.
     */
    public function test_create_archive_tables_creates_missing_tables_for_old_log_db() {
        Log_Install::instance()->create_archive_tables( '1.0.1.7' );

        $this->assertTrue( $this->table_exists( 'wp_logs_archive' ) );
        $this->assertTrue( $this->table_exists( 'wp_logs_meta_archive' ) );
        $this->assertContains( 'archived_at', $this->columns( 'wp_logs_archive' ) );
    }

    /**
     * An archive table left without archived_at (failed upgrade ALTER) gets the column back on Dismiss.
     */
    public function test_dismiss_adds_missing_archived_at_column() {
        $this->temporary_table_like( 'wp_logs_archive', 'wp_logs' );
        $this->temporary_table_like( 'wp_logs_meta_archive', 'wp_logs_meta' );
        $this->seed_logs();
        $this->assertNotContains( 'archived_at', $this->columns( 'wp_logs_archive' ), 'Precondition: archived_at missing.' );

        $response = $this->dismiss( self::LOG_ID );

        $this->assertArchived( self::LOG_ID );
        $this->assertSame( array( 'success' => 'yes' ), $response );
        $this->assertContains( 'archived_at', $this->columns( 'wp_logs_archive' ) );
    }

    /**
     * Archive columns in another order, or fewer of them, still receive the right values.
     */
    public function test_reordered_archive_columns_receive_the_right_values() {
        global $wpdb;
        // Shape of an upgraded install: columns appended in upgrade order, duration and dismiss missing.
        $this->assertNotFalse(
            $wpdb->query(
                'CREATE TEMPORARY TABLE ' . $this->table( 'wp_logs_archive' ) . " (
                log_id bigint(20) NOT NULL,
                site_id bigint(20) unsigned NULL,
                item varchar(256) NOT NULL DEFAULT '',
                user_id int(11) unsigned NOT NULL DEFAULT '0',
                action varchar(100) NOT NULL,
                context varchar(100) NOT NULL,
                connector varchar(100) NOT NULL,
                state tinyint(1) unsigned NULL,
                created BIGINT(20) UNSIGNED NOT NULL,
                archived_at int(11) NOT NULL DEFAULT 0,
                user_login varchar(100) NOT NULL,
                log_type_id bigint NOT NULL DEFAULT 0,
                PRIMARY KEY (log_id)
                )"
            ),
            $wpdb->last_error
        );
        $this->assertNotFalse(
            $wpdb->query(
                'CREATE TEMPORARY TABLE ' . $this->table( 'wp_logs_meta_archive' ) . ' (
                meta_log_id bigint(20) unsigned NOT NULL,
                meta_value mediumtext NOT NULL,
                meta_key varchar(200) NOT NULL,
                meta_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY (meta_id)
                )'
            ),
            $wpdb->last_error
        );
        $this->seed_logs();
        $before = time();

        $single = $this->dismiss( self::LOG_ID );
        $all    = $this->dismiss_all();

        foreach ( array( self::LOG_ID, self::OTHER_LOG_ID ) as $log_id ) {
            $this->assertArchived( $log_id );
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 'wp_logs_archive' ) . ' WHERE log_id = %d', $log_id ), ARRAY_A );
            $this->assertSame( 'login-' . $log_id, $row['user_login'] );
            $this->assertSame( '3', $row['log_type_id'] );
            $this->assertSame( 'item-' . $log_id, $row['item'] );
            $this->assertSame( '1780000000000000', $row['created'] );
            $this->assertGreaterThanOrEqual( $before, (int) $row['archived_at'] );
            $meta = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table( 'wp_logs_meta_archive' ) . ' WHERE meta_log_id = %d', $log_id ), ARRAY_A );
            $this->assertSame( 'meta_key_' . $log_id, $meta['meta_key'] );
            $this->assertSame( 'meta value ' . $log_id, $meta['meta_value'] );
        }
        $this->assertSame( array( 'success' => 'yes' ), $single );
        $this->assertSame( array( 'success' => 'yes' ), $all );
    }

    /**
     * Fresh schema: archive then delete, as before.
     */
    public function test_fresh_schema_archives_and_deletes() {
        Log_Install::instance()->create_archive_tables();
        $this->seed_logs();

        $this->assertSame( array( 'success' => 'yes' ), $this->dismiss( self::LOG_ID ) );
        $this->assertArchived( self::LOG_ID );
        $this->assertKept( self::OTHER_LOG_ID );

        $this->assertSame( array( 'success' => 'yes' ), $this->dismiss_all() );
        $this->assertArchived( self::OTHER_LOG_ID );
    }

    /**
     * Failed copies keep rows and meta, and neither the cron nor the migration records a finished run.
     *
     * @dataProvider failing_copies
     *
     * @param string $suffix Archive table whose INSERT fails.
     */
    public function test_failed_copy_keeps_rows_and_is_not_marked_done( string $suffix ) {
        global $wpdb;
        Log_Install::instance()->create_archive_tables();
        $this->seed_logs();
        $wpdb->update( $this->table( 'wp_logs' ), array( 'dismiss' => 1 ), array( 'log_id' => self::OTHER_LOG_ID ) );
        $target = $this->table( $suffix );
        $this->filter(
            'query',
            static function ( $sql ) use ( $target ) {
                return 0 === strpos( ltrim( $sql ), 'INSERT IGNORE INTO `' . $target . '`' ) ? str_replace( '`' . $target . '`', '`mwp2140_no_such_table`', $sql ) : $sql;
            }
        );
        $this->expire_logs_for_cron();
        update_option( 'mainwp_module_log_last_time_auto_archive_logs', 111 );
        update_option( 'mainwp_module_logs_updates_dismissed_db_process_status', 'require_update' );
        delete_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' );

        $single = $this->dismiss( self::LOG_ID );
        $all    = $this->dismiss_all();
        Log_Manager::instance()->cron_module_log_auto_archive();
        $migration = $this->migrate_dismissed();

        $this->assertKept( self::LOG_ID );
        $this->assertKept( self::OTHER_LOG_ID );
        $this->assertSame( 111, (int) get_option( 'mainwp_module_log_last_time_auto_archive_logs' ) );
        $this->assertSame( 'require_update', get_option( 'mainwp_module_logs_updates_dismissed_db_process_status' ) );
        $this->assertArrayHasKey( 'error', $single );
        $this->assertArrayHasKey( 'error', $all );
        $this->assertArrayHasKey( 'error', $migration );
        $this->assertArrayNotHasKey( 'status', $migration );
        $logged = (string) file_get_contents( $this->error_log[0] );
        $this->assertStringContainsString( 'Rows were kept.', $logged );
        $this->assertStringContainsString( "mwp2140_no_such_table' doesn't exist", $logged );
    }

    /**
     * Archive tables whose INSERT is made to fail.
     *
     * @return array
     */
    public static function failing_copies(): array {
        return array(
            'log copy fails'  => array( 'wp_logs_archive' ),
            'meta copy fails' => array( 'wp_logs_meta_archive' ),
        );
    }

    /**
     * A new log that reuses an archived id (AUTO_INCREMENT reset on restart) is kept; an identical retry is archived.
     */
    public function test_reused_log_id_is_kept_and_identical_retry_is_archived() {
        global $wpdb;
        Log_Install::instance()->create_archive_tables();
        $this->seed_logs();
        $this->assertNotFalse(
            $wpdb->insert(
                $this->table( 'wp_logs_archive' ),
                array(
                    'log_id'      => self::LOG_ID,
                    'user_login'  => 'older-event',
                    'action'      => 'deleted',
                    'context'     => 'plugins',
                    'connector'   => 'plugins',
                    'created'     => '1700000000000000',
                    'archived_at' => 1,
                )
            ),
            $wpdb->last_error
        );
        $this->assertNotFalse(
            $wpdb->insert(
                $this->table( 'wp_logs_meta_archive' ),
                array(
                    'meta_id'     => 1,
                    'meta_log_id' => self::LOG_ID,
                    'meta_key'    => 'older_key',
                    'meta_value'  => 'older value',
                )
            ),
            $wpdb->last_error
        );

        $reused = $this->dismiss( self::LOG_ID );

        $this->assertKept( self::LOG_ID );
        $this->assertSame( 'older-event', $wpdb->get_var( $wpdb->prepare( 'SELECT user_login FROM ' . $this->table( 'wp_logs_archive' ) . ' WHERE log_id = %d', self::LOG_ID ) ) );
        $this->assertSame( array( 'older_key' ), $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM ' . $this->table( 'wp_logs_meta_archive' ) . ' WHERE meta_log_id = %d', self::LOG_ID ) ), 'The new log\'s meta was added to the older archived log.' );
        $this->assertArrayHasKey( 'error', $reused );

        // First attempt copies the log but not its meta, so the row is kept with an identical archive copy.
        $meta_archive = $this->table( 'wp_logs_meta_archive' );
        $block        = static function ( $sql ) use ( $meta_archive ) {
            return 0 === strpos( ltrim( $sql ), 'INSERT IGNORE INTO `' . $meta_archive . '`' ) ? str_replace( '`' . $meta_archive . '`', '`mwp2140_no_such_table`', $sql ) : $sql;
        };
        $this->filter( 'query', $block );
        $this->assertArrayHasKey( 'error', $this->dismiss( self::OTHER_LOG_ID ) );
        $this->assertKept( self::OTHER_LOG_ID );
        remove_filter( 'query', $block, 999 );

        $this->assertSame( array( 'success' => 'yes' ), $this->dismiss( self::OTHER_LOG_ID ) );
        $this->assertArchived( self::OTHER_LOG_ID );
    }

    /**
     * Dismiss All runs in bounded batches, so a large log table does not become one huge query.
     */
    public function test_dismiss_all_archives_in_bounded_batches() {
        global $wpdb;
        Log_Install::instance()->create_archive_tables();
        $values = array();
        for ( $log_id = 1; $log_id <= 1002; $log_id++ ) {
            $values[] = "($log_id, 'login', 'updated', 'plugins', 'plugins', 1780000000000000)";
        }
        $this->assertNotFalse(
            $wpdb->query( 'INSERT INTO ' . $this->table( 'wp_logs' ) . ' (log_id, user_login, action, context, connector, created) VALUES ' . implode( ',', $values ) ),
            $wpdb->last_error
        );
        $selects = array();
        $logs    = $this->table( 'wp_logs' );
        $this->filter(
            'query',
            static function ( $sql ) use ( &$selects, $logs ) {
                if ( 0 === strpos( ltrim( $sql ), 'SELECT log_id' ) && false !== strpos( $sql, $logs ) && false !== strpos( $sql, 'WHERE 1' ) ) {
                    $selects[] = $sql;
                }
                return $sql;
            }
        );

        $response = $this->dismiss_all();

        $this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table( 'wp_logs' ) ) );
        $this->assertSame( 1002, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table( 'wp_logs_archive' ) ) );
        $this->assertSame( array( 'success' => 'yes' ), $response );
        $this->assertNotEmpty( $selects );
        foreach ( $selects as $sql ) {
            $this->assertMatchesRegularExpression( '/LIMIT 1000\s*$/', $sql );
        }
    }

    /**
     * Without failures the cron and migration still record their runs.
     */
    public function test_cron_and_migration_record_successful_runs() {
        global $wpdb;
        Log_Install::instance()->create_archive_tables();
        $this->seed_logs();
        $wpdb->update( $this->table( 'wp_logs' ), array( 'dismiss' => 1 ), array( 'log_id' => self::OTHER_LOG_ID ) );
        $this->expire_logs_for_cron();
        update_option( 'mainwp_module_log_last_time_auto_archive_logs', 111 );
        delete_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' );

        $migration = $this->migrate_dismissed();
        $this->assertSame( array( 'status' => 'finished' ), $migration );
        $this->assertArchived( self::OTHER_LOG_ID );

        Log_Manager::instance()->cron_module_log_auto_archive();
        $this->assertGreaterThan( 111, (int) get_option( 'mainwp_module_log_last_time_auto_archive_logs' ) );
        $this->assertArchived( self::LOG_ID );
    }

    /** Two logs with one meta row each, created in May 2026. */
    private function seed_logs(): void {
        global $wpdb;
        foreach ( array( self::LOG_ID, self::OTHER_LOG_ID ) as $log_id ) {
            $this->assertNotFalse(
                $wpdb->insert(
                    $this->table( 'wp_logs' ),
                    array(
                        'log_id'      => $log_id,
                        'site_id'     => 5,
                        'log_type_id' => 3,
                        'item'        => 'item-' . $log_id,
                        'user_id'     => 1,
                        'user_login'  => 'login-' . $log_id,
                        'action'      => 'updated',
                        'context'     => 'plugins',
                        'connector'   => 'plugins',
                        'state'       => 1,
                        'created'     => '1780000000000000',
                        'duration'    => 0,
                        'dismiss'     => 0,
                    )
                ),
                $wpdb->last_error
            );
            $this->assertNotFalse(
                $wpdb->insert(
                    $this->table( 'wp_logs_meta' ),
                    array(
                        'meta_id'     => $log_id + 100,
                        'meta_log_id' => $log_id,
                        'meta_key'    => 'meta_key_' . $log_id,
                        'meta_value'  => 'meta value ' . $log_id,
                    )
                ),
                $wpdb->last_error
            );
        }
    }

    /** Make the auto-archive cron treat the seeded 2026 logs as expired. */
    private function expire_logs_for_cron(): void {
        $settings = Log_Manager::instance()->settings;
        if ( null === $this->settings_options ) {
            $this->settings_options = $settings->options;
        }
        $options                     = is_array( $settings->options ) ? $settings->options : array();
        $options['records_logs_ttl'] = 1;
        $settings->options           = $options;
    }

    /**
     * Assert a log and its meta moved to the archive.
     *
     * @param int $log_id Log id.
     */
    private function assertArchived( int $log_id ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion style.
        $this->assertSame( 0, $this->row_count( 'wp_logs', 'log_id', $log_id ), "Log $log_id still live." );
        $this->assertSame( 0, $this->row_count( 'wp_logs_meta', 'meta_log_id', $log_id ), "Meta of $log_id still live." );
        $this->assertSame( 1, $this->row_count( 'wp_logs_archive', 'log_id', $log_id ), "Log $log_id not archived." );
        $this->assertSame( 1, $this->row_count( 'wp_logs_meta_archive', 'meta_log_id', $log_id ), "Meta of $log_id not archived." );
    }

    /**
     * Assert a log and its meta are still live.
     *
     * @param int $log_id Log id.
     */
    private function assertKept( int $log_id ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion style.
        $this->assertSame( 1, $this->row_count( 'wp_logs', 'log_id', $log_id ), "Log $log_id was deleted." );
        $this->assertSame( 1, $this->row_count( 'wp_logs_meta', 'meta_log_id', $log_id ), "Meta of $log_id was deleted." );
    }

    /**
     * Rows for one id, 0 when the table does not exist.
     *
     * @param string $suffix Table suffix.
     * @param string $column Id column.
     * @param int    $id Id.
     * @return int
     */
    private function row_count( string $suffix, string $column, int $id ): int {
        global $wpdb;
        if ( ! $this->table_exists( $suffix ) ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table( $suffix ) . " WHERE $column = %d", $id ) );
    }

    /**
     * Whether a (temporary) table exists.
     *
     * @param string $suffix Table suffix.
     * @return bool
     */
    private function table_exists( string $suffix ): bool {
        return array() !== $this->columns( $suffix );
    }

    /**
     * Column names of a (temporary) table.
     *
     * @param string $suffix Table suffix.
     * @return array
     */
    private function columns( string $suffix ): array {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        $columns  = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $this->table( $suffix ) );
        $wpdb->suppress_errors( $suppress );
        return is_array( $columns ) ? $columns : array();
    }

    /**
     * Test table name.
     *
     * @param string $suffix Table suffix.
     * @return string
     */
    private function table( string $suffix ): string {
        return $this->prefix . $suffix;
    }

    /**
     * Copy a production log table into a temporary test table.
     *
     * @param string $suffix Table suffix.
     * @param string $source Production table suffix to copy, defaults to $suffix.
     */
    private function temporary_table_like( string $suffix, string $source = '' ): void {
        global $wpdb;
        $this->assertNotFalse(
            $wpdb->query( 'CREATE TEMPORARY TABLE ' . $this->table( $suffix ) . ' LIKE ' . MainWP_DB::instance()->get_table_name( '' === $source ? $suffix : $source ) ),
            $wpdb->last_error
        );
    }

    /**
     * Row Dismiss (also used once per row by Dismiss Selected).
     *
     * @param int $log_id Log id.
     * @return array Decoded response.
     */
    private function dismiss( int $log_id ): array {
        $page = ( new \ReflectionClass( Log_Manage_Insights_Events_Page::class ) )->newInstanceWithoutConstructor();
        return $this->request( 'mainwp_insight_events_dismiss_actions', array( 'log_id' => (string) $log_id ), array( $page, 'ajax_sites_changes_dismiss_selected' ) );
    }

    /**
     * Dismiss All.
     *
     * @return array Decoded response.
     */
    private function dismiss_all(): array {
        $page = ( new \ReflectionClass( Log_Manage_Insights_Events_Page::class ) )->newInstanceWithoutConstructor();
        return $this->request( 'mainwp_insight_events_dismiss_all', array(), array( $page, 'ajax_sites_changes_dismiss_all' ) );
    }

    /**
     * Legacy dismissed-rows migration step.
     *
     * @return array Decoded response.
     */
    private function migrate_dismissed(): array {
        return $this->request( 'mainwp_module_log_update_dismissed_db', array(), array( '\MainWP\Dashboard\Module\Log\MWP_2140_Insights_Page', 'ajax_archive_dismissed_db' ) );
    }

    /**
     * Run an AJAX handler with a valid nonce and decode its JSON.
     *
     * @param string   $action Nonce action.
     * @param array    $post Request fields.
     * @param callable $callback Handler.
     * @return array
     */
    private function request( string $action, array $post, callable $callback ): array {
        $_POST    = array_merge(
            $post,
            array(
                'security' => wp_create_nonce( $action ),
                'dts'      => wp_generate_uuid4(),
            )
        );
        $_REQUEST = $_POST;
        $handler  = static function () {
            return static function ( $message ) {
                throw new MWP_2140_Response_Stop( (string) $message );
            };
        };
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', $handler );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( MWP_2140_Response_Stop $stop ) {
            $body = $stop->getMessage();
        } finally {
            $body = ob_get_clean() . $body;
            remove_filter( 'wp_die_ajax_handler', $handler );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        $this->last_body = $body;
        // Old code printed wpdb errors before the JSON; decode the JSON so row checks still run.
        $decoded = json_decode( (string) substr( $body, (int) strrpos( $body, '</div>{' ) + ( false === strrpos( $body, '</div>{' ) ? 0 : 6 ) ), true );
        $this->assertIsArray( $decoded, 'Not JSON: ' . $body );
        return $decoded;
    }

    /**
     * Add a filter that tearDown removes.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        $this->filters[ $hook ][] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }
}
