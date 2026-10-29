<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit's file loader matches the class name.
/**
 * Permission callback failures must preserve each entry point's normal refusal.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Application_Passwords;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System;

/** Exercise the five direct permission filter callers through their consumers. */
class Test_Current_User_Permission_Filter extends MainWP_Abilities_Test_Case {

	/**
	 * Callbacks removed independently of test transaction rollback.
	 *
	 * @var array
	 */
	private $permission_filters = array();

	/**
	 * Permission filter arguments observed at the target call site.
	 *
	 * @var array
	 */
	private $permission_calls = array();

	/**
	 * Current user before each test.
	 *
	 * @var int
	 */
	private $original_user_id;

	/**
	 * Existing owned site used by backup authorization.
	 *
	 * @var int
	 */
	private $permission_site_id;

	/** Keep authenticated request context without defining process-wide bypasses. */
	public function setUp(): void {
		parent::setUp();
		$this->original_user_id = get_current_user_id();
		$this->assertFalse( $this->capabilities_bypassed(), 'Permission tests require an ordinary authenticated request.' );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->add_permission_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () {
				return 'all';
			},
			1
		);
	}

	/** Remove only callbacks owned by these tests. */
	public function tearDown(): void {
		foreach ( $this->permission_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 999 );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		wp_set_current_user( $this->original_user_id );
		parent::tearDown();
	}

	/**
	 * Install a callback without replacing another test's hooks.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $callback Test callback.
	 * @param int      $arguments Accepted argument count.
	 */
	private function add_permission_filter( string $hook, callable $callback, int $arguments = 3 ): void {
		add_filter( $hook, $callback, 999, $arguments );
		$this->permission_filters[] = array( $hook, $callback );
	}

	/**
	 * Original filter defaults, groups and rights for every consumer.
	 *
	 * @return array
	 */
	public static function permission_consumers(): array {
		return array(
			'ability operation' => array( 'ability', true, 'dashboard', 'execute_backups' ),
			'REST operation'    => array( 'rest', true, 'rest_api', 'edit_rest_api_keys' ),
			'backup site'       => array( 'backup', true, 'site', null ),
			'view passwords'    => array( 'view', false, 'rest_api', 'view_all_application_passwords' ),
			'manage passwords'  => array( 'manage', false, 'rest_api', 'manage_application_passwords' ),
		);
	}

	/**
	 * Set up an actor who reaches the target permission check.
	 *
	 * @param string $consumer Entry point name.
	 */
	private function prepare_consumer( string $consumer ): void {
		$role = in_array( $consumer, array( 'view', 'manage' ), true ) ? 'subscriber' : 'administrator';
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		if ( 'backup' === $consumer ) {
			$this->permission_site_id = $this->create_test_site();
			$this->assertGreaterThan( 0, $this->permission_site_id );
		}
	}

	/**
	 * Call the production permission consumer without substituting its guard.
	 *
	 * @param string $consumer Entry point name.
	 * @return mixed
	 */
	private function invoke_consumer( string $consumer ) {
		switch ( $consumer ) {
			case 'ability':
				$ability = wp_get_ability( 'mainwp/list-backup-tasks-v1' );
				$this->assertNotNull( $ability, 'Backup list ability must be registered.' );
				return $ability->check_permissions( array() );
			case 'rest':
				$request = new \WP_REST_Request( 'PATCH', '/mainwp/v2/rest-api/edit-key/999999999' );
				$request->set_param( 'key_identifier', 999999999 );
				return ( new \MainWP_Rest_API_Keys_Controller() )->edit_key( $request );
			case 'backup':
				return MainWP_Backup_Operations::authorize_site( $this->permission_site_id, '', 'ui' );
			case 'view':
				return MainWP_Application_Passwords::can_view_all_application_passwords();
			case 'manage':
				return MainWP_Application_Passwords::can_view_manage_application_passwords();
		}
		$this->fail( 'Unknown permission consumer: ' . $consumer );
	}

	/**
	 * Refusals keep their existing error codes and statuses.
	 *
	 * @param string $consumer Entry point name.
	 * @param mixed  $result Observable permission result.
	 */
	private function assert_consumer_denied( string $consumer, $result ): void {
		if ( in_array( $consumer, array( 'view', 'manage' ), true ) ) {
			$this->assertFalse( $result );
			return;
		}
		$this->assertWPError( $result );
		$this->assertSame( 'backup' === $consumer ? 'mainwp_site_not_found' : 'mainwp_insufficient_right', $result->get_error_code() );
		$this->assertSame( 'backup' === $consumer ? 404 : 403, $result->get_error_data()['status'] );
	}

	/**
	 * An allowed guard reaches the original next step, without creating an API key.
	 *
	 * @param string $consumer Entry point name.
	 * @param mixed  $result Observable permission result.
	 */
	private function assert_consumer_allowed( string $consumer, $result ): void {
		if ( 'rest' === $consumer ) {
			$this->assertWPError( $result );
			$this->assertSame( 'api_key_not_found', $result->get_error_code() );
		} elseif ( 'backup' === $consumer ) {
			$this->assertIsObject( $result );
			$this->assertNotWPError( $result );
			$this->assertSame( $this->permission_site_id, (int) $result->id );
		} else {
			$this->assertTrue( $result );
		}
	}

	/**
	 * Target only the final check so earlier authorization remains real.
	 *
	 * @param string $type Capability group.
	 * @param mixed  $right Capability name or site ID.
	 * @param mixed  $outcome Boolean grant or 'throw'.
	 * @return callable
	 */
	private function permission_callback( string $type, $right, $outcome ): callable {
		return function ( $initial_grant, $actual_type, $actual_right ) use ( $type, $right, $outcome ): bool {
			if ( $type !== $actual_type || $right !== $actual_right ) {
				return (bool) $initial_grant;
			}
			$this->permission_calls[] = array( $initial_grant, $actual_type, $actual_right );
			if ( 'throw' === $outcome ) {
				return in_array( 1, null, true );
			}
			return $outcome;
		};
	}

	/**
	 * No add-on, a normal grant and a normal denial retain their original behavior.
	 *
	 * @dataProvider permission_consumers
	 * @param string $consumer Entry point name.
	 * @param bool   $initial_grant Original filter default.
	 * @param string $type Capability group.
	 * @param mixed  $right Capability name or site ID placeholder.
	 */
	public function test_normal_permission_results_preserve_filter_contract( string $consumer, bool $initial_grant, string $type, $right ): void {
		$this->prepare_consumer( $consumer );
		$right  = 'backup' === $consumer ? $this->permission_site_id : $right;
		$result = $this->invoke_consumer( $consumer );
		if ( $initial_grant ) {
			$this->assert_consumer_allowed( $consumer, $result );
		} else {
			$this->assert_consumer_denied( $consumer, $result );
		}
		foreach ( array( true, false ) as $allowed ) {
			$this->permission_calls = array();
			$callback               = $this->permission_callback( $type, $right, $allowed );
			$this->add_permission_filter( 'mainwp_currentusercan', $callback );
			$result = $this->invoke_consumer( $consumer );
			if ( $allowed ) {
				$this->assert_consumer_allowed( $consumer, $result );
			} else {
				$this->assert_consumer_denied( $consumer, $result );
			}
			$this->assertSame( array( array( $initial_grant, $type, $right ) ), $this->permission_calls );
			remove_filter( 'mainwp_currentusercan', $callback, 999 );
		}
	}

	/**
	 * Reproduce Team Control 5.1's native TypeError at the target filter.
	 *
	 * @param string $consumer Entry point name.
	 */
	private function assert_throw_refused( string $consumer ): void {
		$this->prepare_consumer( $consumer );
		foreach ( self::permission_consumers() as $contract ) {
			if ( $consumer !== $contract[0] ) {
				continue;
			}
			list( , $initial_grant, $type, $right ) = $contract;
			$right                                  = 'backup' === $consumer ? $this->permission_site_id : $right;
			$this->add_permission_filter( 'mainwp_currentusercan', $this->permission_callback( $type, $right, 'throw' ) );
			$result = $this->invoke_consumer( $consumer );
			$this->assert_consumer_denied( $consumer, $result );
			$this->assertSame( array( array( $initial_grant, $type, $right ) ), $this->permission_calls );
			return;
		}
		$this->fail( 'Missing permission contract: ' . $consumer );
	}

	/** Backup ability permission errors retain their ordinary 403 refusal. */
	public function test_ability_refuses_throwing_permission_callback(): void {
		$this->assert_throw_refused( 'ability' );
	}

	/** API key editing refuses before reading the requested key. */
	public function test_rest_operation_refuses_throwing_permission_callback(): void {
		$this->assert_throw_refused( 'rest' );
	}

	/** A failed site permission check keeps the backup's normal not-found refusal. */
	public function test_backup_site_refuses_throwing_permission_callback(): void {
		$this->assert_throw_refused( 'backup' );
	}

	/** Viewing every user's application passwords requires an explicit grant. */
	public function test_view_application_passwords_refuses_throwing_permission_callback(): void {
		$this->assert_throw_refused( 'view' );
	}

	/** Managing application passwords requires an explicit grant. */
	public function test_manage_application_passwords_refuses_throwing_permission_callback(): void {
		$this->assert_throw_refused( 'manage' );
	}

	/** The shared boundary must leave successful filter values and arguments intact. */
	public function test_shared_and_global_permission_checks_preserve_filter_values(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$sentinel = (object) array( 'permission_result' => 'unchanged' );
		$this->add_permission_filter(
			'mainwp_currentusercan',
			function ( $initial_grant, $type, $right ) use ( $sentinel ) {
				$this->permission_calls[] = array( $initial_grant, $type, $right );
				return $sentinel;
			}
		);
		$this->assertSame( $sentinel, MainWP_System::filter_current_user_permission( 'original-default', 'site', 42 ) );
		$this->assertSame( $sentinel, \MainWP\Dashboard\mainwp_current_user_have_right( 'site', 42 ) );
		$this->assertSame(
			array(
				array( 'original-default', 'site', 42 ),
				array( true, 'site', 42 ),
			),
			$this->permission_calls
		);
	}

	/** The legacy global boundary must also refuse a native callback failure. */
	public function test_global_permission_check_refuses_throwing_callback(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->add_permission_filter( 'mainwp_currentusercan', $this->permission_callback( 'site', 42, 'throw' ) );
		$this->assertFalse( \MainWP\Dashboard\mainwp_current_user_have_right( 'site', 42 ) );
		$this->assertSame( array( array( true, 'site', 42 ) ), $this->permission_calls );
	}

	/**
	 * Process-wide contexts that grant access before the permission filter.
	 *
	 * @return array
	 */
	public static function global_permission_contexts(): array {
		return array(
			'cron' => array( 'DOING_CRON' ),
			'CLI'  => array( 'WP_CLI' ),
			'REST' => array( 'MAINWP_REST_API_DOING' ),
		);
	}

	/**
	 * Exercise real context constants without changing PHPUnit's request context.
	 *
	 * @dataProvider global_permission_contexts
	 * @param string $context_constant Constant enabled only in the child process.
	 */
	public function test_global_permission_context_returns_before_filter( string $context_constant ): void {
		$constants_before = array();
		foreach ( self::global_permission_contexts() as $context ) {
			$constants_before[ $context[0] ] = array( defined( $context[0] ), defined( $context[0] ) ? constant( $context[0] ) : null );
		}

		$process   = false;
		$pipes     = array();
		$output    = '';
		$error     = '';
		$exit_code = null;
		$timed_out = false;
		try {
			$process = proc_open(
				array( PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/global-permission-context.inc', ABSPATH, $context_constant ),
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$this->assertIsResource( $process );
			fclose( $pipes[0] );
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$deadline = hrtime( true ) + 10 * 1000000000;
			while ( true ) {
				$output .= stream_get_contents( $pipes[1] );
				$error  .= stream_get_contents( $pipes[2] );
				$status  = proc_get_status( $process );
				if ( ! $status['running'] ) {
					$exit_code = $status['exitcode'];
					break;
				}
				if ( hrtime( true ) >= $deadline ) {
					$timed_out = true;
					break;
				}
				usleep( 10000 );
			}
		} finally {
			if ( is_resource( $process ) && proc_get_status( $process )['running'] ) {
				proc_terminate( $process, 9 );
			}
			foreach ( $pipes as $descriptor => $pipe ) {
				if ( is_resource( $pipe ) ) {
					if ( 1 === $descriptor ) {
						$output .= stream_get_contents( $pipe );
					} elseif ( 2 === $descriptor ) {
						$error .= stream_get_contents( $pipe );
					}
					fclose( $pipe );
				}
			}
			if ( is_resource( $process ) ) {
				proc_close( $process );
			}
		}

		foreach ( $constants_before as $name => $before ) {
			$this->assertSame( $before, array( defined( $name ), defined( $name ) ? constant( $name ) : null ), $name . ' changed in the parent process.' );
		}
		$this->assertFalse( $timed_out, 'Permission context child exceeded 10 seconds. ' . $error );
		$this->assertSame( 0, $exit_code, $error );
		$this->assertSame( '', $error );
		$this->assertSame(
			array(
				'permission' => true,
				'calls'      => array(),
			),
			json_decode( $output, true, 512, JSON_THROW_ON_ERROR )
		);
	}

	/**
	 * Userless CLI keeps its grant and userless REST keeps its refusal.
	 *
	 * @dataProvider userless_ability_contexts
	 * @param mixed $context Machine context override.
	 * @param bool  $expected Expected operation right.
	 */
	public function test_userless_ability_context_returns_before_filter( $context, bool $expected ): void {
		$machine = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
		$machine->setAccessible( true );
		$original = $machine->getValue();
		wp_set_current_user( 0 );
		$this->add_permission_filter(
			'mainwp_currentusercan',
			function (): bool {
				$this->permission_calls[] = 'unexpected';
				return in_array( 1, null, true );
			}
		);
		try {
			$machine->setValue( null, $context );
			$this->assertSame( $expected, MainWP_Abilities_Util::current_user_has_operation_right( 'execute_backups' ) );
			$this->assertSame( array(), $this->permission_calls );
		} finally {
			$machine->setValue( null, $original );
		}
	}

	/**
	 * The existing reflection seam avoids leaking machine constants.
	 *
	 * @return array
	 */
	public static function userless_ability_contexts(): array {
		return array(
			'CLI'  => array( true, true ),
			'REST' => array( 'rest', false ),
		);
	}

	/**
	 * Existing scope, access and ownership refusals must precede the site filter.
	 *
	 * @dataProvider backup_earlier_denials
	 * @param string $denial Earlier refusal source.
	 */
	public function test_backup_earlier_refusals_do_not_call_permission_filter( string $denial ): void {
		$this->prepare_consumer( 'backup' );
		$this->add_permission_filter( 'mainwp_currentusercan', $this->permission_callback( 'site', $this->permission_site_id, 'throw' ) );
		if ( 'scope' === $denial ) {
			$this->add_permission_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array', 1 );
		} elseif ( 'access' === $denial ) {
			$this->add_permission_filter( 'mainwp_check_site_access', '__return_false', 1 );
		} else {
			$this->add_permission_filter( 'mainwp_is_single_user', '__return_false', 1 );
			global $wpdb;
			$other_owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$this->assertSame(
				1,
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'userid' => $other_owner ), array( 'id' => $this->permission_site_id ) )
			);
		}
		$this->assertNotNull( MainWP_DB::instance()->get_website_by_id_unscoped( $this->permission_site_id ), 'Earlier refusals must use an existing site.' );
		$this->assert_consumer_denied( 'backup', $this->invoke_consumer( 'backup' ) );
		$this->assertSame( array(), $this->permission_calls );
	}

	/**
	 * Each independent check must stop before the final permission filter.
	 *
	 * @return array
	 */
	public static function backup_earlier_denials(): array {
		return array(
			'site scope' => array( 'scope' ),
			'access'     => array( 'access' ),
			'ownership'  => array( 'ownership' ),
		);
	}

	/**
	 * Administrator branches must return before the add-on grant check.
	 *
	 * @dataProvider application_password_admin_cases
	 * @param string $consumer Application password entry point.
	 * @param bool   $special Whether the admin email identifies the special admin.
	 */
	public function test_application_password_admin_early_returns( string $consumer, bool $special ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		update_option( 'admin_email', $special ? $user->user_email : 'other-admin@example.org' );
		wp_set_current_user( $user_id );
		$this->add_permission_filter(
			'mainwp_currentusercan',
			function (): bool {
				$this->permission_calls[] = 'unexpected';
				return in_array( 1, null, true );
			}
		);
		$this->assertSame( $special, $this->invoke_consumer( $consumer ) );
		$this->assertSame( array(), $this->permission_calls );
	}

	/**
	 * Both application password checks retain both administrator branches.
	 *
	 * @return array
	 */
	public static function application_password_admin_cases(): array {
		return array(
			'special admin view'   => array( 'view', true ),
			'other admin view'     => array( 'view', false ),
			'special admin manage' => array( 'manage', true ),
			'other admin manage'   => array( 'manage', false ),
		);
	}
}
