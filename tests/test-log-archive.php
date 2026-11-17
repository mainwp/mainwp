<?php
/**
 * Log archive tests.
 *
 * Covers Log_DB_Archive::archive_sites_changes(), which selects rows out of the
 * logs table and DELETEs them after copying them to the archive. The selection
 * SQL is built from a prepared WHERE; if that WHERE ever widened, this path
 * would delete rows it was never asked to touch, so the boundary cases are
 * asserted here rather than assumed.
 *
 * @package Mainwp
 */

use MainWP\Dashboard\Module\Log\Log_DB_Archive;
use MainWP\Dashboard\Module\Log\Log_Install;

/**
 * Class Test_Log_Archive
 */
class Test_Log_Archive extends TestCase {

    /**
     * Logs table name.
     *
     * @var string
     */
    private $logs_table;

    /**
     * Archive table name.
     *
     * @var string
     */
    private $archive_table;

    /**
     * Set up the log tables and start from an empty pair.
     */
    public function setUp(): void {
        parent::setUp();

        global $wpdb;

        // Module classes are not in the core autoloader; load them the way the
        // other module tests do.
        if ( ! class_exists( Log_DB_Archive::class ) ) {
            require_once dirname( __DIR__ ) . '/modules/logs/classes/class-log-install.php';
            require_once dirname( __DIR__ ) . '/modules/logs/classes/class-log-db-archive.php';
        }

        $this->logs_table    = $wpdb->prefix . 'mainwp_wp_logs';
        $this->archive_table = $wpdb->prefix . 'mainwp_wp_logs_archive';

        // WP_UnitTestCase rewrites CREATE TABLE into CREATE TEMPORARY TABLE, and temporary
        // tables are invisible to SHOW TABLES, so there is no point guarding this on a table
        // existence probe -- it would read empty every time. They do survive across tests in
        // the class, because the connection outlives them, so install() lands on tables that
        // are already there from the second test onwards. It is idempotent, which is exactly
        // what test_create_archive_tables_does_not_re_add_an_existing_archived_at pins.
        Log_Install::instance()->install();

        $wpdb->query( "DELETE FROM {$this->logs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture; the table name is derived from $wpdb->prefix.
        $wpdb->query( "DELETE FROM {$this->archive_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture; the table name is derived from $wpdb->prefix.
    }

    /**
     * Insert one log row.
     *
     * `created` is stored at the scale archive_sites_changes() compares against:
     * it multiplies its $before_timestamp argument by 1,000,000 before use.
     *
     * @param int $created Created value, already at the stored scale.
     * @param int $dismiss Dismiss flag.
     *
     * @return int Inserted log_id.
     */
    private function insert_log( $created, $dismiss = 0 ) {
        global $wpdb;

        $wpdb->insert(
            $this->logs_table,
            array(
                'site_id'    => 1,
                'item'       => 'test',
                'user_id'    => 1,
                'user_login' => 'tester',
                'action'     => 'updated',
                'context'    => 'plugins',
                'connector'  => 'installer',
                'created'    => $created,
                'dismiss'    => $dismiss,
            )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Log ids still sitting in the live table.
     *
     * @return array
     */
    private function live_ids() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test assertion; the table name is derived from $wpdb->prefix.
        return array_map( 'intval', $wpdb->get_col( "SELECT log_id FROM {$this->logs_table} ORDER BY log_id" ) );
    }

    /**
     * Log ids that were moved to the archive.
     *
     * @return array
     */
    private function archived_ids() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test assertion; the table name is derived from $wpdb->prefix.
        return array_map( 'intval', $wpdb->get_col( "SELECT log_id FROM {$this->archive_table} ORDER BY log_id" ) );
    }

    /**
     * Whether the archive table currently carries the archived_at column.
     *
     * @return bool
     */
    private function archive_has_archived_at() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test assertion; the table name is derived from $wpdb->prefix.
        return (bool) $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$this->archive_table} LIKE %s", 'archived_at' ) );
    }

    /**
     * create_archive_tables() must be safe to run again over tables that already exist.
     *
     * install() reaches it with an empty $currentVersion whenever the version option is
     * missing but the tables are already there, and the ADD COLUMN on that branch used to
     * be unconditional. It collided with the archived_at an earlier pass had added, so the
     * install emitted a "Duplicate column name 'archived_at'" wpdb error every time.
     */
    public function test_create_archive_tables_does_not_re_add_an_existing_archived_at() {
        global $wpdb;

        $this->assertTrue( $this->archive_has_archived_at(), 'Precondition: setUp() leaves archived_at in place.' );

        $wpdb->last_error = '';
        Log_Install::instance()->create_archive_tables( '' );

        $this->assertSame( '', $wpdb->last_error, 'Re-running the fresh-install path over existing tables must not re-add archived_at.' );
        $this->assertTrue( $this->archive_has_archived_at(), 'archived_at must still be there afterwards.' );
    }

    /**
     * Guarding the ALTER must not stop it running when the column is genuinely absent.
     */
    public function test_create_archive_tables_adds_archived_at_when_it_is_missing() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture; the table name is derived from $wpdb->prefix.
        $wpdb->query( "ALTER TABLE {$this->archive_table} DROP COLUMN archived_at" );
        $this->assertFalse( $this->archive_has_archived_at(), 'Precondition: the column is gone.' );

        $wpdb->last_error = '';
        Log_Install::instance()->create_archive_tables( '' );

        $this->assertTrue( $this->archive_has_archived_at(), 'A missing archived_at must still be added.' );
        $this->assertSame( '', $wpdb->last_error, 'Adding the missing column must not error.' );
    }

    /**
     * The cutoff is exclusive, and rows at or after it must survive.
     *
     * This is the assertion that matters: a widened WHERE would sweep up the
     * newer row and delete it.
     */
    public function test_archive_sites_changes_moves_only_rows_before_the_cutoff() {
        $old      = $this->insert_log( 100 * 1000000 );
        $boundary = $this->insert_log( 200 * 1000000 );
        $new      = $this->insert_log( 300 * 1000000 );

        Log_DB_Archive::instance()->archive_sites_changes( 200 );

        $this->assertSame( array( $old ), $this->archived_ids(), 'Only the row before the cutoff should be archived.' );
        $this->assertSame( array( $boundary, $new ), $this->live_ids(), 'The cutoff is exclusive; the boundary row and newer rows must survive.' );
    }

    /**
     * The dismiss filter must narrow the delete, not widen it.
     */
    public function test_archive_sites_changes_respects_the_dismiss_filter() {
        $not_dismissed = $this->insert_log( 100 * 1000000, 0 );
        $dismissed     = $this->insert_log( 100 * 1000000, 1 );

        Log_DB_Archive::instance()->archive_sites_changes( 200, 0, 1 );

        $this->assertSame( array( $dismissed ), $this->archived_ids(), 'Only dismissed rows should be archived.' );
        $this->assertSame( array( $not_dismissed ), $this->live_ids(), 'A non-dismissed row must not be swept up by the dismiss filter.' );
    }

    /**
     * dismiss = 0 is a real filter, not "no filter". `false` is the no-filter value.
     */
    public function test_archive_sites_changes_treats_dismiss_zero_as_a_filter() {
        $not_dismissed = $this->insert_log( 100 * 1000000, 0 );
        $dismissed     = $this->insert_log( 100 * 1000000, 1 );

        Log_DB_Archive::instance()->archive_sites_changes( 200, 0, 0 );

        $this->assertSame( array( $not_dismissed ), $this->archived_ids(), 'dismiss=0 should archive only the undismissed row.' );
        $this->assertSame( array( $dismissed ), $this->live_ids(), 'dismiss=0 must not archive dismissed rows.' );
    }

    /**
     * The batch loop must keep going until the selection is exhausted, not stop
     * after the first batch.
     */
    public function test_archive_sites_changes_batches_until_exhausted() {
        $ids = array();
        for ( $i = 0; $i < 5; $i++ ) {
            $ids[] = $this->insert_log( ( 100 + $i ) * 1000000 );
        }

        Log_DB_Archive::instance()->archive_sites_changes( 200, 2 );

        $this->assertSame( $ids, $this->archived_ids(), 'All five rows should archive across batches of two.' );
        $this->assertSame( array(), $this->live_ids(), 'Nothing should be left behind once the selection is exhausted.' );
    }

    /**
     * Called with no bounds, every row is archived. That is the existing contract
     * and it is worth pinning: it means an empty argument list is destructive.
     */
    public function test_archive_sites_changes_with_no_bounds_archives_everything() {
        $first  = $this->insert_log( 100 * 1000000 );
        $second = $this->insert_log( 900 * 1000000, 1 );

        Log_DB_Archive::instance()->archive_sites_changes();

        $this->assertSame( array( $first, $second ), $this->archived_ids(), 'With no bounds every row is archived.' );
        $this->assertSame( array(), $this->live_ids(), 'With no bounds the live table is emptied.' );
    }
}
