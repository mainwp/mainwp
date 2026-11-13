<?php
/**
 * Tests for repairing Assistant tables on a current-version install.
 *
 * install() returns early when the stored DB version matches the current one,
 * so a Dashboard that was already on that version when the Assistant tables
 * arrived never runs the full install that creates them.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_Install;

/**
 * Class Test_Assistant_Install_Tables
 */
class Test_Assistant_Install_Tables extends MainWP_Assistant_Test_Case {

	/**
	 * Assistant table names keyed by suffix.
	 *
	 * @return array<string, string>
	 */
	private function assistant_tables() {
		global $wpdb;
		$tables = array();
		foreach ( array( 'assistant_conversations', 'assistant_messages', 'assistant_spend' ) as $suffix ) {
			$tables[ $suffix ] = $wpdb->prefix . 'mainwp_' . $suffix;
		}
		return $tables;
	}

	/**
	 * Whether a real table exists. SHOW TABLES does not list the harness's
	 * temporary tables, which is why the fixtures below use real tables.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema probe.
	}

	/**
	 * Run install() on the current-version path with some Assistant tables dropped.
	 *
	 * The harness rewrites CREATE and DROP to their TEMPORARY forms, so the
	 * rewrite is suspended to work on the real tables. DDL commits the test
	 * transaction, so every table is restored and the result committed before
	 * returning.
	 *
	 * @param string[]      $drop  Table suffixes to drop before install().
	 * @param callable      $check Assertions to run after install(), given the captured queries.
	 * @param callable|null $setup Optional fixture setup after dropping the tables.
	 *
	 * @return void
	 */
	private function with_current_version_install( array $drop, callable $check, ?callable $setup = null ) {
		global $wpdb;

		$install = MainWP_Install::instance();
		$version = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$version->setAccessible( true );
		$stored = get_site_option( 'mainwp_db_version' );
		update_site_option( 'mainwp_db_version', $version->getValue( $install ) );
		$repair_option = MainWP_Install::ASSISTANT_MESSAGE_METADATA_REPAIR;
		$repair_status = get_option( $repair_option );
		delete_option( $repair_option );

		$tables  = $this->assistant_tables();
		$queries = array();
		// The existence probe escapes LIKE wildcards, so it never contains a plain table name.
		$capture = static function ( $query ) use ( &$queries, $tables ) {
			$is_probe = 0 === stripos( $query, 'SHOW TABLES' ) && false !== strpos( $query, 'assistant' );
			if ( $is_probe || str_replace( $tables, '', $query ) !== $query ) {
				$queries[] = $query;
			}
			return $query;
		};

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			foreach ( $drop as $suffix ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$tables[ $suffix ]}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Test fixture DDL.
				$this->assertFalse( $this->table_exists( $tables[ $suffix ] ), "Precondition: {$suffix} is dropped." );
			}

			if ( null !== $setup ) {
				$setup();
			}

			add_filter( 'query', $capture );
			$install->install();
			remove_filter( 'query', $capture );

			$check( $queries );
		} finally {
			remove_filter( 'query', $capture );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( MainWP_DB_Assistant::instance()->hook_db_install_tables( array(), '', $wpdb->get_charset_collate() ) as $sql ) {
				dbDelta( $sql );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			update_site_option( 'mainwp_db_version', $stored );
			if ( false === $repair_status ) {
				delete_option( $repair_option );
			} else {
				update_option( $repair_option, $repair_status );
			}
			self::commit_transaction();
		}
	}

	/**
	 * A Dashboard already on the current DB version gets all three Assistant
	 * tables when they are missing, with the spend table's composite key.
	 *
	 * @return void
	 */
	public function test_current_version_install_creates_missing_assistant_tables() {
		global $wpdb;
		$tables = $this->assistant_tables();

		$this->with_current_version_install(
			array_keys( $tables ),
			function () use ( $wpdb, $tables ) {
				foreach ( $tables as $suffix => $table ) {
					$this->assertTrue( $this->table_exists( $table ), "{$suffix} must be created on a current-version install." );
				}
				$pk = $wpdb->get_col( "SHOW INDEX FROM {$tables['assistant_spend']} WHERE Key_name = 'PRIMARY'", 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Schema probe; column 4 is Column_name.
				$this->assertSame( array( 'spend_day', 'user_id' ), $pk );
				$this->assertNotNull( MainWP_DB_Assistant::instance()->get_spend_today(), 'Spend reads must succeed once the table exists.' );
			}
		);
	}

	/**
	 * Only the missing table needs a schema change.
	 *
	 * @return void
	 */
	public function test_current_version_install_touches_only_the_missing_table() {
		$tables = $this->assistant_tables();

		$this->with_current_version_install(
			array( 'assistant_spend' ),
			function ( array $queries ) use ( $tables ) {
				$this->assertTrue( $this->table_exists( $tables['assistant_spend'] ) );
				foreach ( $queries as $query ) {
					if ( 0 === stripos( $query, 'SHOW' ) ) {
						continue;
					}
					$this->assertStringNotContainsString( $tables['assistant_conversations'], $query );
					$this->assertStringNotContainsString( $tables['assistant_messages'], $query );
				}
			}
		);
	}

	/**
	 * Once the metadata column is confirmed, the existing table check is enough.
	 *
	 * @return void
	 */
	public function test_current_version_install_with_existing_tables_runs_one_check() {
		$this->with_current_version_install(
			array(),
			function ( array $queries ) {
				$this->assertCount( 1, $queries, 'Only one Assistant table query may run per load: ' . implode( ' | ', $queries ) );
				$this->assertStringStartsWith( 'SHOW TABLES LIKE', $queries[0] );
			},
			static function () {
				update_option( MainWP_Install::ASSISTANT_MESSAGE_METADATA_REPAIR, 'confirmed' );
			}
		);
	}

	/**
	 * An existing conversation keeps its history and can store new messages after upgrade.
	 */
	public function test_current_version_install_repairs_legacy_assistant_messages() {
		global $wpdb;
		$db              = MainWP_DB_Assistant::instance();
		$table           = $this->assistant_tables()['assistant_messages'];
		$user_id         = 1;
		$conversation_id = 0;

		$this->with_current_version_install(
			array( 'assistant_messages' ),
			function () use ( $db, $user_id, &$conversation_id ) {
				$metadata = array( 'route' => 'ask' );
				$this->assertNotWPError( $db->add_message( $conversation_id, 'assistant', 'New Docs answer', $user_id, $metadata ) );
				$messages = $db->get_messages( $conversation_id, $user_id );
				$this->assertIsArray( $messages );
				$this->assertSame( array( 'Legacy question', 'New Docs answer' ), wp_list_pluck( $messages, 'content' ) );
				$this->assertSame( $metadata, json_decode( $messages[1]->metadata, true ) );
				$this->assertSame( 'confirmed', get_option( MainWP_Install::ASSISTANT_MESSAGE_METADATA_REPAIR ) );
			},
			function () use ( $wpdb, $db, $table, $user_id, &$conversation_id ) {
				foreach ( $db->hook_db_install_tables( array(), '', $wpdb->get_charset_collate() ) as $sql ) {
					if ( str_starts_with( $sql, "CREATE TABLE {$table} (" ) ) {
						$wpdb->query( str_replace( 'metadata longtext NOT NULL,', '', $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Legacy test fixture from the canonical table definition.
						break;
					}
				}

				$conversation_id = $this->create_test_conversation( $user_id );
				$this->assertSame(
					1,
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Legacy test row without metadata.
						$table,
						array(
							'conversation_id' => $conversation_id,
							'role'            => 'user',
							'content'         => 'Legacy question',
						),
						array( '%d', '%s', '%s' )
					)
				);
			}
		);
	}
}
