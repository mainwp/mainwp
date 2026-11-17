<?php
/**
 * Tests for the Logs-module zero-timestamp migration (M15).
 *
 * The 1.0.1.51 migration used a 250-row loop of per-row UPDATEs; a
 * persistently failing UPDATE re-selected the same 250 ids forever — an
 * infinite loop inside a normal request. The fix is ONE set-based UPDATE
 * anchored to now, using log_id to keep the values distinct and ordered.
 *
 * The Logs module is disabled under phpunit (tests/bootstrap.php), so the
 * class file is included directly and the private migration method is
 * invoked via reflection against a hand-created table.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for Log_Install::update_log_db_60() zero-timestamp handling.
 *
 * @group abilities
 * @group logs-migration
 */
class Test_Log_Install_Timestamp_Migration extends MainWP_Abilities_Test_Case {

    /**
     * Fully qualified Log_Install class name.
     *
     * @var string
     */
    const LOG_INSTALL_CLASS = 'MainWP\Dashboard\Module\Log\Log_Install';

    /**
     * Logs table name for this test run.
     *
     * @var string
     */
    private $logs_table = '';

    /**
     * Create the logs table (DDL first — it commits the surrounding
     * transaction, so it must precede row fixtures) and load the class.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();

        global $wpdb;
        $this->logs_table = $wpdb->prefix . 'mainwp_wp_logs';

        if ( ! class_exists( self::LOG_INSTALL_CLASS ) ) {
            include_once MAINWP_PLUGIN_DIR . 'modules/logs/classes/class-log-install.php';
        }
        if ( ! class_exists( self::LOG_INSTALL_CLASS ) ) {
            $this->markTestSkipped( 'Log_Install class is not loadable in this environment.' );
        }

        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$this->logs_table} ( log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, created BIGINT(20) UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (log_id) )" ); // phpcs:ignore WordPress.DB -- test fixture DDL.
        $wpdb->query( "DELETE FROM {$this->logs_table}" ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
    }

    /**
     * Drop the fixture table.
     *
     * @return void
     */
    public function tearDown(): void {
        global $wpdb;
        if ( '' !== $this->logs_table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$this->logs_table}" ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
        }
        parent::tearDown();
    }

    /**
     * Zeroed timestamps are fixed in ONE set-based pass: every row becomes a
     * distinct, now-anchored value whose spacing follows log_id (the per-row
     * loop assigned sequential offsets instead), and non-zero rows are
     * untouched.
     *
     * @return void
     */
    public function test_zero_timestamps_fixed_in_single_set_based_update() {
        global $wpdb;

        // log_ids with deliberate gaps: value spacing must follow these gaps.
        $legit_created = (int) ( microtime( true ) * 1000000 ) - HOUR_IN_SECONDS * 1000000;
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->logs_table} (log_id, created) VALUES (5, 0), (8, 0), (14, 0), (20, %d)", $legit_created ) ); // phpcs:ignore WordPress.DB -- test fixture.

        $installer = ( new \ReflectionClass( self::LOG_INSTALL_CLASS ) )->newInstanceWithoutConstructor();
        $db_base   = new \ReflectionClass( 'MainWP\Dashboard\MainWP_DB_Base' );
        $wpdb_prop = $db_base->getProperty( 'wpdb' );
        $wpdb_prop->setAccessible( true );
        $wpdb_prop->setValue( $installer, $wpdb );
        $prefix_prop = $db_base->getProperty( 'table_prefix' );
        $prefix_prop->setAccessible( true );
        $prefix_prop->setValue( $installer, $wpdb->prefix . 'mainwp_' );

        $method = new \ReflectionMethod( self::LOG_INSTALL_CLASS, 'update_log_db_60' );
        $method->setAccessible( true );

        $anchor_us = (int) ( microtime( true ) * 1000000 );
        $zero_updates = 0;
        $count_zero_updates = static function ( $sql ) use ( &$zero_updates ) {
            if ( preg_match( '/^UPDATE .* SET created = [0-9]+ \\+ log_id WHERE created = 0$/', $sql ) ) {
                ++$zero_updates;
            }
            return $sql;
        };
        add_filter( 'query', $count_zero_updates );
        try {
            $method->invoke( $installer, '1.0.1.50' );
        } finally {
            remove_filter( 'query', $count_zero_updates );
        }

        $rows = $wpdb->get_results( "SELECT log_id, created FROM {$this->logs_table} ORDER BY log_id ASC" ); // phpcs:ignore WordPress.DB -- test assertion query.

        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->logs_table} WHERE created = 0" ), 'Every zeroed row must be fixed.' ); // phpcs:ignore WordPress.DB -- test assertion query.
        $this->assertSame( $legit_created, (int) $rows[3]->created, 'Non-zero rows must be untouched.' );

        $fixed = array_slice( $rows, 0, 3 );
        foreach ( $fixed as $row ) {
            $this->assertGreaterThanOrEqual( $anchor_us, (int) $row->created, 'Fixed values must be now-anchored.' );
        }
        $this->assertSame(
            array( 3, 6 ),
            array(
                (int) $fixed[1]->created - (int) $fixed[0]->created,
                (int) $fixed[2]->created - (int) $fixed[1]->created,
            ),
            'Value spacing must follow log_id gaps (set-based), not sequential per-row offsets.'
        );

        $this->assertSame( 1, $zero_updates, 'Zero timestamps must be repaired in one UPDATE.' );
    }
}
