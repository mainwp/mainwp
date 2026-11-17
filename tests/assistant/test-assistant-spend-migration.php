<?php
/**
 * Tests for the assistant_spend per-user schema migration.
 *
 * The table's grain changes from one row per day to one row per (day, user):
 * a user_id column joins the primary key so per-user caps can read their own
 * accrual. Existing rows must survive the upgrade in the user_id 0 global
 * bucket, and re-running the migration must be a no-op.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Assistant;

/**
 * Class Test_Assistant_Spend_Migration
 */
class Test_Assistant_Spend_Migration extends MainWP_Assistant_Test_Case {

	/**
	 * Spend table name.
	 *
	 * @return string
	 */
	private function spend_table() {
		global $wpdb;
		return $wpdb->prefix . 'mainwp_assistant_spend';
	}

	/**
	 * Column names of the spend table's PRIMARY KEY, in key order.
	 *
	 * @return string[]
	 */
	private function primary_key_columns() {
		global $wpdb;
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$this->spend_table()} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema inspection.
		usort( $rows, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_map( static fn( $r ) => $r['Column_name'], $rows );
	}

	/**
	 * Whether the spend table has a user_id column.
	 *
	 * @return bool
	 */
	private function has_user_id_column() {
		global $wpdb;
		return (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$this->spend_table()} LIKE 'user_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema inspection.
	}

	/**
	 * Recreate the pre-migration table shape (spend_day-only PK, no user_id).
	 *
	 * DDL auto-commits, so this deliberately escapes the test transaction;
	 * tests that call it must restore the new shape before finishing.
	 *
	 * @return void
	 */
	private function create_old_shape_table() {
		global $wpdb;
		$table           = $this->spend_table();
		$charset_collate = $wpdb->get_charset_collate();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture DDL.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture DDL.
			"CREATE TABLE {$table} (
				spend_day date NOT NULL,
				cost_usd decimal(14,6) NOT NULL default 0,
				prompt_tokens bigint(20) NOT NULL default 0,
				completion_tokens bigint(20) NOT NULL default 0,
				unpriced_turns int(11) NOT NULL default 0,
				turns int(11) NOT NULL default 0,
				updated_at int(11) NOT NULL default 0,
				PRIMARY KEY (spend_day)
			) {$charset_collate}"
		);
	}

	/**
	 * Fresh installs create the spend table with the composite (day, user) key.
	 *
	 * @return void
	 */
	public function test_fresh_spend_table_has_composite_primary_key() {
		$this->assertTrue( $this->has_user_id_column(), 'Fresh install must include the user_id column.' );
		$this->assertSame( array( 'spend_day', 'user_id' ), $this->primary_key_columns() );
	}

	/**
	 * Upgrading an old-shape table adds the column and composite key while
	 * preserving existing rows in the user_id 0 bucket.
	 *
	 * @return void
	 */
	public function test_migration_upgrades_old_table_preserving_rows() {
		global $wpdb;
		$table = $this->spend_table();

		$this->create_old_shape_table();
		$wpdb->query( "INSERT INTO {$table} (spend_day, cost_usd, turns) VALUES ('2026-01-01', 1.25, 3), ('2026-01-02', 0.50, 1)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.

		try {
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );

			$this->assertTrue( $this->has_user_id_column(), 'Migration must add the user_id column.' );
			$this->assertSame( array( 'spend_day', 'user_id' ), $this->primary_key_columns() );

			$rows = $wpdb->get_results( "SELECT spend_day, user_id, cost_usd, turns FROM {$table} ORDER BY spend_day", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Assertion read.
			$this->assertCount( 2, $rows, 'Existing spend rows must survive the migration.' );
			$this->assertSame( 0, (int) $rows[0]['user_id'], 'Pre-migration rows land in the user_id 0 global bucket.' );
			$this->assertEqualsWithDelta( 1.25, (float) $rows[0]['cost_usd'], 0.000001 );
			$this->assertSame( 3, (int) $rows[0]['turns'] );
			$this->assertSame( 0, (int) $rows[1]['user_id'] );
		} finally {
			$wpdb->query( "DELETE FROM {$table} WHERE spend_day IN ('2026-01-01', '2026-01-02')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup: DDL escaped the transaction.
		}
	}

	/**
	 * The postcondition retries a failed ADD COLUMN.
	 *
	 * The ALTERs run suppressed, so a swallowed failure used to leave the
	 * column silently missing and every accrual INSERT failing. The migration
	 * must verify the column landed and retry once when it did not. Against
	 * the pre-postcondition code only one ALTER attempt happens.
	 *
	 * The broken-accounting flag deliberately stays out of this test: the
	 * ALTERs' implicit commits would persist the option write past the test
	 * transaction and its rollback would resurrect the row for later tests.
	 * Flag clearing is covered DDL-free below.
	 *
	 * @return void
	 */
	public function test_migration_postcondition_retries_failed_alter() {
		$this->create_old_shape_table();

		// Break only the first ADD COLUMN attempt; the retry runs unmodified.
		$attempts          = 0;
		$break_first_alter = function ( $query ) use ( &$attempts ) {
			if ( false !== stripos( $query, 'ADD COLUMN' ) && false !== stripos( $query, 'user_id' ) ) {
				++$attempts;
				if ( 1 === $attempts ) {
					return 'ALTER TABLE nonexistent_mainwp_spend_probe ADD COLUMN user_id bigint(20)';
				}
			}
			return $query;
		};
		add_filter( 'query', $break_first_alter );

		try {
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );
		} finally {
			remove_filter( 'query', $break_first_alter );
		}

		$this->assertSame( 2, $attempts, 'The postcondition must retry the failed ALTER once.' );
		$this->assertTrue( $this->has_user_id_column(), 'The retried ALTER must land the user_id column.' );
		$this->assertSame( array( 'spend_day', 'user_id' ), $this->primary_key_columns() );
	}

	/**
	 * The postcondition retries a failed PRIMARY KEY swap.
	 *
	 * A user_id column without the composite key is the silent grain failure:
	 * ON DUPLICATE KEY UPDATE keyed on spend_day alone collapses every user's
	 * accrual into one row and inserts "succeed", so nothing trips the broken
	 * flag. The migration must verify the composite key landed and retry the
	 * ALTER once when it did not. Against the pre-postcondition code only one
	 * ALTER attempt happens.
	 *
	 * The broken-accounting flag stays out of this test too (DDL commits
	 * would resurrect the option row past the test transaction).
	 *
	 * @return void
	 */
	public function test_migration_postcondition_retries_failed_primary_key_swap() {
		$this->create_old_shape_table();

		// Break only the first PK swap attempt; the retry runs unmodified.
		$attempts       = 0;
		$break_first_pk = function ( $query ) use ( &$attempts ) {
			if ( false !== stripos( $query, 'DROP PRIMARY KEY' ) ) {
				++$attempts;
				if ( 1 === $attempts ) {
					return 'ALTER TABLE nonexistent_mainwp_spend_probe DROP PRIMARY KEY';
				}
			}
			return $query;
		};
		add_filter( 'query', $break_first_pk );

		try {
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );
		} finally {
			remove_filter( 'query', $break_first_pk );
		}

		$this->assertSame( 2, $attempts, 'The postcondition must retry the failed PRIMARY KEY swap once.' );
		$this->assertTrue( $this->has_user_id_column() );
		$this->assertSame( array( 'spend_day', 'user_id' ), $this->primary_key_columns(), 'The retried ALTER must land the composite key.' );
	}

	/**
	 * A broken-grain schema (column present, single-column key) keeps the flag.
	 *
	 * @return void
	 */
	public function test_migration_keeps_broken_flag_when_primary_key_swap_fails() {
		$this->create_old_shape_table();

		// Every PK swap attempt fails: column lands, composite key never does.
		$break_all_pk = function ( $query ) {
			if ( false !== stripos( $query, 'DROP PRIMARY KEY' ) ) {
				return 'ALTER TABLE nonexistent_mainwp_spend_probe DROP PRIMARY KEY';
			}
			return $query;
		};
		add_filter( 'query', $break_all_pk );

		try {
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );

			// The flag write happens AFTER the migration's DDL, inside the
			// test transaction, so it rolls back cleanly with the test.
			update_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN, time() );
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );

			$this->assertTrue( $this->has_user_id_column() );
			$this->assertSame( array( 'spend_day' ), $this->primary_key_columns(), 'Fixture: the composite key must still be missing.' );
			$this->assertNotEmpty(
				get_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN ),
				'A verified column with a missing composite key must NOT clear the broken flag.'
			);
		} finally {
			remove_filter( 'query', $break_all_pk );
			delete_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN );
			// Restore the migrated shape for later tests: DDL escaped the
			// test transaction.
			MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.4', '9.0.2.5' );
		}
	}

	/**
	 * A verified schema clears the broken-accounting flag on upgrade.
	 *
	 * @return void
	 */
	public function test_migration_clears_broken_flag_when_schema_verified() {
		update_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN, time() );

		MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.5', '9.0.2.5' );

		$this->assertFalse(
			get_option( MainWP_DB_Assistant::OPTION_SPEND_ACCOUNTING_BROKEN ),
			'A migration run that verifies the schema must clear the broken flag.'
		);
	}

	/**
	 * Re-running the migration against an already-migrated table is a no-op.
	 *
	 * @return void
	 */
	public function test_migration_rerun_is_noop() {
		global $wpdb;

		MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.5', '9.0.2.5' );
		$first_pk = $this->primary_key_columns();

		MainWP_DB_Assistant::instance()->hook_db_after_update( '9.0.2.5', '9.0.2.5' );

		$this->assertSame( array( 'spend_day', 'user_id' ), $this->primary_key_columns() );
		$this->assertSame( $first_pk, $this->primary_key_columns() );
		$this->assertSame( '', $wpdb->last_error, 'A repeated migration must not raise database errors.' );
	}
}
