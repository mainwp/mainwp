<?php
/**
 * Cost Tracker price column keeps NOT NULL through upgrade and repair (MWP-1792).
 *
 * @package MainWP/Dashboard
 */

use MainWP\Dashboard\MainWP_Database_Schema_Checker;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB;

/**
 * Class Test_Cost_Tracker_Price_Not_Null
 */
class Test_Cost_Tracker_Price_Not_Null extends WP_UnitTestCase {

    /**
     * Reset repair markers so each case starts from the column itself.
     */
    public function set_up() {
        parent::set_up();
        delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION );
        delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION );
    }

    /**
     * Remove rows this class inserted and put the module back to a current install.
     *
     * The DROP and ALTER statements in these tests commit the test transaction, so the
     * rollback in parent::tear_down() cannot undo rows, version options or schema written
     * before them. Leaked cost rows changed the counts of later cost-scope tests.
     */
    public function tear_down() {
        global $wpdb;
        $table = $this->cost_table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE slug LIKE %s", $wpdb->esc_like( 'mwp-1792-' ) . '%' ) );
        delete_option( 'mainwp_module_cost_tracker_db_version' );
        delete_site_option( 'mainwp_module_cost_tracker_db_version' );
        Cost_Tracker_DB::get_instance()->install();
        delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION );
        delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION );
        $wpdb->query( 'COMMIT' );
        parent::tear_down();
    }

    /**
     * A new table is created with price decimal(26,8) NOT NULL.
     */
    public function test_fresh_install_price_column_is_not_null() {
        global $wpdb;

        $table = $this->cost_table();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( 'mainwp_module_cost_tracker_db_version' );
        delete_site_option( 'mainwp_module_cost_tracker_db_version' );

        Cost_Tracker_DB::get_instance()->install();

        $column = $this->price_column();
        $this->assertNotEmpty( $column );
        $this->assertSame( 'NO', $column->Null );
        $this->assertSame( 'decimal(26,8)', strtolower( $column->Type ) );
        $this->assertSame( array(), $this->price_schema_issues() );
    }

    /**
     * The pre-1.0.8 precision change must keep NOT NULL and existing prices.
     */
    public function test_upgrade_from_pre_108_preserves_not_null_and_prices() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(10,2)' );
        $this->add_legacy_author_column();

        $kept_id = $this->insert_cost( 'kept', '19.50' );
        $null_id = $this->insert_cost( 'null-price', null );

        Cost_Tracker_DB::get_instance()->update_db_cost( '1.0.7' );

        $column = $this->price_column();
        $this->assertSame( 'NO', $column->Null );
        $this->assertSame( 'decimal(26,8)', strtolower( $column->Type ) );
        $this->assertSame( '19.50000000', $this->stored_price( $kept_id ) );
        $this->assertSame( '0.00000000', $this->stored_price( $null_id ) );
        $this->assertSame( array(), $this->price_schema_issues() );
    }

    /**
     * A site already recorded at the current db version still gets the constraint back.
     */
    public function test_already_upgraded_nullable_price_is_repaired() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );
        update_option( 'mainwp_module_cost_tracker_db_version', '1.0.18' );
        update_site_option( 'mainwp_module_cost_tracker_db_version', '1.0.18' );

        $kept_id = $this->insert_cost( 'kept-current', '8.25' );
        $null_id = $this->insert_cost( 'null-current', null );

        $this->assertNotEmpty( $this->price_schema_issues(), 'a nullable price column is a real schema mismatch' );

        Cost_Tracker_DB::get_instance()->install();

        $column = $this->price_column();
        $this->assertSame( 'NO', $column->Null );
        $this->assertSame( 'decimal(26,8)', strtolower( $column->Type ) );
        $this->assertSame( '8.25000000', $this->stored_price( $kept_id ) );
        $this->assertSame( '0.00000000', $this->stored_price( $null_id ) );
        $this->assertSame( array(), $this->price_schema_issues() );
        $this->assertSame( '1', (string) get_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION ) );
    }

    /**
     * A failed repair waits an hour instead of altering the table on every request.
     */
    public function test_failed_price_repair_is_throttled() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $failing = new class() extends Cost_Tracker_DB {
            public function repair_cost_tracker_price_not_null() {
                return false;
            }
        };

        $this->assertFalse( $failing->maybe_repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertNotEmpty( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION ) );

        $this->assertFalse( $failing->maybe_repair_cost_tracker_price_not_null() );

        update_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION, time() - MINUTE_IN_SECONDS );
        $this->assertTrue( Cost_Tracker_DB::get_instance()->maybe_repair_cost_tracker_price_not_null() );
        $this->assertSame( 'NO', $this->price_column()->Null );
        $this->assertFalse( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION ) );
    }

    /**
     * An upgrade runs the retry wrapper once. A failed attempt is not repeated immediately.
     */
    public function test_upgrade_failure_is_not_retried_on_the_same_pass() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );
        $this->add_legacy_author_column();
        update_option( 'mainwp_module_cost_tracker_db_version', '1.0.7' );
        update_site_option( 'mainwp_module_cost_tracker_db_version', '1.0.7' );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Repair attempts during this install.
             *
             * @var int
             */
            public $repairs = 0;

            /**
             * Count the attempt and leave the column unchanged.
             *
             * @return bool
             */
            public function repair_cost_tracker_price_not_null() {
                ++$this->repairs;
                return false;
            }
        };

        $db->install();

        $this->assertSame( 1, $db->repairs );
        $this->assertNotEmpty( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION ) );
        $this->assertFalse( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION ) );
        $this->assertSame( 'YES', $this->price_column()->Null );

        $db->install();
        $this->assertSame( 1, $db->repairs, 'the hourly retry must block a second ALTER on the next install' );
    }

    /**
     * A failed NOT NULL change puts the prices that were NULL back to NULL.
     */
    public function test_failed_alter_restores_null_prices() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $kept_id = $this->insert_cost( 'restore-kept', '4.50' );
        $null_id = $this->insert_cost( 'restore-null', null );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Skip the ALTER so verification fails after the NULL prices were zeroed.
             *
             * @param string $table Escaped table name.
             *
             * @return void
             */
            protected function apply_cost_tracker_price_not_null( $table ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            }
        };

        $this->assertFalse( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertNull( $this->stored_price( $null_id ) );
        $this->assertSame( '4.50000000', $this->stored_price( $kept_id ) );
    }

    /**
     * A repaired NULL price is not served from the object cache.
     */
    public function test_successful_repair_drops_cached_null_price() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $null_id = $this->insert_cost( 'cache-null', null );
        $before  = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $null_id );
        $this->assertNull( $before->price );

        wp_cache_set( 'mainwp_cost_tracker_all', 'stale-sentinel', 'mainwp_cost_tracker' );

        $this->assertTrue( Cost_Tracker_DB::get_instance()->repair_cost_tracker_price_not_null() );

        $after = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $null_id );
        $this->assertSame( '0.00000000', $after->price );
        $this->assertNotSame( 'stale-sentinel', Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all' ) );
    }

    /**
     * A price written after the NULL id list is captured is not replaced with 0.
     */
    public function test_concurrent_price_edit_is_not_zeroed() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $edited_id = $this->insert_cost( 'race-edited', null );
        $still_null = $this->insert_cost( 'race-still-null', null );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Id that receives a real price after the id list is read.
             *
             * @var int
             */
            public $race_id = 0;

            /**
             * Simulate a save that lands between the SELECT and the UPDATE.
             *
             * @param string $table Escaped table name.
             *
             * @return array|null
             */
            protected function cost_tracker_null_price_ids( $table ) {
                $ids = parent::cost_tracker_null_price_ids( $table );
                global $wpdb;
                $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET price = %f WHERE id = %d", 9.99, $this->race_id ) );
                return $ids;
            }
        };
        $db->race_id = $edited_id;

        $this->assertTrue( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'NO', $this->price_column()->Null );
        $this->assertSame( '9.99000000', $this->stored_price( $edited_id ) );
        $this->assertSame( '0.00000000', $this->stored_price( $still_null ) );
    }

    /**
     * A price written after the repair zero is not put back to NULL when ALTER fails.
     */
    public function test_concurrent_price_edit_is_not_restored_to_null() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $edited_id = $this->insert_cost( 'race-restore-edited', null );
        $still_zero = $this->insert_cost( 'race-restore-zero', null );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Id edited after it was set to 0.
             *
             * @var int
             */
            public $race_id = 0;

            /**
             * Skip ALTER and write a real price over the repair zero.
             *
             * @param string $table Escaped table name.
             *
             * @return void
             */
            protected function apply_cost_tracker_price_not_null( $table ) {
                global $wpdb;
                $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET price = %f WHERE id = %d", 15, $this->race_id ) );
            }
        };
        $db->race_id = $edited_id;

        $this->assertFalse( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertSame( '15.00000000', $this->stored_price( $edited_id ) );
        $this->assertNull( $this->stored_price( $still_zero ) );
    }

    /**
     * A user-saved price of 0 is not restored to NULL when ALTER fails.
     */
    public function test_user_saved_zero_survives_failed_alter() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $user_zero_id = $this->insert_cost( 'user-zero', '0' );
        $null_id      = $this->insert_cost( 'null-for-restore', null );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Skip the ALTER so verification fails after NULL prices were zeroed.
             *
             * @param string $table Escaped table name.
             *
             * @return void
             */
            protected function apply_cost_tracker_price_not_null( $table ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            }
        };

        $this->assertFalse( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertSame( '0.00000000', $this->stored_price( $user_zero_id ) );
        $this->assertNull( $this->stored_price( $null_id ) );
    }

    /**
     * A failed ALTER still drops stale id, slug, and site_id cache entries.
     */
    public function test_failed_alter_invalidates_cost_caches() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $null_id = $this->insert_cost(
            'cache-fail',
            null,
            array(
                'sites'   => wp_json_encode( array( 42 ) ),
                'clients' => wp_json_encode( array( 7 ) ),
            )
        );

        $db       = Cost_Tracker_DB::get_instance();
        $id_key   = $this->cost_cache_key( 'id', $null_id, array() );
        $slug_key = $this->cost_cache_key( 'slug', 'mwp-1792-cache-fail', array( 'product_type' => 'plugin' ) );
        $site_key = $this->cost_cache_key( 'site_id', 42, array() );
        $client_key = $this->cost_cache_key( 'client_id', 7, array() );

        wp_cache_set( $id_key, (object) array( 'id' => $null_id, 'price' => null ), 'mainwp_cost_tracker' );
        wp_cache_set( $slug_key, (object) array( 'id' => $null_id, 'price' => null ), 'mainwp_cost_tracker' );
        wp_cache_set( $site_key, array( (object) array( 'id' => $null_id, 'price' => null ) ), 'mainwp_cost_tracker' );
        wp_cache_set( $client_key, array( (object) array( 'id' => $null_id, 'price' => null ) ), 'mainwp_cost_tracker' );
        wp_cache_set( 'mainwp_cost_tracker_all', 'stale-all', 'mainwp_cost_tracker' );

        $failing = new class() extends Cost_Tracker_DB {
            /**
             * Skip the ALTER so verification fails after NULL prices were zeroed.
             *
             * @param string $table Escaped table name.
             *
             * @return void
             */
            protected function apply_cost_tracker_price_not_null( $table ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            }
        };

        $this->assertFalse( $failing->repair_cost_tracker_price_not_null() );
        $this->assertFalse( wp_cache_get( $id_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( wp_cache_get( $slug_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( wp_cache_get( $site_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( wp_cache_get( $client_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( wp_cache_get( 'mainwp_cost_tracker_all', 'mainwp_cost_tracker' ) );
        unset( $db );
    }

    /**
     * A mid-loop zeroing failure restores ids already set to 0.
     */
    public function test_mid_loop_zeroing_failure_restores_partial_batch() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $first_id  = $this->insert_cost( 'partial-first', null );
        $second_id = $this->insert_cost( 'partial-second', null );

        $id_key = $this->cost_cache_key( 'id', $first_id, array() );
        wp_cache_set( $id_key, (object) array( 'id' => $first_id, 'price' => null ), 'mainwp_cost_tracker' );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Fail on the second still-NULL row.
             *
             * @var int
             */
            public $updates = 0;

            /**
             * Zero one row, then fail the next UPDATE.
             *
             * @param string $table Escaped table name.
             * @param int    $id Cost id.
             *
             * @return int|false
             */
            protected function zero_one_null_cost_price( $table, $id ) {
                ++$this->updates;
                if ( 2 === $this->updates ) {
                    return false;
                }
                return parent::zero_one_null_cost_price( $table, $id );
            }
        };

        $this->assertFalse( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertNull( $this->stored_price( $first_id ) );
        $this->assertNull( $this->stored_price( $second_id ) );
        $this->assertFalse( wp_cache_get( $id_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION ) );
    }

    /**
     * Missing cache metadata aborts before the column is tightened.
     */
    public function test_cache_metadata_lookup_failure_aborts_repair() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $null_id = $this->insert_cost( 'meta-fail', null );

        $db = new class() extends Cost_Tracker_DB {
            /**
             * Pretend the metadata SELECT failed.
             *
             * @param string $table Escaped table name.
             * @param array  $ids Cost ids.
             *
             * @return null
             */
            protected function cost_tracker_cache_rows( $table, $ids ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
                return null;
            }
        };

        $this->assertFalse( $db->repair_cost_tracker_price_not_null() );
        $this->assertSame( 'YES', $this->price_column()->Null );
        $this->assertNull( $this->stored_price( $null_id ) );
        $this->assertFalse( get_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION ) );
    }

    /**
     * Numeric id cache keys match whether the caller passed int or string.
     */
    public function test_numeric_cache_keys_normalize_int_and_string_ids() {
        $this->ensure_cost_table();
        $this->make_price_nullable( 'decimal(26,8)' );

        $null_id = $this->insert_cost( 'key-norm', null );
        $int_key = $this->cost_cache_key( 'id', (int) $null_id, array() );
        $str_key = $this->cost_cache_key( 'id', (string) $null_id, array() );
        $this->assertSame( $int_key, $str_key );

        wp_cache_set( $str_key, (object) array( 'id' => $null_id, 'price' => null ), 'mainwp_cost_tracker' );
        $this->assertTrue( Cost_Tracker_DB::get_instance()->repair_cost_tracker_price_not_null() );
        $this->assertFalse( wp_cache_get( $int_key, 'mainwp_cost_tracker' ) );
        $this->assertFalse( wp_cache_get( $str_key, 'mainwp_cost_tracker' ) );
    }

    /**
     * Cost tracker table name.
     *
     * @return string
     */
    private function cost_table() {
        global $wpdb;
        return $wpdb->prefix . 'mainwp_cost_tracker';
    }

    /**
     * Create the table when this process has not installed the module yet.
     */
    private function ensure_cost_table() {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->cost_table() ) );
        if ( $exists !== $this->cost_table() ) {
            delete_option( 'mainwp_module_cost_tracker_db_version' );
            delete_site_option( 'mainwp_module_cost_tracker_db_version' );
            Cost_Tracker_DB::get_instance()->install();
            delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_OPTION );
            delete_option( Cost_Tracker_DB::PRICE_NOT_NULL_RETRY_OPTION );
        }
    }

    /**
     * Pre-1.0.9 tables still have author; the upgrade drops it.
     */
    private function add_legacy_author_column() {
        global $wpdb;
        $table  = $this->cost_table();
        $exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'author'" );
        if ( empty( $exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN author varchar(255) NULL" );
        }
    }

    /**
     * Widen or narrow price and allow NULL, which is the broken upgraded state.
     *
     * @param string $definition Column type, including precision.
     */
    private function make_price_nullable( $definition ) {
        global $wpdb;
        $table = $this->cost_table();
        $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN price {$definition} NULL" );
    }

    /**
     * Insert one cost row.
     *
     * @param string      $slug_suffix Row marker.
     * @param string|null $price Price, or null for an SQL NULL.
     * @param array       $extra Optional sites/clients JSON overrides.
     *
     * @return int
     */
    private function insert_cost( $slug_suffix, $price, $extra = array() ) {
        global $wpdb;
        $table   = $this->cost_table();
        $slug    = 'mwp-1792-' . $slug_suffix;
        $sites   = isset( $extra['sites'] ) ? $extra['sites'] : '';
        $clients = isset( $extra['clients'] ) ? $extra['clients'] : '';
        $price_sql = null === $price ? 'NULL' : '%f';
        $args      = array( $slug, $slug );
        if ( null !== $price ) {
            $args[] = $price;
        }
        $args[] = $sites;
        $args[] = $clients;

        $sql = "INSERT INTO {$table}
            (name, url, type, product_type, slug, license_type, cost_status, payment_method, price, renewal_type, last_renewal, next_renewal, next_renewal_today, last_alert, sites, `groups`, clients, note)
            VALUES (%s, 'https://example.com', 'subscription', 'plugin', %s, 'single', 'active', 'paypal', {$price_sql}, 'monthly', 0, 0, 0, 0, %s, '', %s, '')";

        $wpdb->query( $wpdb->prepare( $sql, $args ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Build a Cost Tracker object-cache key the same way the DB class does.
     *
     * @param string $by Query type.
     * @param mixed  $value Query value.
     * @param array  $params Extra params.
     *
     * @return string
     */
    private function cost_cache_key( $by, $value, $params = array() ) {
        if ( in_array( $by, array( 'id', 'site_id', 'client_id' ), true ) && is_numeric( $value ) ) {
            $value = (int) $value;
        }

        return 'mainwp_cost_tracker_' . md5( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- cache key only.
            wp_json_encode(
                array(
                    'method' => 'get_cost_tracker_by',
                    'by'     => $by,
                    'value'  => $value,
                    'params' => $params,
                )
            )
        );
    }

    /**
     * Current price column.
     *
     * @return object|null
     */
    private function price_column() {
        global $wpdb;
        $table = $this->cost_table();
        return $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'price'" );
    }

    /**
     * Stored price for one row.
     *
     * @param int $id Cost id.
     *
     * @return string|null
     */
    private function stored_price( $id ) {
        global $wpdb;
        $table = $this->cost_table();
        return $wpdb->get_var( $wpdb->prepare( "SELECT price FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Schema-checker mismatches for the price column.
     *
     * @return array
     */
    private function price_schema_issues() {
        $issues = MainWP_Database_Schema_Checker::detect_table_columns_issues( $this->cost_table(), 'core' );
        if ( empty( $issues['invalid_columns'] ) || ! is_array( $issues['invalid_columns'] ) ) {
            return array();
        }

        $price_issues = array();
        foreach ( $issues['invalid_columns'] as $issue ) {
            if ( isset( $issue['column'] ) && 'price' === $issue['column'] ) {
                $price_issues[] = $issue;
            }
        }
        return $price_issues;
    }
}
