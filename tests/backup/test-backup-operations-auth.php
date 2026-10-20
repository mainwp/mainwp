<?php
/**
 * Single-target and fleet authorization must enforce the same site predicates.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

/** Class MainWP_Backup_Operations_Auth_Test. */
class MainWP_Backup_Operations_Auth_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;

	/**
	 * The isolated CLI process lets the WordPress harness define its own constants.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( 'test_userless_cli_passes_including_empty_right' !== $this->getName( false ) ) {
			return parent::run( $result );
		}
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/**
	 * Each invocation starts from fixed records.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->seed_read_fixtures();
	}

	/**
	 * Fixture records and request state must not escape the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Denials must conceal target existence.
	 *
	 * @return void
	 */
	public function test_missing_and_out_of_scope_share_not_found_codes() {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'Shared authorization must exist.' );
		$targets = array(
			'authorize_site'        => array( $this->site_id, 'execute_backups' ),
			'authorize_task'        => array( 987671, 'run_backup_tasks' ),
			'authorize_snapshot'    => array( 987673 ),
			'authorize_restore_job' => array( 987674 ),
			'authorize_destination' => array( 987676 ),
		);
		foreach ( $targets as $method => $args ) {
			$missing_args    = $args;
			$missing_args[0] = 999999999;
			$missing         = MainWP_Backup_Operations::$method( ...$missing_args );
			$this->assertWPError( $missing );
			$this->assertSame( 404, $missing->get_error_data()['status'] );
			if ( 'authorize_destination' === $method ) {
				continue;
			}
			add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
			$denied = MainWP_Backup_Operations::$method( ...$args );
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
			$this->assertWPError( $denied );
			$this->assertSame( $missing->get_error_code(), $denied->get_error_code() );
			$this->assertSame( 404, $denied->get_error_data()['status'] );
		}
		$this->assertSame( 'mainwp_backup_task_not_found', MainWP_Backup_Operations::authorize_task( 987672, 'run_backup_tasks' )->get_error_code(), 'Hidden tasks cannot be targeted.' );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'status' => 'reserved' ), array( 'id' => 987674 ) );
		$this->assertSame( 'mainwp_backup_restore_job_not_found', MainWP_Backup_Operations::authorize_restore_job( 987674 )->get_error_code() );
	}

	/**
	 * Base capability and granular rights are independent gates.
	 *
	 * @return void
	 */
	public function test_subscriber_and_missing_operation_right_fail() {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'Shared authorization must exist.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'mainwp_permission_denied', MainWP_Backup_Operations::authorize_site( $this->site_id, 'execute_backups' )->get_error_code() );
		$this->assertSame( array(), MainWP_Backup_Operations::authorized_site_ids( '' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$deny = static function ( $allowed, $type ) {
			return 'dashboard' === $type ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 2 );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$this->assertSame( 'mainwp_insufficient_right', MainWP_Backup_Operations::authorize_site( $this->site_id, 'execute_backups' )->get_error_code() );
			$this->assertSame( array(), MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}

	/**
	 * Each fixture withholds one independent site permission.
	 *
	 * @return array Independent site denials.
	 */
	public function denials() {
		return array( array( 'access' ), array( 'owner' ), array( 'site-right' ) );
	}

	/**
	 * A single-site denial must also remove the site from fleet totals.
	 *
	 * @dataProvider denials
	 * @param string $kind Denial source.
	 * @return void
	 */
	public function test_independent_site_denials_apply_to_single_and_fleet( $kind ) {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'Shared authorization must exist.' );

		$deny  = static function ( $allowed, $id ) {
			return 987670 === (int) $id ? false : $allowed;
		};
		$right = static function ( $allowed, $type, $key ) {
			return 'site' === $type && 987670 === (int) $key ? false : $allowed;
		};
		if ( 'access' === $kind ) {
			add_filter( 'mainwp_check_site_access', $deny, 10, 2 );
		} elseif ( 'site-right' === $kind ) {
			add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		} else {
			add_filter( 'mainwp_is_single_user', '__return_false' );
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'userid' => get_current_user_id() + 1000 ), array( 'id' => $this->site_id ) );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$this->assertSame( 'mainwp_site_not_found', MainWP_Backup_Operations::authorize_site( $this->site_id, 'execute_backups' )->get_error_code() );
			$this->assertNotContains( $this->site_id, MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			$this->assertNotContains( $this->site_id, MainWP_Backup_Operations::authorized_site_ids( '' ) );
		} finally {
			remove_filter( 'mainwp_is_single_user', '__return_false' );
			remove_filter( 'mainwp_check_site_access', $deny, 10 );
			remove_filter( 'mainwp_currentusercan', $right, 10 );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}

	/**
	 * An empty granular key must leave site checks active.
	 *
	 * @return void
	 */
	public function test_empty_right_skips_only_granular_check_in_normal_and_rest_contexts() {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'Shared authorization must exist.' );
		$machine = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
		$machine->setAccessible( true );
		$original = $machine->getValue();
		$deny     = static function ( $allowed, $type, $key ) {
			return 'dashboard' === $type && '' === $key ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			foreach ( array( false, 'rest' ) as $context ) {
				$machine->setValue( null, $context );
				MainWP_Backup_Operations::reset_authorized_site_ids_memo();
				$this->assertContains( $this->site_id, MainWP_Backup_Operations::authorized_site_ids( '' ) );
				$this->assertIsObject( MainWP_Backup_Operations::authorize_site( $this->site_id, '' ) );
			}
			wp_set_current_user( 0 );
			$this->assertSame( array(), MainWP_Backup_Operations::authorized_site_ids( '' ) );
			$this->assertWPError( MainWP_Backup_Operations::authorize_site( $this->site_id, '' ) );
		} finally {
			$machine->setValue( null, $original );
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}

	/**
	 * A trusted userless CLI operator retains access when Team Control denies user zero.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_userless_cli_passes_including_empty_right() {
		$this->assertTrue( class_exists( MainWP_Backup_Operations::class ), 'Shared authorization must exist.' );
		define( 'WP_CLI', true );
		wp_set_current_user( 0 );
		add_filter( 'mainwp_currentusercan', '__return_false' );
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		try {
			$this->assertIsObject( MainWP_Backup_Operations::authorize_site( $this->site_id, 'execute_backups' ) );
			$this->assertIsObject( MainWP_Backup_Operations::authorize_restore_job( 987674, true ) );
			$this->assertNotWPError( MainWP_Backup_Operations::save_backup_settings( array( 'on_demand_retention_count' => 7 ), 'ui' ) );
			$this->assertSame( 7, (int) get_option( 'mainwp_on_demand_retention_count' ) );
			$this->assertFalse( is_wp_error( MainWP_Backup_Operations::authorize_task( 987671, 'run_backup_tasks' ) ) );
			$this->assertContains( $this->site_id, MainWP_Backup_Operations::authorized_site_ids( '' ) );
			$this->assertContains( $this->site_id, MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			global $wpdb;
			$table  = MainWP_DB::instance()->get_table_name( 'wp_backup' );
			$before = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A );
			$result = MainWP_Backup_Operations::start_site_backup( $this->site_id, 'cli' );
			$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A ), 'A userless CLI start must leave every task row unchanged.' );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
			$this->assertSame( 401, $result->get_error_data()['status'] );
			$this->assertSame( 'Starting a backup from WP-CLI needs a Dashboard administrator. Run the command again with --user set to one.', $result->get_error_message() );
		} finally {
			remove_filter( 'mainwp_currentusercan', '__return_false' );
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		}
	}
	/**
	 * The service must expose a refusal code without reading request globals.
	 *
	 * @return void
	 */
	public function test_action_refusal_has_code_and_ignores_request_globals() {
		update_option( 'mainwp_enableNativeBackups', 0 );
		$_POST['site_id'] = 999999999;
		try {
			$result = MainWP_Backup_Operations::start_site_backup( $this->site_id, 'ui' );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_backup_legacy_disabled', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		} finally {
			unset( $_POST['site_id'] );
		}
	}

	/**
	 * An unknown transport is refused before any site lookup or cleanup work.
	 *
	 * @return void
	 */
	public function test_unknown_context_is_refused_as_invalid_input() {
		$result = MainWP_Backup_Operations::start_site_backup( $this->site_id, 'bogus' );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Duplicate synchronization rows must not inflate the authorized fleet.
	 *
	 * @return void
	 */
	public function test_authorized_site_ids_deduplicates_sync_rows() {
		$this->insert_read_fixture( 'wp_sync', array( 'wpid' => $this->site_id ) );
		$authorized = MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' );
		$this->assertContains( $this->site_id, $authorized );
		$this->assertSame( array( $this->site_id ), array_values( array_filter( $authorized, fn( $id ) => $this->site_id === $id ) ) );
	}

	/**
	 * A different user or right must not inherit an earlier fleet scope.
	 *
	 * @return void
	 */
	public function test_authorized_site_ids_memoizes_one_roster_per_user_and_right() {
		$first_user = get_current_user_id();
		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_site = $this->create_test_site(
			array(
				'name' => 'Other owner',
				'url'  => 'https://other-owner.example.org/',
			)
		);
		$sites      = array(
			$first_user => array( $this->site_id ),
			$other_user => array( $other_site ),
		);
		$scope      = static function () use ( $sites ) {
			return $sites[ get_current_user_id() ];
		};
		$right      = static function ( $allowed, $type, $key ) {
			return 'dashboard' === $type && 'run_backup_tasks' === $key ? false : $allowed;
		};
		$table      = MainWP_DB::instance()->get_table_name( 'wp' );
		$queries    = 0;
		$count      = static function ( $sql ) use ( $table, &$queries ) {
			if ( preg_match( '/^SELECT\b/i', $sql ) && preg_match( '/\b(?:FROM|JOIN)\s+`?' . preg_quote( $table, '/' ) . '`?(?:\s|$)/i', $sql ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		add_filter( 'query', $count );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$this->assertSame( $sites[ $first_user ], MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			$this->assertSame( 1, $queries, 'The first fleet read must load the roster in one query.' );
			$this->assertSame( $sites[ $first_user ], MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			$this->assertSame( 1, $queries, 'The second fleet read must issue no further site query.' );
			$this->assertSame( array(), MainWP_Backup_Operations::authorized_site_ids( 'run_backup_tasks' ), 'A different right must retain its own denial.' );
			wp_set_current_user( $other_user );
			$this->assertSame( $sites[ $other_user ], MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ), 'A user switch must not reuse another owner\'s set.' );
			$this->assertSame( 2, $queries, 'The second user must load one roster.' );
			$this->assertSame( $sites[ $other_user ], MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			$this->assertSame( 2, $queries, 'The second user must also reuse their own set.' );
			wp_set_current_user( $first_user );
			$this->assertSame( $sites[ $first_user ], MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' ) );
			$this->assertSame( 2, $queries, 'Returning to the first user may reuse only that user\'s set.' );
		} finally {
			remove_filter( 'query', $count );
			remove_filter( 'mainwp_currentusercan', $right, 10 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}
}
