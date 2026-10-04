<?php
/**
 * Tests for knowledge record storage, provenance and permissions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Abilities_Knowledge;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Knowledge;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Logger;

/**
 * Class Test_Abilities_Knowledge_DB
 */
class Test_Abilities_Knowledge_DB extends MainWP_Abilities_Test_Case {

	/**
	 * Callbacks added by this test, removed one by one so shared hooks keep
	 * unrelated callbacks.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private $added_filters = array();

	/**
	 * Knowledge DB under test.
	 *
	 * @var MainWP_DB_Knowledge
	 */
	private $db;

	/**
	 * wpdb's suppress_errors value before a fault test silenced it; null when untouched.
	 *
	 * @var bool|null
	 */
	private $suppressed_errors = null;

	private $roster_table_prefix = null;

	public function setUp(): void {
		parent::setUp();
		$this->db = MainWP_DB_Knowledge::instance();
	}

	public function tearDown(): void {
		global $wpdb;
		if ( null !== $this->roster_table_prefix ) {
			$prefix = new \ReflectionProperty( MainWP_DB::class, 'table_prefix' );
			$prefix->setAccessible( true );
			$prefix->setValue( MainWP_DB::instance(), $this->roster_table_prefix );
			$this->roster_table_prefix = null;
		}
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		if ( null !== $this->suppressed_errors ) {
			$wpdb->suppress_errors( $this->suppressed_errors );
			$this->suppressed_errors = null;
		}
		$this->set_machine_context_override( null );
		parent::tearDown();
	}

	private function track_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Point SELECTs on one table at a table that does not exist, so they fail the way a lock timeout or lost connection does.
	 *
	 * @param string $table             Table name without the WordPress prefix, e.g. mainwp_knowledge.
	 * @param bool   $client_scope_only Only fail the SELECTs that read client-scope rows.
	 */
	private function fail_selects_on( string $table, bool $client_scope_only = false ): void {
		global $wpdb;
		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
		$name = $wpdb->prefix . $table;
		// Boundaries keep mainwp_wp from matching mainwp_wp_clients; the query may quote the name with backticks or not.
		$pattern = '/(?<![A-Za-z0-9_])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_])/';
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $pattern, $name, $client_scope_only ) {
				if ( 0 !== stripos( ltrim( $sql ), 'SELECT' ) || ( $client_scope_only && false === strpos( $sql, "scope_type = 'client'" ) ) ) {
					return $sql;
				}
				return preg_replace( $pattern, $name . '_missing', $sql );
			}
		);
	}

	private function restrict_current_user_to_sites( array $site_ids ): void {
		$this->track_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_ids ) {
				return $site_ids;
			}
		);
	}

	private function fail_client_roster_reads(): void {
		// The roster uses mysqli_query() directly, so WordPress's query filter cannot intercept it.
		$prefix = new \ReflectionProperty( MainWP_DB::class, 'table_prefix' );
		$prefix->setAccessible( true );
		$this->roster_table_prefix = $prefix->getValue( MainWP_DB::instance() );
		$prefix->setValue( MainWP_DB::instance(), $this->roster_table_prefix . 'missing_' );
	}

	private function fail_knowledge_deletes(): void {
		global $wpdb;
		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
		$name = $wpdb->prefix . 'mainwp_knowledge';
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $name ) {
				if ( 0 === strpos( $sql, 'DELETE FROM `' . $name . '`' ) ) {
					return str_replace( '`' . $name . '`', '`' . $name . '_missing`', $sql );
				}
				return $sql;
			}
		);
	}

	private function deny_site_access_filter(): void {
		$this->track_filter( 'mainwp_check_site_access', '__return_false' );
	}

	private function withhold_right( string $withheld_right ): void {
		$this->track_filter(
			'mainwp_currentusercan',
			static function ( $allowed, $cap_type, $cap ) use ( $withheld_right ) {
				if ( 'dashboard' === $cap_type && $cap === $withheld_right ) {
					return false;
				}
				return $allowed;
			},
			10,
			3
		);
	}

	private function set_machine_context_override( $value ): void {
		$prop = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	private function input( string $scope_type, int $scope_id, array $overrides = array() ): array {
		$input = array_merge(
			array(
				'scope_type' => $scope_type,
				'scope_id'   => $scope_id,
				'type'       => 'context',
				'title'      => 'Knowledge title',
				'body'       => 'Knowledge body',
			),
			$overrides
		);
		if ( 'agency' === $scope_type && ! array_key_exists( 'scope_id', $overrides ) ) {
			unset( $input['scope_id'] );
		}
		return $input;
	}

	private function create_record( string $scope_type, int $scope_id, array $overrides = array() ) {
		if ( 'skill' === ( $overrides['type'] ?? '' ) ) {
			$overrides += array( 'description' => 'Use this skill when maintaining the site.' );
		}
		$record = $this->db->save( $this->input( $scope_type, $scope_id, $overrides ), MainWP_DB_Knowledge::ORIGIN_UI );
		$this->assertNotWPError( $record );
		return $record;
	}

	private function ids( array $records ): array {
		return array_map( static fn( $record ) => (int) $record->id, $records );
	}

	private function row_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_knowledge" );
	}

	private function assert_error( $result, string $code, int $status ): void {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function assert_not_found( $result, int $id ): void {
		$this->assert_error( $result, 'mainwp_knowledge_not_found', 404 );
		$this->assertSame( sprintf( 'No knowledge record found with ID %d.', $id ), $result->get_error_message() );
	}

	public function test_scope_records_follow_the_input_matrix() {
		global $wpdb;
		$admin = $this->set_current_user_as_admin();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$clients = array();
		$sites = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$clients[] = $this->create_test_client();
			$sites[] = $this->create_test_site( array( 'client_id' => $clients[ $i ] ) );
		}
		$records = array();
		foreach ( array( 'site' => $sites[0], 'client' => $clients[0], 'agency' => 0 ) as $type => $id ) {
			$first = $this->create_record( $type, $id );
			$second = $this->create_record( $type, $id );
			$third = $this->create_record( $type, $id, array( 'type' => 'skill' ) );
			foreach ( array( $first, $second, $third ) as $record ) {
				$wpdb->update( $wpdb->prefix . 'mainwp_knowledge', array( 'updated_at' => $record === $third ? 100 : 200 ), array( 'id' => $record->id ) );
			}
			$records[ $type ] = array( $second->id, $first->id, $third->id );
		}
		$missing = 999999;
		$orphan = (array) $first;
		unset( $orphan['id'] );
		$orphan['scope_type'] = 'client';
		$orphan['scope_id'] = $missing;
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'mainwp_knowledge', $orphan ) );
		// Rows under a missing site id too, so cell 2 fails without the site existence check.
		$orphan['scope_type'] = 'site';
		$this->assertSame( 1, $wpdb->insert( $wpdb->prefix . 'mainwp_knowledge', $orphan ) );
		$matrix = array(
			'1 admin site' => array( 'A', array( array( 'site', $sites[0], $records['site'], 0 ) ) ),
			'2 admin missing site' => array( 'A', array( array( 'site', $missing, array(), 0 ) ) ),
			'3 admin client' => array( 'A', array( array( 'client', $clients[0], $records['client'], 0 ) ) ),
			'4 admin orphan client' => array( 'A', array( array( 'client', $missing, array(), 0 ) ) ),
			'5 restricted visible site' => array( 'R', array( array( 'site', $sites[0], $records['site'], 0 ) ) ),
			'6 restricted hidden site' => array( 'R', array( array( 'site', $sites[1], 'mainwp_site_not_found', 404 ) ) ),
			'7 restricted missing site' => array( 'R', array( array( 'site', $missing, 'mainwp_site_not_found', 404 ) ) ),
			'8 restricted visible client' => array( 'R', array( array( 'client', $clients[0], $records['client'], 0 ) ) ),
			'9 restricted hidden client' => array( 'R', array( array( 'client', $clients[1], 'mainwp_client_not_found', 404 ) ) ),
			'10 restricted missing client' => array( 'R', array( array( 'client', $missing, 'mainwp_client_not_found', 404 ) ) ),
			'11 withheld site right' => array( 'W', array( array( 'site', $sites[0], 'mainwp_access_denied', 403 ) ) ),
			'12 withheld client right' => array( 'W', array( array( 'client', $clients[0], 'mainwp_access_denied', 403 ) ) ),
			'13 denied site filter' => array( 'F', array( array( 'site', $sites[0], 'mainwp_access_denied', 403 ) ) ),
			'14 machine user zero' => array( 'M', array( array( 'site', $sites[0], 'mainwp_permission_denied', 401 ), array( 'client', $clients[0], 'mainwp_permission_denied', 401 ) ) ),
			'15 subscriber' => array( 'S', array( array( 'site', $sites[0], 'mainwp_permission_denied', 403 ), array( 'client', $clients[0], 'mainwp_permission_denied', 403 ) ) ),
			'16 invalid scope' => array( 'A', array( array( 'global', $sites[0], 'mainwp_knowledge_invalid', 400 ), array( 'tag', $sites[0], 'mainwp_knowledge_invalid', 400 ), array( '', $sites[0], 'mainwp_knowledge_invalid', 400 ) ) ),
			'18 agency readers' => array( 'A,R,W,F', array( array( 'agency', 0, $records['agency'], 0 ) ) ),
			'19 agency base gate' => array( 'M', array( array( 'agency', 0, 'mainwp_permission_denied', 401 ) ) ),
			'20 agency subscriber' => array( 'S', array( array( 'agency', 0, 'mainwp_permission_denied', 403 ) ) ),
			'17 zero scope id' => array( 'A,R', array( array( 'site', 0, array(), 0 ), array( 'client', 0, array(), 0 ) ) ),
		);
		$errors = array();
		foreach ( $matrix as $label => list( $callers, $cases ) ) {
			foreach ( explode( ',', $callers ) as $caller ) {
				foreach ( $cases as list( $type, $id, $expected, $status ) ) {
					wp_set_current_user( 'M' === $caller ? 0 : ( 'S' === $caller ? $subscriber : $admin ) );
					$this->set_machine_context_override( 'M' === $caller ? true : null );
					if ( 'R' === $caller ) {
						$this->restrict_current_user_to_sites( array( $sites[0] ) );
						if ( 0 === $id && 'agency' !== $type ) {
							$expected = 'mainwp_' . $type . '_not_found';
							$status = 404;
						}
					} elseif ( 'W' === $caller ) {
						$this->withhold_right( 'site' === $type ? 'access_individual_dashboard' : 'manage_clients' );
					} elseif ( 'F' === $caller ) {
						$this->deny_site_access_filter();
					}
					$queries = array();
					$this->track_filter( 'query', static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; } );
					$result = $this->db->get_scope_records( $type, $id );
					if ( $status ) {
						$this->assertWPError( $result, $label . ' ' . $caller . ' ' . $type );
						$this->assert_error( $result, $expected, $status );
						$errors[ $label ] = str_replace( (string) $id, 'ID', $result->get_error_message() );
						$this->assertEquals( $this->db->can_read( (object) array( 'scope_type' => $type, 'scope_id' => $id ) ), $result, $label );
					} else {
						$this->assertNotWPError( $result, $label );
						$this->assertSame( $expected, $this->ids( $result ), $label );
						if ( ! empty( $expected ) ) {
							$this->assertIsInt( $result[0]->revision, $label );
							$this->assertTrue( property_exists( $result[0], 'body' ), $label );
							if ( 'A' === $caller ) {
								$filtered = $this->db->get_scope_records( $type, $id, array( 'type' => 'context', 'with_body' => false ) );
								$this->assertSame( array_slice( $expected, 0, 2 ), $this->ids( $filtered ), $label );
								foreach ( $filtered as $row ) {
									$this->assertFalse( property_exists( $row, 'body' ), $label );
								}
								$this->assertSame( $expected, $this->ids( $this->db->get_scope_records( $type, $id, array( 'search' => 'no match', 'limit' => 1, 'offset' => 1, 'scope_type' => 'global', 'scope_id' => $missing ) ) ), $label );
							}
							// A restricted walk reads only the full client list; an unrestricted one also reads the full site list, so check both callers.
							$this->assertNotEmpty( $queries, $label );
							foreach ( $queries as $sql ) {
								$this->assertSame( 0, preg_match( '/^\s*SELECT\s+(?:client_id|id)\s+FROM\s+`?' . preg_quote( $wpdb->prefix . 'mainwp_wp', '/' ) . '(?:_clients)?`?\s*;?\s*$/i', $sql ), $label . ': ' . $sql );
							}
						}
					}
					foreach ( $this->added_filters as $filter ) {
						remove_filter( $filter[0], $filter[1], $filter[2] );
					}
					$this->added_filters = array();
				}
			}
		}
		$this->assertSame( $errors['6 restricted hidden site'], $errors['7 restricted missing site'] );
		$this->assertSame( $errors['9 restricted hidden client'], $errors['10 restricted missing client'] );
	}

	public function test_create_stamps_provenance_and_reads_back() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();

		$record = $this->create_record( 'site', $site_id, array( 'body' => "<b>raw</b> & kept\nverbatim" ) );

		$this->assertSame( 1, $record->revision );
		$this->assertSame( 'ui', $record->source );
		$this->assertSame( 1, $record->verified );
		$this->assertSame( $user_id, $record->created_by );
		$this->assertSame( $user_id, $record->updated_by );
		$this->assertSame( "<b>raw</b> & kept\nverbatim", $record->body );

		$read = $this->db->get_record( $record->id );
		$this->assertNotWPError( $read );
		$this->assertEquals( $record, $read );
	}

	public static function description_create_cases(): array {
		return array(
			'skill omitted' => array( array( 'type' => 'skill' ), null ),
			'skill empty' => array( array( 'type' => 'skill', 'description' => '' ), null ),
			'skill whitespace' => array( array( 'type' => 'skill', 'description' => '   ' ), null ),
			'skill supplied' => array( array( 'type' => 'skill', 'description' => 'Use <staging> & PHP < 8.' ), 'Use <staging> & PHP < 8.' ),
			'skill maximum' => array( array( 'type' => 'skill', 'description' => str_repeat( 'é', 1024 ) ), str_repeat( 'é', 1024 ) ),
			'skill too long' => array( array( 'type' => 'skill', 'description' => str_repeat( 'é', 1025 ) ), null ),
			'skill invalid UTF-8' => array( array( 'type' => 'skill', 'description' => "broken \xC3 byte" ), null ),
			'skill non-string' => array( array( 'type' => 'skill', 'description' => 7 ), null ),
			'context omitted' => array( array( 'type' => 'context' ), '' ),
			'context empty' => array( array( 'type' => 'context', 'description' => '' ), '' ),
			'context supplied' => array( array( 'type' => 'context', 'description' => 'Hidden text' ), null ),
			'memory omitted' => array( array( 'type' => 'memory' ), '' ),
			'memory empty' => array( array( 'type' => 'memory', 'description' => '' ), '' ),
			'memory supplied' => array( array( 'type' => 'memory', 'description' => 'Hidden text' ), null ),
		);
	}

	/** @dataProvider description_create_cases */
	public function test_description_create_rule_matrix( array $fields, ?string $expected ): void {
		$this->set_current_user_as_admin();
		$result = $this->db->save( $this->input( 'site', $this->create_test_site(), $fields ), MainWP_DB_Knowledge::ORIGIN_UI );
		if ( null === $expected ) {
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 0, $this->row_count() );
			if ( isset( $fields['description'] ) && is_string( $fields['description'] ) && mb_strlen( $fields['description'] ) > 1024 ) {
				$this->assertSame( 'The description can be at most 1024 characters.', $result->get_error_message() );
			} elseif ( 'skill' === $fields['type'] && is_string( $fields['description'] ?? '' ) && '' === trim( $fields['description'] ?? '' ) ) {
				$this->assertSame( 'A skill needs a description that says when an AI agent should use it.', $result->get_error_message() );
			} elseif ( 'skill' !== $fields['type'] ) {
				$this->assertSame( 'Only skills have a description. Remove it, or change the type to Skill.', $result->get_error_message() );
			}
		} else {
			$this->assertNotWPError( $result );
			$this->assertSame( $expected, $result->description );
			$this->assertSame( $expected, $this->db->get_record( $result->id )->description );
		}
	}

	public static function description_update_cases(): array {
		$cases = array();
		foreach ( array( 'skill', 'context', 'memory', 'legacy skill' ) as $stored ) {
			foreach ( array( null, 'skill', 'context', 'memory' ) as $type ) {
				foreach ( array( 'omitted', 'empty', 'supplied' ) as $description ) {
					$patch = array( 'title' => 'Edited title' );
					if ( null !== $type ) {
						$patch['type'] = $type;
					}
					if ( 'omitted' !== $description ) {
						$patch['description'] = 'empty' === $description ? '' : 'Use the new procedure.';
					}
					$target = $type ?? ( 'legacy skill' === $stored ? 'skill' : $stored );
					$expected = $patch['description'] ?? ( 'skill' === $target && 'skill' === $stored ? 'Use this skill when maintaining the site.' : '' );
					$valid = 'skill' === $target ? '' !== $expected : '' === $expected;
					$cases[ $stored . ' to ' . ( $type ?? 'unchanged' ) . ', ' . $description ] = array( $stored, $patch, $valid ? $expected : null );
				}
			}
		}
		return $cases;
	}

	/** @dataProvider description_update_cases */
	public function test_description_update_rule_matrix( string $stored, array $patch, ?string $expected ): void {
		global $wpdb;
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'site', $this->create_test_site(), array( 'type' => 'legacy skill' === $stored ? 'context' : $stored ) );
		if ( 'legacy skill' === $stored ) {
			$this->assertSame( 1, $wpdb->update( $wpdb->prefix . 'mainwp_knowledge', array( 'type' => 'skill' ), array( 'id' => $record->id ) ) );
		}
		$before = $this->db->get_record( $record->id );
		$result = $this->db->save( $patch + array( 'id' => $record->id ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		if ( null === $expected ) {
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'skill' === ( $patch['type'] ?? $before->type )
				? 'A skill needs a description that says when an AI agent should use it.'
				: 'Only skills have a description. Remove it, or change the type to Skill.', $result->get_error_message() );
			$this->assertEquals( $before, $this->db->get_record( $record->id ) );
		} else {
			$this->assertNotWPError( $result );
			$this->assertSame( $expected, $result->description );
			$this->assertSame( $patch['type'] ?? $before->type, $result->type );
			$this->assertSame( 2, $result->revision );
			$this->assertSame( $expected, $this->db->get_record( $record->id )->description );
		}
	}

	public function test_description_update_authorizes_before_normalizing_hidden_record(): void {
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'site', $this->create_test_site(), array( 'type' => 'skill' ) );
		$this->restrict_current_user_to_sites( array( $this->create_test_site() ) );
		$this->assert_not_found( $this->db->save( array( 'id' => $record->id, 'description' => '' ), MainWP_DB_Knowledge::ORIGIN_UI, 1 ), $record->id );
	}

	public function test_description_column_is_repaired_at_current_db_version(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$this->with_description_column_dropped(
			function () use ( $wpdb, $table ) {
				update_site_option( 'mainwp_knowledge_schema_repair_pending', time() - HOUR_IN_SECONDS - 1 );
				$suppressed = $wpdb->suppress_errors;
				MainWP_Install::instance()->install();
				$column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'description' ) );
				$this->assertNotNull( $column );
				$this->assertSame( $suppressed, $wpdb->suppress_errors );
				$this->assertSame( 'varchar(1024)', $column->Type );
				$this->assertSame( 'NO', $column->Null );
				$this->assertSame( '', $column->Default );
				$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
				$this->assertSame( 'description', $columns[ array_search( 'title', $columns, true ) + 1 ] );
				$this->assertSame( 'agency-required-description-1', get_site_option( 'mainwp_knowledge_schema_confirmed' ) );
				$this->assertFalse( get_site_option( 'mainwp_knowledge_schema_repair_pending' ) );
			}
		);
	}

	public function test_description_column_repair_is_quiet_and_hourly_when_alter_fails(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$this->with_description_column_dropped(
			function () use ( $wpdb, $table ) {
				$attempts = array();
				$this->track_filter(
					'query',
					static function ( $query ) use ( $wpdb, &$attempts ) {
						if ( 0 === strpos( $query, 'ALTER TABLE' ) && false !== strpos( $query, 'ADD COLUMN description' ) ) {
							$attempts[] = $wpdb->suppress_errors;
							return 'ALTER TABLE mainwp_knowledge_missing_table ADD COLUMN description int';
						}
						return $query;
					}
				);
				$install = MainWP_Install::instance();
				$suppressed = $wpdb->suppress_errors;
				$install->install();
				$this->assertSame( $suppressed, $wpdb->suppress_errors );
				$this->assertSame( array( true ), $attempts );
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'description' ) ) );
				$this->assertFalse( get_site_option( 'mainwp_knowledge_schema_confirmed' ) );
				$this->assertGreaterThanOrEqual( time() - 60, (int) get_site_option( 'mainwp_knowledge_schema_repair_pending' ) );
				$install->install();
				$this->assertCount( 1, $attempts );
				update_site_option( 'mainwp_knowledge_schema_repair_pending', time() - HOUR_IN_SECONDS - 1 );
				$install->install();
				$this->assertSame( array( true, true ), $attempts );
			}
		);
	}

	public function test_description_column_check_stops_querying_once_confirmed(): void {
		$checks = array();
		$this->track_filter(
			'query',
			static function ( $query ) use ( &$checks ) {
				if ( 0 === stripos( $query, 'SHOW COLUMNS' ) && false !== strpos( $query, 'mainwp_knowledge' ) ) {
					$checks[] = $query;
				}
				return $query;
			}
		);
		delete_site_option( 'mainwp_knowledge_schema_confirmed' );
		MainWP_Install::instance()->install();
		$this->assertCount( 1, $checks );
		$this->assertSame( 'agency-required-description-1', get_site_option( 'mainwp_knowledge_schema_confirmed' ) );
		MainWP_Install::instance()->install();
		$this->assertCount( 1, $checks );
	}

	/**
	 * Run a check against the current-version install path with the description column missing.
	 *
	 * The DDL commits the test transaction, so the column is restored and the repair markers are cleared before returning.
	 *
	 * @param callable $check Assertions to run while the column is missing.
	 */
	private function with_description_column_dropped( callable $check ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'mainwp_knowledge';
		$install = MainWP_Install::instance();
		$version = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$version->setAccessible( true );
		$this->assertSame( $version->getValue( $install ), get_site_option( 'mainwp_db_version' ) );
		delete_site_option( 'mainwp_knowledge_schema_confirmed' );
		delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'description' ) ) ) {
				$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN description', $table ) ) );
			}
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'description' ) ) );
			$check();
		} finally {
			// A query filter left in place would rewrite the restoring ALTER too.
			foreach ( $this->added_filters as $filter ) {
				remove_filter( $filter[0], $filter[1], $filter[2] );
			}
			$this->added_filters = array();
			delete_site_option( 'mainwp_knowledge_schema_confirmed' );
			delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $this->db->hook_db_install_tables( array(), get_site_option( 'mainwp_db_version' ), $wpdb->get_charset_collate() ) as $sql ) {
				dbDelta( $sql );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_validation_rejects_bad_fields_without_writing() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$before  = $this->row_count();

		$cases = array(
			'scope_type' => $this->input( 'site', $site_id, array( 'scope_type' => 'tag' ) ),
			'type'       => $this->input( 'site', $site_id, array( 'type' => 'secret' ) ),
			'empty'      => $this->input( 'site', $site_id, array( 'title' => '' ) ),
			'title'      => $this->input( 'site', $site_id, array( 'title' => str_repeat( 'a', 201 ) ) ),
			'body'       => $this->input( 'site', $site_id, array( 'body' => str_repeat( 'é', 10001 ) ) ),
			'utf8'       => $this->input( 'site', $site_id, array( 'body' => "broken \xC3 byte" ) ),
		);
		$missing_scope_id = $this->input( 'site', $site_id );
		unset( $missing_scope_id['scope_id'] );
		$cases['scope_id'] = $missing_scope_id;

		foreach ( $cases as $label => $input ) {
			$result = $this->db->save( $input, MainWP_DB_Knowledge::ORIGIN_UI );
			$this->assertWPError( $result, $label );
			$this->assertSame( 'mainwp_knowledge_invalid', $result->get_error_code(), $label );
			$this->assertSame( 400, $result->get_error_data()['status'], $label );
		}

		$this->assertSame( $before, $this->row_count() );
	}

	public function test_title_validation_preserves_plain_text() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$cases   = array(
			array( 'Keep PHP < 8', 'Keep PHP < 8' ),
			array( 'Deploy via <staging> first', 'Deploy via <staging> first' ),
			array( 'Load 80%Dashboard', 'Load 80%Dashboard' ),
			array( 'a  b', 'a  b' ),
			array( '  Trim edges  ', 'Trim edges' ),
			array( 'a', 'a' ),
			array( str_repeat( 'é', 200 ), str_repeat( 'é', 200 ) ),
			array( null, null ),
			array( array( 'title' ), null ),
			array( 123, null ),
			array( "broken \xC3 byte", null ),
			array( '', null ),
			array( '   ', null ),
			array( str_repeat( 'é', 201 ), null ),
		);
		foreach ( array_merge( range( 0, 31 ), array( 127 ) ) as $control ) {
			$cases[] = array( chr( $control ) . 'title', null );
		}
		foreach ( range( 0x80, 0x9F ) as $c1 ) {
			$cases[] = array( mb_chr( $c1, 'UTF-8' ) . 'title', null );
		}
		$cases[] = array( "Client \u{00E9} \u{2014} \u{4E2D}", "Client \u{00E9} \u{2014} \u{4E2D}" );

		foreach ( $cases as $case ) {
			$result = $this->db->save( $this->input( 'site', $site_id, array( 'title' => $case[0] ) ), MainWP_DB_Knowledge::ORIGIN_UI );
			if ( null === $case[1] ) {
				$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
				$this->assertSame( 'Invalid knowledge record field: title.', $result->get_error_message() );
			} else {
				$this->assertNotWPError( $result );
				$this->assertSame( $case[1], $result->title );
				$this->assertSame( $case[1], $this->db->get_by_id( $result->id )->title );
			}
		}
	}

	public function test_list_and_count_search_metacharacters_literally() {
		$this->set_current_user_as_admin();
		$cases = array(
			array( '%', 'anything' ),
			array( '_', 'X' ),
			array( "'", '' ),
			array( '"', '' ),
			array( '\\', '' ),
			array( '%s', 'sample' ),
			array( '%d', '7' ),
			array( '%1$s', 'sample' ),
			array( '{', '' ),
			array( '}', '' ),
		);
		foreach ( $cases as list( $term, $decoy ) ) {
			$site_id = $this->create_test_site();
			$literal = 'literal ' . $term . ' token';
			$title = $this->create_record( 'site', $site_id, array( 'title' => $literal ) );
			$body = $this->create_record( 'site', $site_id, array( 'body' => $literal ) );
			$this->assertSame( $literal, $title->title, $term );
			$this->assertSame( $literal, $body->body, $term );
			$this->create_record( 'site', $site_id, array( 'title' => 'literal ' . $decoy . ' token', 'body' => 'literal ' . $decoy . ' token' ) );
			$this->restrict_current_user_to_sites( array( $site_id ) );
			$args = array( 'scope_type' => 'site', 'scope_id' => $site_id, 'type' => 'context', 'search' => $term );
			$expected = array( $title->id, $body->id );
			$actual = $this->ids( $this->db->get_records( $args ) );
			sort( $expected );
			sort( $actual );
			$this->assertSame( $expected, $actual, $term );
			$this->assertSame( count( $expected ), $this->db->count_records( $args ), $term );
			$this->clear_filters();
		}
	}

	public function test_records_page_matches_list_and_count_with_literal_search() {
		$this->set_current_user_as_admin();
		$client = $this->create_test_client();
		$site = $this->create_test_site( array( 'client_id' => $client ) );
		$cases = array( array() );
		foreach ( array( 'site' => $site, 'client' => $client ) as $scope_type => $scope_id ) {
			$this->create_record( $scope_type, $scope_id, array( 'title' => 'Literal 50%_ token' ) );
			$this->create_record( $scope_type, $scope_id, array( 'body' => 'Literal 50%_ token' ) );
			$this->create_record( $scope_type, $scope_id, array( 'title' => 'Literal 50XX token', 'body' => 'Literal 50XX token' ) );
			$this->create_record( $scope_type, $scope_id, array( 'title' => 'Literal 50%_ token', 'type' => 'skill' ) );
			$filters = array( 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'type' => 'context', 'search' => '50%_' );
			$cases[] = $filters;
			$cases[] = $filters + array( 'limit' => 1, 'offset' => 1, 'with_body' => true );
			$cases[] = $filters + array( 'limit' => 1, 'offset' => 1, 'with_body' => false );
			$cases[] = $filters + array( 'limit' => 1, 'offset' => 20, 'with_body' => false );
		}
		foreach ( $cases as $args ) {
			$page = $this->db->get_records_page( $args );
			$this->assertSame( array( 'items', 'total' ), array_keys( $page ) );
			$this->assertSame( array_map( 'get_object_vars', $this->db->get_records( $args ) ), array_map( 'get_object_vars', $page['items'] ) );
			$this->assertSame( $this->db->count_records( $args ), $page['total'] );
			$this->assertSame( empty( $args ) ? 8 : 2, $page['total'] );
		}
	}

	public function test_update_checks_expected_revision() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );

		$editor = $this->set_current_user_as_admin();
		$update = $this->input( 'site', $site_id, array( 'id' => $record->id, 'title' => 'Edited' ) );

		$saved = $this->db->save( $update, MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $saved );
		$this->assertSame( 2, $saved->revision );
		$this->assertSame( 'Edited', $saved->title );
		$this->assertSame( $editor, $saved->updated_by );
		$this->assertSame( $record->created_by, $saved->created_by );

		$stale = $this->db->save( array_merge( $update, array( 'title' => 'Stale' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assert_error( $stale, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( 2, $stale->get_error_data()['revision'] );

		$missing = $this->db->save( array_merge( $update, array( 'title' => 'No revision' ) ), MainWP_DB_Knowledge::ORIGIN_UI );
		$this->assert_error( $missing, 'mainwp_knowledge_invalid', 400 );

		$unchanged = $this->db->get_by_id( $record->id );
		$this->assertSame( 2, $unchanged->revision );
		$this->assertSame( 'Edited', $unchanged->title );
	}

	public function test_concurrent_writer_between_check_and_update_gets_conflict() {
		global $wpdb;
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );
		$table   = $wpdb->prefix . 'mainwp_knowledge';

		// Another writer commits after save() read revision 1 but before its UPDATE runs.
		$raced = false;
		$this->track_filter(
			'query',
			static function ( $query ) use ( &$raced, $wpdb, $table, $record ) {
				if ( ! $raced && 0 === strpos( $query, 'UPDATE ' . $table ) ) {
					$raced = true;
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revision = revision + 1, title = %s WHERE id = %d", 'Other writer', $record->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				return $query;
			}
		);

		$result = $this->db->save( $this->input( 'site', $site_id, array( 'id' => $record->id, 'title' => 'Late writer' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );

		$this->assertTrue( $raced );
		$this->assert_error( $result, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( 2, $result->get_error_data()['revision'] );
		$this->assertSame( 'Other writer', $this->db->get_by_id( $record->id )->title );
	}

	public function test_concurrent_writer_between_check_and_delete_gets_conflict() {
		global $wpdb;
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'site', $this->create_test_site() );
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$raced = false;
		$this->track_filter(
			'query',
			static function ( $query ) use ( &$raced, $wpdb, $table, $record ) {
				if ( ! $raced && 0 === strpos( $query, 'DELETE FROM `' . $table . '`' ) ) {
					$raced = true;
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revision = revision + 1, title = %s, body = %s WHERE id = %d', $table, 'Other writer', 'Other content', $record->id ) );
				}
				return $query;
			}
		);

		$result = $this->db->delete( $record->id, 1 );

		$this->assertTrue( $raced );
		$this->assert_error( $result, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( 2, $result->get_error_data()['revision'] );
		$stored = $this->db->get_by_id( $record->id );
		$this->assertNotNull( $stored );
		$this->assertSame( 2, $stored->revision );
		$this->assertSame( 'Other writer', $stored->title );
		$this->assertSame( 'Other content', $stored->body );
	}

	public function test_concurrent_removal_between_check_and_delete_gets_not_found() {
		global $wpdb;
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'site', $this->create_test_site() );
		$missing = $this->db->delete( 999999, 1 );
		$this->assert_not_found( $missing, 999999 );
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$raced = false;
		$this->track_filter(
			'query',
			static function ( $query ) use ( &$raced, $wpdb, $table, $record ) {
				if ( ! $raced && 0 === strpos( $query, 'DELETE FROM `' . $table . '`' ) ) {
					$raced = true;
					$wpdb->delete( $table, array( 'id' => $record->id ), array( '%d' ) );
				}
				return $query;
			}
		);

		$result = $this->db->delete( $record->id, 1 );

		$this->assertTrue( $raced );
		$this->assert_not_found( $result, $record->id );
		$this->assertSame( $missing->get_error_code(), $result->get_error_code() );
		$this->assertSame( $missing->get_error_data()['status'], $result->get_error_data()['status'] );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
	}

	public function test_update_cannot_move_a_record_to_another_scope() {
		$this->set_current_user_as_admin();
		$site_id   = $this->create_test_site();
		$other     = $this->create_test_site();
		$client_id = $this->create_test_client();
		$record    = $this->create_record( 'site', $site_id );

		foreach ( array( $this->input( 'site', $other ), $this->input( 'client', $client_id ) ) as $target ) {
			$moved = $this->db->save( array_merge( $target, array( 'id' => $record->id ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
			$this->assert_error( $moved, 'mainwp_knowledge_invalid', 400 );
		}

		$stored = $this->db->get_by_id( $record->id );
		$this->assertSame( 'site', $stored->scope_type );
		$this->assertSame( $site_id, $stored->scope_id );
		$this->assertSame( 1, $stored->revision );
	}

	public function test_delete_removes_record() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );

		$this->assertTrue( $this->db->delete( $record->id ) );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
		$this->assert_not_found( $this->db->get_record( $record->id ), $record->id );
	}

	public function test_delete_with_stale_expected_revision_keeps_the_record() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );
		$updated = $this->db->save( array( 'id' => $record->id ) + $this->input( 'site', $site_id, array( 'title' => 'Second writer' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $updated );

		$this->assert_error( $this->db->delete( $record->id, 1 ), 'mainwp_knowledge_conflict', 409 );
		$this->assertEquals( $updated, $this->db->get_by_id( $record->id ) );
		$this->assertTrue( $this->db->delete( $record->id, $updated->revision ) );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
	}

	public function test_origin_decides_source_and_verified() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();

		$ability = $this->db->save(
			$this->input(
				'site',
				$site_id,
				array(
					'verified'   => true,
					'source'     => 'ui',
					'revision'   => 99,
					'created_by' => 12345,
				)
			),
			MainWP_DB_Knowledge::ORIGIN_ABILITY
		);
		$this->assertNotWPError( $ability );
		$this->assertSame( 'ability', $ability->source );
		$this->assertSame( 0, $ability->verified );
		$this->assertSame( 1, $ability->revision );
		$this->assertSame( get_current_user_id(), $ability->created_by );

		$ui_row  = $this->create_record( 'site', $site_id );
		$touched = $this->db->save( $this->input( 'site', $site_id, array( 'id' => $ui_row->id ) ), MainWP_DB_Knowledge::ORIGIN_ABILITY, 1 );
		$this->assertNotWPError( $touched );
		$this->assertSame( 'ability', $touched->source );
		$this->assertSame( 0, $touched->verified );

		$reviewed = $this->db->save( $this->input( 'site', $site_id, array( 'id' => $ability->id ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $reviewed );
		$this->assertSame( 'ui', $reviewed->source );
		$this->assertSame( 1, $reviewed->verified );
	}

	public function test_restricted_user_cannot_see_or_touch_out_of_scope_site_record() {
		$this->set_current_user_as_admin();
		$site_a   = $this->create_test_site();
		$site_b   = $this->create_test_site();
		$record_a = $this->create_record( 'site', $site_a, array( 'title' => 'Needle A' ) );
		$record_b = $this->create_record( 'site', $site_b, array( 'title' => 'Needle B' ) );

		$this->restrict_current_user_to_sites( array( $site_a ) );

		$this->assertSame( array( $record_a->id ), $this->ids( $this->db->get_records() ) );
		$this->assertSame( 1, $this->db->count_records() );
		$this->assertSame( array( $record_a->id ), $this->ids( $this->db->get_records( array( 'search' => 'Needle' ) ) ) );
		$this->assertSame( 0, $this->db->count_records( array( 'search' => 'Needle B' ) ) );

		$this->assert_not_found( $this->db->get_record( $record_b->id ), $record_b->id );
		$missing = $this->db->get_record( PHP_INT_MAX );
		$this->assertSame( $missing->get_error_code(), $this->db->get_record( $record_b->id )->get_error_code() );

		$update = $this->db->save( $this->input( 'site', $site_b, array( 'id' => $record_b->id ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assert_not_found( $update, $record_b->id );
		$this->assert_not_found( $this->db->delete( $record_b->id ), $record_b->id );
		$this->assertNotNull( $this->db->get_by_id( $record_b->id ) );
	}

	public function test_site_access_filter_denial_is_forbidden_for_unrestricted_and_hidden_for_restricted() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id, array( 'title' => 'Filtered needle' ) );

		$this->deny_site_access_filter();

		$this->assertNotContains( $record->id, $this->ids( $this->db->get_records() ) );
		$this->assertSame( array(), $this->db->get_records( array( 'search' => 'Filtered needle' ) ) );
		$this->assertSame( 0, $this->db->count_records( array( 'scope_type' => 'site', 'scope_id' => $site_id ) ) );
		$this->assert_error( $this->db->get_record( $record->id ), 'mainwp_access_denied', 403 );

		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->assert_not_found( $this->db->get_record( $record->id ), $record->id );
	}

	public function test_client_records_follow_any_overlap_scoping() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_a    = $this->create_test_site( array( 'client_id' => $client_id ) );
		$site_b    = $this->create_test_site( array( 'client_id' => $client_id ) );
		$site_c    = $this->create_test_site();
		$empty     = $this->create_test_client();
		$record    = $this->create_record( 'client', $client_id );
		$orphan    = $this->create_record( 'client', $empty );

		$this->restrict_current_user_to_sites( array( $site_a ) );
		$this->assertNotWPError( $this->db->get_record( $record->id ) );
		$saved = $this->db->save( $this->input( 'client', $client_id, array( 'id' => $record->id, 'title' => 'Overlap edit' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assert_error( $saved, 'mainwp_access_denied', 403 );
		$this->assert_error( $this->db->save( $this->input( 'client', $client_id ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_access_denied', 403 );
		$this->assert_error( $this->db->delete( $record->id, 1 ), 'mainwp_access_denied', 403 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		$this->assertContains( $record->id, $this->ids( $this->db->get_records() ) );

		$this->assert_not_found( $this->db->get_record( $orphan->id ), $orphan->id );
		$this->assertNotContains( $orphan->id, $this->ids( $this->db->get_records() ) );

		$this->clear_filters();
		$this->restrict_current_user_to_sites( array( $site_a, $site_b ) );
		$saved = $this->db->save( $this->input( 'client', $client_id, array( 'id' => $record->id, 'title' => 'Full coverage edit' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $saved );
		$this->assertSame( 2, $saved->revision );
		$created = $this->create_record( 'client', $client_id );
		$this->assertTrue( $this->db->delete( $created->id, 1 ) );

		$this->clear_filters();
		$this->restrict_current_user_to_sites( array( $site_c ) );
		$this->assert_not_found( $this->db->get_record( $record->id ), $record->id );
		$this->assertSame( array(), $this->db->get_records( array( 'scope_type' => 'client' ) ) );
		$this->assertSame( 0, $this->db->count_records( array( 'scope_type' => 'client' ) ) );
	}

	public function test_knowledge_encoding_validation_accepts_utf8_and_rejects_invalid_bytes() {
		update_option( 'blog_charset', 'ISO-8859-1' );
		$input = $this->input( 'client', 1, array( 'title' => 'Café 🍵', 'body' => "日本語\nRésumé" ) );
		$this->assertEquals( $input + array( 'description' => '', 'caller_note' => '', 'required' => 0 ), $this->db->validate_input( $input ) );
		foreach ( array( 'title', 'body' ) as $field ) {
			$invalid = $input;
			$invalid[ $field ] = "Invalid \xC3\x28";
			$result = $this->db->validate_input( $invalid );
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'Invalid knowledge record field: ' . $field . '.', $result->get_error_message() );
		}
	}

	public function test_knowledge_utf8_fallback_without_mbstring_checker() {
		$script = <<<'PHP'
define( 'ABSPATH', '/' );
require $argv[1] . '/class/class-mainwp-db-base.php';
require $argv[1] . '/class/class-mainwp-db.php';
require $argv[1] . '/class/class-mainwp-db-knowledge.php';
$class = new ReflectionClass( MainWP\Dashboard\MainWP_DB_Knowledge::class );
$db = $class->newInstanceWithoutConstructor();
$method = $class->getMethod( 'is_valid_utf8' );
$method->setAccessible( true );
echo json_encode( array(
    function_exists( 'mb_check_encoding' ),
    $method->invoke( $db, 'Café 日本語 🍵' ),
    $method->invoke( $db, "\xC3\x28" ),
    $method->invoke( $db, "\xED\xA0\x80" ),
) );
PHP;
		$output = array();
		$exit = null;
		exec( escapeshellarg( PHP_BINARY ) . ' -d disable_functions=mb_check_encoding -r ' . escapeshellarg( $script ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) ) . ' 2>&1', $output, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $output ) );
		$this->assertSame( array( false, true, false, false ), json_decode( implode( "\n", $output ), true ) );
	}

	public function test_unrestricted_admin_lists_client_records_and_non_admin_lists_nothing() {
		$this->set_current_user_as_admin();
		$site_id    = $this->create_test_site();
		$client_id  = $this->create_test_client();
		$site_rec   = $this->create_record( 'site', $site_id );
		$client_rec = $this->create_record( 'client', $client_id );

		$this->assertContains( $client_rec->id, $this->ids( $this->db->get_records() ) );
		$this->assertSame( 1, $this->db->count_records( array( 'scope_type' => 'client' ) ) );

		// Team Control rights default to allowed, so only the base permission check keeps a subscriber out.
		$this->set_current_user_as_subscriber();
		$this->assertSame( '1=0', $this->db->scope_where_sql() );
		$this->assertSame( array(), $this->db->get_records() );
		$this->assertSame( 0, $this->db->count_records() );
		$this->assertNotNull( $this->db->get_by_id( $site_rec->id ) );
	}

	public function test_missing_rights_are_forbidden() {
		$this->set_current_user_as_admin();
		$site_id     = $this->create_test_site();
		$client_id   = $this->create_test_client();
		$site_rec    = $this->create_record( 'site', $site_id );
		$client_rec  = $this->create_record( 'client', $client_id );
		$site_update = $this->input( 'site', $site_id, array( 'id' => $site_rec->id ) );

		$this->withhold_right( 'access_individual_dashboard' );
		$this->assert_error( $this->db->get_record( $site_rec->id ), 'mainwp_access_denied', 403 );
		$this->assertNotContains( $site_rec->id, $this->ids( $this->db->get_records() ) );
		$this->assertSame( 0, $this->db->count_records( array( 'scope_type' => 'site', 'scope_id' => $site_id ) ) );
		$this->clear_filters();

		$this->withhold_right( 'manage_clients' );
		$this->assert_error( $this->db->get_record( $client_rec->id ), 'mainwp_access_denied', 403 );
		$this->assertNotContains( $client_rec->id, $this->ids( $this->db->get_records() ) );
		$this->assertSame( 0, $this->db->count_records( array( 'scope_type' => 'client', 'scope_id' => $client_id ) ) );
		$this->assert_error(
			$this->db->save( $this->input( 'client', $client_id, array( 'id' => $client_rec->id ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 ),
			'mainwp_access_denied',
			403
		);
		$this->assert_error( $this->db->save( $this->input( 'client', $client_id ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_access_denied', 403 );
		$this->assert_error( $this->db->delete( $client_rec->id ), 'mainwp_access_denied', 403 );
		$this->clear_filters();

		$this->withhold_right( 'edit_sites' );
		$this->assert_error( $this->db->save( $site_update, MainWP_DB_Knowledge::ORIGIN_UI, 1 ), 'mainwp_access_denied', 403 );
		$this->assert_error( $this->db->save( $this->input( 'site', $site_id ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_access_denied', 403 );
		$this->assert_error( $this->db->delete( $site_rec->id ), 'mainwp_access_denied', 403 );
		$this->assertNotWPError( $this->db->get_record( $site_rec->id ) );
		$this->assertSame( 1, $this->db->get_by_id( $site_rec->id )->revision );
	}

	public function test_empty_visible_scope_and_userless_machine_context_fail_closed() {
		$this->set_current_user_as_admin();
		$site_id    = $this->create_test_site();
		$client_id  = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $client_id ) );
		$site_rec   = $this->create_record( 'site', $site_id );
		$client_rec = $this->create_record( 'client', $client_id );
		$bare_site  = $this->create_test_site();

		$this->restrict_current_user_to_sites( array() );
		$this->assertStringContainsString( "scope_type = 'agency'", $this->db->scope_where_sql() );
		$this->assertSame( array(), $this->db->get_records() );
		$this->assertSame( 0, $this->db->count_records() );
		$this->clear_filters();

		$this->restrict_current_user_to_sites( array( $bare_site ) );
		$this->assertSame( array(), $this->db->get_records() );
		$this->assertSame( 0, $this->db->count_records() );
		$this->clear_filters();

		$this->restrict_current_user_to_sites( array() );
		$this->set_machine_context_override( true );
		wp_set_current_user( 0 );
		$this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_site_ids_or_all() );
		$base = MainWP_Abilities_Util::check_rest_api_permission();
		$this->assertWPError( $base );
		$this->assertSame( array(), $this->db->get_records() );
		$this->assertSame( 0, $this->db->count_records() );
	}

	public function test_site_and_client_deletion_remove_their_records() {
		$this->set_current_user_as_admin();
		$site_id    = $this->create_test_site();
		$other_site = $this->create_test_site();
		$client_id  = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $client_id ) );
		$doomed     = $this->create_record( 'site', $site_id );
		$kept       = $this->create_record( 'site', $other_site );
		$client_rec = $this->create_record( 'client', $client_id );

		MainWP_DB::instance()->remove_website( $site_id );
		$this->assertNull( $this->db->get_by_id( $doomed->id ) );
		$this->assertNotNull( $this->db->get_by_id( $kept->id ) );

		$listener_ran     = false;
		$seen_in_listener = null;
		$listener         = function () use ( $client_rec, &$seen_in_listener, &$listener_ran ) {
			$listener_ran     = true;
			$seen_in_listener = $this->db->get_by_id( $client_rec->id );
		};
		add_action( 'mainwp_client_deleted', $listener );
		$this->added_filters[] = array( 'mainwp_client_deleted', $listener, 10 );

		$this->assertTrue( MainWP_DB_Client::instance()->delete_client( $client_id ) );
		$this->assertTrue( $listener_ran );
		$this->assertNull( $seen_in_listener );
		$this->assertNull( $this->db->get_by_id( $client_rec->id ) );
		$this->assertNotNull( $this->db->get_by_id( $kept->id ) );
	}

	public function test_knowledge_db_failure_roster_read_is_distinct_from_empty_roster() {
		global $wpdb;
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$empty_client = $this->create_test_client();
		$roster = new \ReflectionMethod( MainWP_Abilities_Util::class, 'get_client_site_ids' );
		$roster->setAccessible( true );
		$this->assertSame( array(), $roster->invoke( null, $empty_client ) );
		$this->assertSame( array( $site_id ), array_values( $roster->invoke( null, $client_id ) ) );

		$this->fail_client_roster_reads();
		$wpdb->last_error = '';
		$this->assertSame( array(), MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id, array( 'no_perm_check' => true ) ) );
		$this->assertNotSame( 0, mysqli_errno( $wpdb->dbh ) );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertFalse( $roster->invoke( null, $client_id ) );
		$this->assertNotSame( '', $wpdb->last_error );
	}

	public static function knowledge_roster_read_operations(): array {
		return array(
			'scopes' => array( 'visible_scopes' ),
			'page' => array( 'get_records_page' ),
			'list' => array( 'get_records' ),
			'count' => array( 'count_records' ),
			'sql' => array( 'scope_where_sql' ),
		);
	}

	/** @dataProvider knowledge_roster_read_operations */
	public function test_knowledge_db_failure_roster_read_never_returns_partial_scopes( string $method ) {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_record( 'site', $site_id );
		$this->create_record( 'client', $client_id );
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->assertSame( array( $client_id ), $this->db->visible_scopes()['clients'] );
		$this->fail_client_roster_reads();

		$result = $this->db->$method();
		if ( 'scope_where_sql' === $method ) {
			$this->assertSame( '1=0', $result );
		} else {
			$this->assert_error( $result, 'mainwp_knowledge_db_error', 500 );
			$this->assertSame( 'Knowledge could not be read from the database. Try again.', $result->get_error_message() );
		}
	}

	public function test_knowledge_db_failure_roster_read_reaches_list_ability() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_record( 'site', $site_id );
		$this->create_record( 'client', $client_id );
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->fail_client_roster_reads();

		$this->assert_error( $this->execute_ability( 'mainwp/list-knowledge-v1' ), 'mainwp_knowledge_db_error', 500 );
	}

	public static function knowledge_client_record_reads(): array {
		return array(
			'read access' => array( 'can_read' ),
			'write access' => array( 'can_write' ),
			'scope records' => array( 'get_scope_records' ),
			'single record' => array( 'get_record' ),
		);
	}

	/** @dataProvider knowledge_client_record_reads */
	public function test_knowledge_db_failure_client_record_access_returns_roster_error( string $method ) {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$record = $this->create_record( 'client', $client_id );
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->assertTrue( $this->db->can_read( $record ) );
		$this->assertTrue( $this->db->can_write( $record ) );
		$this->fail_client_roster_reads();

		if ( 'get_scope_records' === $method ) {
			$result = $this->db->get_scope_records( 'client', $client_id );
		} elseif ( 'get_record' === $method ) {
			$result = $this->db->get_record( $record->id );
		} else {
			$result = $this->db->$method( $record );
		}
		$this->assert_error( $result, 'mainwp_knowledge_db_error', 500 );
	}

	public static function knowledge_client_record_abilities(): array {
		return array(
			'client summary' => array( 'mainwp/get-client-knowledge-v1', 'execute_get_client_knowledge', 'client_id' ),
			'single record' => array( 'mainwp/get-knowledge-record-v1', 'execute_get_knowledge_record', 'record_id' ),
		);
	}

	/** @dataProvider knowledge_client_record_abilities */
	public function test_knowledge_db_failure_client_record_abilities_return_roster_error( string $ability_name, string $method, string $parameter ) {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$record = $this->create_record( 'client', $client_id );
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$input = array( $parameter => 'client_id' === $parameter ? $client_id : $record->id );
		$this->fail_client_roster_reads();

		$this->assert_error( wp_get_ability( $ability_name )->check_permissions( $input ), 'mainwp_knowledge_db_error', 500 );
		$this->assert_error( MainWP_Abilities_Knowledge::$method( $input ), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_knowledge_db_failure_control_keeps_non_knowledge_client_denial() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->restrict_current_user_to_sites( array( $site_id ) );
		$this->fail_client_roster_reads();

		foreach ( array( false, true ) as $full_coverage ) {
			$this->assert_error( MainWP_Abilities_Util::check_client_access( $client_id, null, $full_coverage ), 'mainwp_client_not_found', 404 );
		}
	}

	public function test_knowledge_db_failure_control_unrestricted_caller_never_reads_roster() {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_record( 'site', $site_id );
		$this->create_record( 'client', $client_id );
		$scopes = $this->db->visible_scopes();
		$page = wp_json_encode( $this->db->get_records_page() );
		$roster_queries = 0;
		$this->track_filter( 'mainwp_get_sql_websites', static function ( $params ) use ( &$roster_queries ) {
			++$roster_queries;
			return $params;
		} );
		$this->fail_client_roster_reads();

		$this->assertSame( 'all', MainWP_Abilities_Util::get_allowed_site_ids() );
		$this->assertTrue( MainWP_Abilities_Util::check_client_access( $client_id ) );
		$this->assertSame( $scopes, $this->db->visible_scopes() );
		$this->assertSame( $page, wp_json_encode( $this->db->get_records_page() ) );
		$this->assertSame( 0, $roster_queries );
	}

	public static function knowledge_deletion_scopes(): array {
		return array( 'site' => array( 'site' ), 'client' => array( 'client' ) );
	}

	/** @dataProvider knowledge_deletion_scopes */
	public function test_knowledge_db_failure_scope_delete_reports_success_including_no_rows( string $scope_type ) {
		$this->set_current_user_as_admin();
		$scope_id = 'site' === $scope_type ? $this->create_test_site() : $this->create_test_client();
		$record = $this->create_record( $scope_type, $scope_id );
		$kept = $this->create_record( 'site', $this->create_test_site() );

		$this->assertTrue( $this->db->delete_by_scope( $scope_type, $scope_id ) );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
		$this->assertNotNull( $this->db->get_by_id( $kept->id ) );
		$this->assertTrue( $this->db->delete_by_scope( $scope_type, $scope_id ) );
	}

	/** @dataProvider knowledge_deletion_scopes */
	public function test_knowledge_db_failure_scope_delete_returns_db_error( string $scope_type ) {
		$this->set_current_user_as_admin();
		$scope_id = 'site' === $scope_type ? $this->create_test_site() : $this->create_test_client();
		$record = $this->create_record( $scope_type, $scope_id );
		$this->fail_knowledge_deletes();

		$this->assert_error( $this->db->delete_by_scope( $scope_type, $scope_id ), 'mainwp_knowledge_db_error', 500 );
		$this->assertNotNull( $this->db->get_by_id( $record->id ) );
	}

	/** @dataProvider knowledge_deletion_scopes */
	public function test_knowledge_db_failure_parent_delete_proceeds_and_reports_cleanup_failure( string $scope_type ) {
		global $wpdb;
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id = $this->create_test_site( array( 'client_id' => $client_id ) );
		$scope_id = 'site' === $scope_type ? $site_id : $client_id;
		$record = $this->create_record( $scope_type, $scope_id );
		$listener_ran = false;
		$this->track_filter( 'mainwp_client_deleted', static function () use ( &$listener_ran ) { $listener_ran = true; } );
		$this->fail_knowledge_deletes();

		$logger = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( MainWP_Logger::class, 'logPriority' );
		$specific = new \ReflectionProperty( MainWP_Logger::class, 'logSpecific' );
		$priority->setAccessible( true );
		$specific->setAccessible( true );
		$old_priority = $priority->getValue( $logger );
		$old_specific = $specific->getValue( $logger );
		$logger->set_log_priority( MainWP_Logger::WARNING );
		$warnings = array();
		$this->track_filter( 'mainwp_logger_to_db', '__return_true' );
		$this->track_filter( 'mainwp_before_log_data', static function ( $text, $log_priority ) use ( &$warnings ) {
			if ( MainWP_Logger::WARNING === $log_priority ) {
				$warnings[] = $text;
			}
		}, 10, 2 );
		try {
			$result = 'site' === $scope_type
				? MainWP_DB::instance()->remove_website( $site_id )
				: MainWP_DB_Client::instance()->delete_client( $client_id );
		} finally {
			$logger->set_log_priority( $old_priority, $old_specific );
		}

		if ( 'site' === $scope_type ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ) ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT wpid FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid = %d", $site_id ) ) );
		} else {
			$this->assertNull( MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id ) );
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ) ) );
			$this->assertTrue( $listener_ran );
		}
		$this->assertNotNull( $this->db->get_by_id( $record->id ) );
		$this->assertSame( 'site' === $scope_type ? 1 : true, $result );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( $scope_type . ' ' . $scope_id, $warnings[0] );
		$this->assertStringContainsString( 'The knowledge record could not be written to the database.', $warnings[0] );
	}

	public function test_knowledge_db_failure_delete_site_ability_still_succeeds_and_fires_hooks() {
		global $wpdb;
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record = $this->create_record( 'site', $site_id );
		$fired = array();
		foreach ( array( 'mainwp_delete_site', 'mainwp_site_deleted' ) as $hook ) {
			$this->track_filter( $hook, static function ( $site ) use ( $hook, &$fired ) {
				$fired[] = array( $hook, (int) $site->id );
			} );
		}
		$this->fail_knowledge_deletes();

		$result = $this->execute_ability( 'mainwp/delete-site-v1', array( 'site_id_or_domain' => $site_id, 'confirm' => true ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $site_id, $result['site']['id'] );
		$this->assertSame( array( array( 'mainwp_delete_site', $site_id ), array( 'mainwp_site_deleted', $site_id ) ), $fired );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ) ) );
		$this->assertNotNull( $this->db->get_by_id( $record->id ) );
	}

	/**
	 * Drop this test's filters mid-test so the next case starts unfiltered.
	 */
	private function clear_filters(): void {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
	}

	public function test_client_without_synced_sites_is_hidden_from_list_and_count_like_single_reads() {
		global $wpdb;
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id   = $this->create_test_site( array( 'client_id' => $client_id ) );
		$record    = $this->create_record( 'client', $client_id );

		// A site row whose sync insert failed: the client contract resolves sites through the wp_sync join and denies the client, so the list and count must not see it either.
		$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ) );
		$this->restrict_current_user_to_sites( array( $site_id ) );

		$this->assertWPError( MainWP_Abilities_Util::check_client_access( $client_id ) );
		$this->assertSame( array(), $this->db->visible_scopes()['clients'] );
		$this->assertNotContains( $record->id, $this->ids( $this->db->get_records() ) );
		$this->assertSame( 0, $this->db->count_records( array( 'scope_type' => 'client' ) ) );
	}

	public function test_install_recreates_missing_knowledge_table_at_current_db_version() {
		global $wpdb;
		$table   = $wpdb->prefix . 'mainwp_knowledge';
		$install = MainWP_Install::instance();
		$version = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$version->setAccessible( true );
		$this->assertSame( $version->getValue( $install ), get_site_option( 'mainwp_db_version' ), 'the early-return path is the one under test' );

		// Real DDL: the temporary-table rewrite would hide the recreated table from SHOW TABLES, which is what install() keys on.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

			$install->install();

			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
			$this->assertSame( 'PRIMARY', $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), 2 ) );
			$this->assertFalse( $install->maybe_create_knowledge_table( get_site_option( 'mainwp_db_version' ) ) );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_records_page_answers_db_error_when_the_knowledge_select_fails() {
		$this->set_current_user_as_admin();
		$this->create_record( 'site', $this->create_test_site() );
		$this->fail_selects_on( 'mainwp_knowledge' );

		$this->assert_error( $this->db->get_records_page(), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_list_and_count_answer_db_error_when_the_knowledge_select_fails() {
		$this->set_current_user_as_admin();
		$this->create_record( 'site', $this->create_test_site() );
		$this->fail_selects_on( 'mainwp_knowledge' );

		$this->assert_error( $this->db->get_records(), 'mainwp_knowledge_db_error', 500 );
		$this->assert_error( $this->db->count_records(), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_scope_records_answer_db_error_when_the_knowledge_select_fails() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->create_record( 'site', $site_id );
		$this->fail_selects_on( 'mainwp_knowledge' );

		$this->assert_error( $this->db->get_scope_records( 'site', $site_id ), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_scope_records_answer_db_error_when_the_site_existence_lookup_fails() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->create_record( 'site', $site_id );
		$this->fail_selects_on( 'mainwp_wp' );

		$this->assert_error( $this->db->get_scope_records( 'site', $site_id ), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_record_read_answers_db_error_not_not_found_when_the_knowledge_select_fails() {
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'site', $this->create_test_site() );
		$this->fail_selects_on( 'mainwp_knowledge' );

		$this->assert_error( $this->db->get_record( $record->id ), 'mainwp_knowledge_db_error', 500 );
		$this->assert_error( $this->db->get_by_id( $record->id ), 'mainwp_knowledge_db_error', 500 );
	}

	public static function scope_lookup_tables(): array {
		return array(
			'site lookup'   => array( 'mainwp_wp' ),
			'client lookup' => array( 'mainwp_wp_clients' ),
		);
	}

	/** @dataProvider scope_lookup_tables */
	public function test_visible_scopes_answer_db_error_and_fail_closed_when_a_scope_lookup_fails( string $table ) {
		$this->set_current_user_as_admin();
		$client_id = $this->create_test_client();
		$site_id   = $this->create_test_site( array( 'client_id' => $client_id ) );
		$this->create_record( 'site', $site_id );
		$this->create_record( 'client', $client_id );
		$this->fail_selects_on( $table );

		$this->assert_error( $this->db->visible_scopes(), 'mainwp_knowledge_db_error', 500 );
		$this->assert_error( $this->db->get_records_page(), 'mainwp_knowledge_db_error', 500 );
		// The lookup that still works must not leave its half of the scope open.
		$this->assertSame( '1=0', $this->db->scope_where_sql() );
	}

	public function test_update_and_delete_answer_db_error_when_the_knowledge_select_fails() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );
		$this->fail_selects_on( 'mainwp_knowledge' );

		$update = $this->db->save( $this->input( 'site', $site_id, array( 'id' => $record->id, 'title' => 'Edited' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assert_error( $update, 'mainwp_knowledge_db_error', 500 );
		$this->assert_error( $this->db->delete( $record->id, 1 ), 'mainwp_knowledge_db_error', 500 );
		$this->clear_filters();
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_create_answers_db_error_and_inserts_nothing_when_the_site_lookup_fails() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$this->create_record( 'site', $site_id );
		$before = $this->row_count();
		$this->fail_selects_on( 'mainwp_wp' );

		$this->assert_error( $this->db->save( $this->input( 'site', $site_id ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_knowledge_db_error', 500 );
		$this->clear_filters();
		$this->assertSame( $before, $this->row_count() );
	}

	/**
	 * Fail knowledge SELECTs only once an INSERT or UPDATE on the table has run, so the write lands and its re-read fails.
	 */
	private function fail_knowledge_selects_after_a_write(): void {
		global $wpdb;
		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
		$name    = $wpdb->prefix . 'mainwp_knowledge';
		$pattern = '/(?<![A-Za-z0-9_])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_])/';
		$written = false;
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $pattern, $name, &$written ) {
				$head = ltrim( $sql );
				if ( ! $written && ( 0 === stripos( $head, 'INSERT' ) || 0 === stripos( $head, 'UPDATE' ) ) && preg_match( $pattern, $sql ) ) {
					$written = true;
					return $sql;
				}
				if ( ! $written || 0 !== stripos( $head, 'SELECT' ) ) {
					return $sql;
				}
				return preg_replace( $pattern, $name . '_missing', $sql );
			}
		);
	}

	public function test_create_whose_re_read_fails_answers_the_written_record_not_an_error() {
		$user_id = $this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$before  = $this->row_count();
		$this->fail_knowledge_selects_after_a_write();

		// An error here would leave the panel's Add form open, and a second Save would create the record twice.
		$record = $this->db->save( $this->input( 'site', $site_id, array( 'caller_note' => 'note' ) ), MainWP_DB_Knowledge::ORIGIN_UI );
		$this->assertNotWPError( $record );
		$this->clear_filters();

		$this->assertSame( $before + 1, $this->row_count() );
		$this->assertSame( $user_id, $record->created_by );
		$this->assertEquals( $this->db->get_by_id( $record->id ), $record );
	}

	public function test_update_whose_re_read_fails_answers_the_written_record_not_an_error() {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record  = $this->create_record( 'site', $site_id );
		$this->fail_knowledge_selects_after_a_write();

		// Only the post-UPDATE read fails; the pre-UPDATE read that loads the row must still work.
		$updated = $this->db->save( $this->input( 'site', $site_id, array( 'id' => $record->id, 'title' => 'Edited' ) ), MainWP_DB_Knowledge::ORIGIN_ABILITY, 1 );
		$this->assertNotWPError( $updated );
		$this->clear_filters();

		$this->assertSame( 2, $updated->revision );
		$this->assertSame( 'Edited', $updated->title );
		$this->assertEquals( $this->db->get_by_id( $record->id ), $updated );
	}

	public function test_agency_db_storage_required_round_trip_and_rights(): void {
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'agency', 0, array( 'required' => true ) );
		$this->assertSame( 0, $record->scope_id );
		$this->assertSame( 1, $this->db->get_by_id( $record->id )->required );
		$this->assertSame( 1, $record->verified );
		$cleared = $this->db->save( array( 'id' => $record->id, 'required' => false ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $cleared );
		$this->assertSame( 0, $this->db->get_by_id( $record->id )->required );
		$this->assertSame( 2, $cleared->revision );
		$this->restrict_current_user_to_sites( array() );
		$this->withhold_right( 'manage_clients' );
		$this->withhold_right( 'access_individual_dashboard' );
		$this->assertSame( $record->id, $this->db->get_record( $record->id )->id );
		$this->assertSame( array( $record->id ), $this->ids( $this->db->get_records( array( 'scope_type' => 'agency', 'with_body' => false ) ) ) );
		$this->assertSame( 1, $this->db->count_records( array( 'scope_type' => 'agency' ) ) );
		$this->assert_error( $this->db->save( $this->input( 'agency', 0 ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_restricted_account', 403 );
		$this->assert_error( $this->db->save( array( 'id' => $record->id, 'required' => true ), MainWP_DB_Knowledge::ORIGIN_UI, 2 ), 'mainwp_restricted_account', 403 );
		$this->assertSame( 0, $this->db->get_by_id( $record->id )->required );
		$this->clear_filters();
		$this->assertTrue( $this->db->delete( $record->id, 2, MainWP_DB_Knowledge::ORIGIN_UI ) );
	}

	public function test_rows_read_before_the_required_column_exists_are_not_required(): void {
		$normalize = new \ReflectionMethod( MainWP_DB_Knowledge::class, 'normalize_row' );
		$normalize->setAccessible( true );
		$row = $normalize->invoke( $this->db, (object) array( 'id' => '7', 'scope_type' => 'agency', 'scope_id' => '0', 'verified' => '1' ) );
		$this->assertSame( 0, $row->required );
		$this->assertSame( '', $row->description );
		$this->assertSame( 1, $normalize->invoke( $this->db, (object) array( 'required' => '1' ) )->required );
	}

	public function test_agency_db_writes_require_manage_clients_with_all_site_access(): void {
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'agency', 0 );
		$this->track_filter( 'mainwp_currentuserallowedaccesssites', static function () { return 'all'; } );
		$this->withhold_right( 'manage_clients' );
		$this->assertTrue( MainWP_Abilities_Util::check_unrestricted_access() );
		foreach ( array( MainWP_DB_Knowledge::ORIGIN_UI, MainWP_DB_Knowledge::ORIGIN_ABILITY ) as $origin ) {
			$this->assert_error( $this->db->save( $this->input( 'agency', 0 ), $origin ), 'mainwp_access_denied', 403 );
			$this->assert_error( $this->db->save( array( 'id' => $record->id, 'title' => 'Changed' ), $origin, 1 ), 'mainwp_access_denied', 403 );
			$this->assert_error( $this->db->delete( $record->id, 1, $origin ), 'mainwp_access_denied', 403 );
		}
		$this->assert_error( $this->db->save( array( 'id' => $record->id, 'required' => true ), MainWP_DB_Knowledge::ORIGIN_UI, 1 ), 'mainwp_access_denied', 403 );
		$this->assertEquals( $record, $this->db->get_record( $record->id ) );
		$this->assertSame( array( $record->id ), $this->ids( $this->db->get_records( array( 'scope_type' => 'agency' ) ) ) );
		$this->assertSame( 1, $this->db->count_records( array( 'scope_type' => 'agency' ) ) );
	}

	public function test_required_db_ui_false_does_not_require_unrestricted_access(): void {
		$this->set_current_user_as_admin();
		$site_id = $this->create_test_site();
		$record = $this->create_record( 'site', $site_id );
		$this->create_test_site();
		$this->restrict_current_user_to_sites( array( $site_id ) );
		foreach ( array( false, 0, '0' ) as $required ) {
			$created = $this->db->save( $this->input( 'site', $site_id, array( 'required' => $required ) ), MainWP_DB_Knowledge::ORIGIN_UI );
			$this->assertNotWPError( $created );
			$this->assertSame( 0, $created->required );
			$updated = $this->db->save( array( 'id' => $record->id, 'title' => 'Changed', 'required' => $required ), MainWP_DB_Knowledge::ORIGIN_UI, $record->revision );
			$this->assertNotWPError( $updated );
			$this->assertSame( 0, $updated->required );
			$this->assertSame( 'Changed', $updated->title );
			$record = $updated;
		}
		foreach ( array( true, 1, '1' ) as $required ) {
			$this->assert_error( $this->db->save( $this->input( 'site', $site_id, array( 'required' => $required ) ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_restricted_account', 403 );
			$this->assert_error( $this->db->save( array( 'id' => $record->id, 'required' => $required ), MainWP_DB_Knowledge::ORIGIN_UI, $record->revision ), 'mainwp_restricted_account', 403 );
		}
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public static function required_invalid_scopes(): array {
		return array( 'site' => array( 'site', 'context' ), 'client' => array( 'client', 'skill' ), 'memory' => array( 'agency', 'memory' ) );
	}

	/** @dataProvider required_invalid_scopes */
	public function test_required_db_rejects_ineligible_records( string $scope, string $type ): void {
		$this->set_current_user_as_admin();
		$id = 'site' === $scope ? $this->create_test_site() : ( 'client' === $scope ? $this->create_test_client() : 0 );
		$input = $this->input( $scope, $id, array( 'type' => $type, 'required' => true ) );
		if ( 'skill' === $type ) {
			$input['description'] = 'Use this skill for maintenance.';
		}
		$result = $this->db->save( $input, MainWP_DB_Knowledge::ORIGIN_UI );
		$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( 'Only agency context and agency skills can be required.', $result->get_error_message() );
		$this->assertSame( 0, $this->row_count() );
	}

	public function test_agency_db_scope_id_validation_and_invalid_scopes(): void {
		$this->set_current_user_as_admin();
		foreach ( array( 0, 1, null ) as $id ) {
			$result = $this->db->save( $this->input( 'agency', 0, array( 'scope_id' => $id ) ), MainWP_DB_Knowledge::ORIGIN_UI );
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'Agency records apply to every site, so leave out scope_id.', $result->get_error_message() );
		}
		foreach ( array( 'site', 'client' ) as $scope ) {
			$input = $this->input( $scope, 1 );
			unset( $input['scope_id'] );
			$result = $this->db->save( $input, MainWP_DB_Knowledge::ORIGIN_UI );
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'Site and client records need a scope_id, the site or client the record belongs to.', $result->get_error_message() );
		}
		foreach ( array( 'global', 'tag' ) as $scope ) {
			$this->assert_error( $this->db->save( $this->input( $scope, 1 ), MainWP_DB_Knowledge::ORIGIN_UI ), 'mainwp_knowledge_invalid', 400 );
		}
	}

	public function test_required_db_ability_input_and_stored_row_refusals(): void {
		$this->set_current_user_as_admin();
		foreach ( array( true, false ) as $required ) {
			$result = $this->db->save( $this->input( 'agency', 0, array( 'required' => $required ) ), MainWP_DB_Knowledge::ORIGIN_ABILITY );
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'Leave out required. Only a person using the Dashboard can mark a record as required.', $result->get_error_message() );
		}
		$record = $this->create_record( 'agency', 0, array( 'required' => true ) );
		foreach ( array( $this->db->save( array( 'id' => $record->id, 'title' => 'Changed' ), MainWP_DB_Knowledge::ORIGIN_ABILITY, 1 ), $this->db->delete( $record->id, 1 ) ) as $result ) {
			$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 'This is a required agency record. Only a person using the Dashboard can change or delete it.', $result->get_error_message() );
		}
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public static function required_write_operations(): array {
		return array( 'update' => array( 'update' ), 'delete' => array( 'delete' ) );
	}

	/** @dataProvider required_write_operations */
	public function test_required_db_rechecks_row_after_authorization( string $operation ): void {
		$this->set_current_user_as_admin();
		$record = $this->create_record( 'agency', 0 );
		$this->assertTrue( $this->db->can_write( $record ) );
		$this->assertNotWPError( $this->db->save( array( 'id' => $record->id, 'required' => true ), MainWP_DB_Knowledge::ORIGIN_UI, 1 ) );
		$result = 'update' === $operation ? $this->db->save( array( 'id' => $record->id, 'title' => 'Late edit' ), MainWP_DB_Knowledge::ORIGIN_ABILITY, 1 ) : $this->db->delete( $record->id, 1 );
		$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( 1, $this->db->get_by_id( $record->id )->required );
	}

	/** @dataProvider required_write_operations */
	public function test_required_db_atomic_guard_when_row_changes_before_sql( string $operation ): void {
		$this->set_current_user_as_admin();
		global $wpdb;
		$record = $this->create_record( 'agency', 0 );
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$raced = false;
		$this->track_filter( 'query', static function ( $sql ) use ( $wpdb, $table, $record, $operation, &$raced ) {
			$prefix = 'update' === $operation ? 'UPDATE ' . $table : 'DELETE FROM `' . $table . '`';
			if ( ! $raced && 0 === strpos( $sql, $prefix ) ) {
				$raced = true;
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET required = 1, verified = 1 WHERE id = %d', $table, $record->id ) );
			}
			return $sql;
		} );
		$result = 'update' === $operation ? $this->db->save( array( 'id' => $record->id, 'title' => 'Late edit' ), MainWP_DB_Knowledge::ORIGIN_ABILITY, 1 ) : $this->db->delete( $record->id );
		$this->assertTrue( $raced );
		$this->assert_error( $result, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( 1, $this->db->get_by_id( $record->id )->required );
		$this->assertSame( $record->title, $this->db->get_by_id( $record->id )->title );
	}

	public function test_verified_db_filter_agrees_for_list_count_and_page(): void {
		$this->set_current_user_as_admin();
		$verified = $this->create_record( 'site', $this->create_test_site() );
		$unverified = $this->db->save( $this->input( 'site', $verified->scope_id ), MainWP_DB_Knowledge::ORIGIN_ABILITY );
		$this->assertNotWPError( $unverified );
		foreach ( array( 1 => $verified, 0 => $unverified ) as $flag => $record ) {
			$args = array( 'verified' => (bool) $flag, 'with_body' => false );
			$this->assertSame( array( $record->id ), $this->ids( $this->db->get_records( $args ) ) );
			$this->assertSame( 1, $this->db->count_records( $args ) );
			$page = $this->db->get_records_page( $args );
			$this->assertSame( array( $record->id ), $this->ids( $page['items'] ) );
			$this->assertSame( 1, $page['total'] );
		}
	}

	public static function knowledge_schema_missing_components(): array {
		$cases = array();
		foreach ( array( '9.0.2.6', '9.0.2.7', '9.0.2.8', '9.0.2.27' ) as $version ) {
			foreach ( array( 'agency', 'required', 'description' ) as $component ) {
				$cases[ $version . ' missing ' . $component ] = array( $version, $component );
			}
		}
		return $cases;
	}

	/** @dataProvider knowledge_schema_missing_components */
	public function test_knowledge_schema_repair_by_inspection_preserves_rows( string $version, string $component ): void {
		$this->set_current_user_as_admin();
		global $wpdb;
		$table = $wpdb->prefix . 'mainwp_knowledge';
		$site = $this->create_record( 'site', $this->create_test_site() );
		$client = $this->create_record( 'client', $this->create_test_client() );
		$prior = get_site_option( 'mainwp_db_version' );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			delete_site_option( 'mainwp_knowledge_schema_confirmed' );
			delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
			update_site_option( 'mainwp_knowledge_description_column_confirmed', 1 );
			update_site_option( 'mainwp_knowledge_description_column_repair_pending', time() );
			if ( 'agency' === $component ) {
				$this->assertNotFalse( $wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY scope_type enum('site','client') NOT NULL", $table ) ) );
			} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $component ) ) ) {
				$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $component ) ) );
			}
			update_site_option( 'mainwp_db_version', $version );
			MainWP_Install::instance()->install();
			$this->assertSame( '9.0.2.8', get_site_option( 'mainwp_db_version' ) );
			$this->assertFalse( get_site_option( 'mainwp_knowledge_description_column_confirmed' ) );
			$this->assertFalse( get_site_option( 'mainwp_knowledge_description_column_repair_pending' ) );
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), OBJECT_K );
			$this->assertSame( "enum('agency','site','client')", $columns['scope_type']->Type );
			$this->assertArrayHasKey( 'required', $columns );
			$this->assertArrayHasKey( 'description', $columns );
			foreach ( array( $site, $client ) as $record ) {
				$row = $this->db->get_by_id( $record->id );
				$this->assertSame( $record->scope_type, $row->scope_type );
				$this->assertSame( $record->scope_id, $row->scope_id );
				$this->assertSame( $record->body, $row->body );
				$this->assertSame( 0, $row->required );
			}
		} finally {
			$this->clear_filters();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (%d,%d)', $table, $site->id, $client->id ) );
			update_site_option( 'mainwp_db_version', $prior );
			delete_site_option( 'mainwp_knowledge_schema_confirmed' );
			delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $this->db->hook_db_install_tables( array(), $prior, $wpdb->get_charset_collate() ) as $sql ) {
				dbDelta( $sql );
			}
			MainWP_Install::instance()->maybe_repair_knowledge_table();
			MainWP_DB::instance()->remove_website( $site->scope_id );
			MainWP_DB_Client::instance()->delete_client( $client->scope_id );
			// DDL ended the fixture transaction; cleanup must survive the harness rollback too.
			self::commit_transaction();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public static function knowledge_schema_components(): array {
		return array( 'agency' => array( 'agency' ), 'required' => array( 'required' ), 'description' => array( 'description' ) );
	}

	/** @dataProvider knowledge_schema_components */
	public function test_knowledge_schema_failed_alter_retries_on_next_eligible_load( string $component ): void {
		$this->set_current_user_as_admin();
		global $wpdb;
		$table = $wpdb->prefix . 'mainwp_knowledge';
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$suppressed = $wpdb->suppress_errors;
		try {
			delete_site_option( 'mainwp_knowledge_schema_confirmed' );
			delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
			if ( 'agency' === $component ) {
				$wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY scope_type enum('site','client') NOT NULL", $table ) );
			} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $component ) ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $component ) );
			}
			$attempts = 0;
			$this->track_filter( 'query', function ( $sql ) use ( $wpdb, &$attempts ) {
				if ( 0 === strpos( $sql, 'ALTER TABLE' ) && false !== strpos( $sql, 'mainwp_knowledge' ) ) {
					++$attempts;
					$this->assertTrue( $wpdb->suppress_errors );
					return str_replace( 'mainwp_knowledge', 'mainwp_knowledge_missing', $sql );
				}
				return $sql;
			} );
			update_site_option( 'mainwp_db_version', '9.0.2.8' );
			$install = MainWP_Install::instance();
			$install->install();
			$this->assertSame( $suppressed, $wpdb->suppress_errors );
			$this->assertSame( 1, $attempts );
			$this->assertFalse( get_site_option( 'mainwp_knowledge_schema_confirmed' ) );
			$install->install();
			$this->assertSame( 1, $attempts );
			$this->clear_filters();
			update_site_option( 'mainwp_knowledge_schema_repair_pending', time() - HOUR_IN_SECONDS - 1 );
			$install->install();
			$this->assertSame( $suppressed, $wpdb->suppress_errors );
			$this->assertSame( 'agency-required-description-1', get_site_option( 'mainwp_knowledge_schema_confirmed' ) );
			$this->assertFalse( get_site_option( 'mainwp_knowledge_schema_repair_pending' ) );
		} finally {
			$this->clear_filters();
			delete_site_option( 'mainwp_knowledge_schema_confirmed' );
			delete_site_option( 'mainwp_knowledge_schema_repair_pending' );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $this->db->hook_db_install_tables( array(), get_site_option( 'mainwp_db_version' ), $wpdb->get_charset_collate() ) as $sql ) {
				dbDelta( $sql );
			}
			MainWP_Install::instance()->maybe_repair_knowledge_table();
			$wpdb->suppress_errors( $suppressed );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
}
