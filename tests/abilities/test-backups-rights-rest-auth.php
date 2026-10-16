<?php
/**
 * Backup rights must survive each supported REST authentication state.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';

/** Core authentication runs before the real ability REST controller. */
class MainWP_Backups_Rights_REST_Auth_Test extends MainWP_Abilities_Test_Case {

	use Backup_Read_Purity_Guard;

	/**
	 * Request globals restored even when authentication clears the current user.
	 *
	 * @var array
	 */
	private $saved_globals = array();
	/**
	 * Allocated fixture identities and local catalog artifact.
	 *
	 * @var array
	 */
	private $fixture = array();
	/**
	 * REST server with registered ability routes.
	 *
	 * @var \Spy_REST_Server
	 */
	private $server;
	/**
	 * Existing cookie hook priority, or false when absent.
	 *
	 * @var int|false
	 */
	private $cookie_priority;
	/**
	 * Core and the singleton must see the same MainWP authenticator.
	 *
	 * @var \MainWP_REST_Authentication
	 */
	private $auth;
	/**
	 * MainWP authentication caches outlive a simulated request.
	 *
	 * @var array
	 */
	private $saved_auth = array();

	/** Authentication fixtures use core credentials and allocated backup records. */
	public function setUp(): void {
		parent::setUp();
		foreach ( array( '_SERVER', '_GET', '_POST', '_REQUEST', '_COOKIE', 'wp_rest_server', 'wp_rest_auth_cookie', 'wp_rest_application_password_status', 'wp_rest_application_password_uuid' ) as $name ) {
			$this->saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		$this->fixture['query_vars']  = $GLOBALS['wp']->query_vars;
		$this->saved_auth['instance'] = \MainWP_REST_Authentication::$instance;
		foreach ( $GLOBALS['wp_filter']['determine_current_user']->callbacks[15] as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof \MainWP_REST_Authentication && 'authenticate' === $function[1] ) {
				$this->auth = $function[0];
				break;
			}
		}
		$this->assertInstanceOf( \MainWP_REST_Authentication::class, $this->auth );
		\MainWP_REST_Authentication::$instance = $this->auth;
		foreach ( array(
			'user'        => null,
			'error'       => null,
			'auth_method' => '',
		) as $name => $value ) {
			$property = new \ReflectionProperty( $this->auth, $name );
			$property->setAccessible( true );
			$this->saved_auth[ $name ] = $property->getValue( $this->auth );
			$property->setValue( $this->auth, $value );
		}
		$this->cookie_priority = has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' );
		if ( false === $this->cookie_priority ) {
			add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );
		}
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		$this->fixture['user'] = $this->set_current_user_as_admin();
		$password              = \WP_Application_Passwords::create_new_application_password( $this->fixture['user'], array( 'name' => 'Backup rights REST fixture' ) );
		$this->assertNotWPError( $password );
		$this->fixture['password'] = $password[0];
		$this->fixture['site']     = $this->create_test_site();
		$this->fixture['artifact'] = tempnam( sys_get_temp_dir(), 'mainwp-auth-snapshot-' );
		file_put_contents( $this->fixture['artifact'], 'snapshot fixture' );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->fixture['snapshot'] = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'  => $this->fixture['site'],
				'type'     => 'db',
				'archives' => array(
					array(
						'file' => 'db.sql.gz',
						'role' => 'db',
						'path' => $this->fixture['artifact'],
						'size' => 16,
					),
				),
			)
		);
		$this->fixture['job']      = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->fixture['site'],
			$this->fixture['snapshot'],
			array(
				'sub_type'               => 'db',
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'safety.tar.gz',
						'role' => 'full',
						'size' => 16,
					),
				),
			)
		);
		MainWP_Backup_Restore_Job_Store::instance()->set_status( $this->fixture['job'], 'failed_permanent' );
		$GLOBALS['wp_rest_server'] = new \Spy_REST_Server();
		$this->server              = $GLOBALS['wp_rest_server'];
		do_action( 'rest_api_init' );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Authentication state must not leak into another REST test. */
	public function tearDown(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		if ( false === $this->cookie_priority ) {
			remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );
		}
		global $wpdb;
		foreach ( array( 'wp_backup_snapshot', 'wp_backup_restore_job' ) as $table ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( $table ), array( 'site_id' => $this->fixture['site'] ) );
		}
		unlink( $this->fixture['artifact'] );
		foreach ( array( 'user', 'error', 'auth_method' ) as $name ) {
			$property = new \ReflectionProperty( $this->auth, $name );
			$property->setAccessible( true );
			$property->setValue( $this->auth, $this->saved_auth[ $name ] );
		}
		\MainWP_REST_Authentication::$instance = $this->saved_auth['instance'];
		$GLOBALS['wp']->query_vars             = $this->fixture['query_vars'];
		foreach ( $this->saved_globals as $name => list( $existed, $value ) ) {
			if ( $existed ) {
				$GLOBALS[ $name ] = $value;
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tearDown();
	}

	/**
	 * The isolated API-key case loads the harness before setting its immutable REST flag.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( 'test_api_key_owner_cannot_bypass_restore_rights' !== $this->getName( false ) ) {
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
	 * Both outcomes are exercised for every ability and authentication state.
	 *
	 * @return array
	 */
	public static function auth_states() {
		$cases = array();
		foreach ( array(
			'restore-site-snapshot'  => 'restore_backups',
			'rollback-restore'       => 'restore_backups',
			'cancel-restore'         => 'restore_backups',
			'update-backup-settings' => 'manage_dashboard_settings',
			'list-backup-tasks'      => 'execute_backups',
		) as $name => $right ) {
			foreach ( array( 'application-password', 'application-password-cookie', 'cookie-nonce', 'cookie-only' ) as $state ) {
				foreach ( array( true, false ) as $granted ) {
					$cases[ $name . '/' . $state . '/' . ( $granted ? 'granted' : 'denied' ) ] = array( $name, $right, $state, $granted );
				}
			}
		}
		return $cases;
	}

	/**
	 * Valid previews isolate authorization from destructive admission.
	 *
	 * @param string $name Ability slug.
	 * @return array
	 */
	private function input( string $name ): array {
		switch ( $name ) {
			case 'restore-site-snapshot':
				return array(
					'site_id_or_domain' => $this->fixture['site'],
					'snapshot_id'       => $this->fixture['snapshot'],
					'dry_run'           => true,
				);
			case 'rollback-restore':
			case 'cancel-restore':
				return array(
					'restore_job_id' => $this->fixture['job'],
					'dry_run'        => true,
				);
			case 'update-backup-settings':
				return array(
					'on_demand_retention_count' => 7,
					'dry_run'                   => true,
				);
			default:
				return array();
		}
	}

	/**
	 * dispatch() omits authentication, so the harness calls core's authenticators first.
	 *
	 * @param string $state Authentication state.
	 */
	private function authenticate( string $state ): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$GLOBALS['wp_rest_auth_cookie']                 = null;
		$GLOBALS['wp_rest_application_password_status'] = null;
		$GLOBALS['wp_rest_application_password_uuid']   = null;
		wp_set_current_user( 0 );
		if ( 'application-password' !== $state ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->fixture['user'], time() + HOUR_IN_SECONDS, 'logged_in' );
		}
		if ( 0 === strpos( $state, 'application-password' ) ) {
			$_SERVER['PHP_AUTH_USER'] = get_userdata( $this->fixture['user'] )->user_login;
			$_SERVER['PHP_AUTH_PW']   = $this->fixture['password'];
		}
		// Core's own filter order decides which credential wins: the
		// logged-in cookie runs first, and the application password then
		// declines to authenticate an already identified user.
		$user_id = (int) apply_filters( 'determine_current_user', false );
		$this->assertSame( $this->fixture['user'], $user_id );
		wp_set_current_user( $user_id );
		$this->assertSame( 'application-password' === $state, $GLOBALS['wp_rest_application_password_status'] instanceof \WP_User );
		$this->assertSame( 'application-password' !== $state, true === $GLOBALS['wp_rest_auth_cookie'] );
		if ( 'cookie-nonce' === $state ) {
			$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		}
		$this->assertNotFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
		$this->assertNotWPError( $this->server->check_authentication() );
		$this->assertSame( in_array( $state, array( 'application-password-cookie', 'cookie-only' ), true ) ? 0 : $this->fixture['user'], get_current_user_id() );
	}

	/**
	 * A cookie without a nonce must not inherit another request's authenticated user.
	 *
	 * @dataProvider auth_states
	 * @param string $name Ability slug.
	 * @param string $right Right withheld in denied cases.
	 * @param string $state Authentication state.
	 * @param bool   $granted Whether the right is held.
	 */
	public function test_rights_over_real_rest_authentication( $name, $right, $state, $granted ) {
		$GLOBALS['wp']->query_vars['rest_route'] = '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run';
		$this->authenticate( $state );
		$filter = static function ( $allowed, $type, $cap ) use ( $right, $granted ) {
			return 'dashboard' === $type && $right === $cap ? $granted : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $filter, 10, 3 );
		try {
			$method  = 'list-backup-tasks' === $name ? 'GET' : ( 'cancel-restore' === $name ? 'DELETE' : 'POST' );
			$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
			if ( 'GET' === $method ) {
				$request->set_query_params( array( 'input' => $this->input( $name ) ) );
			} else {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'input' => $this->input( $name ) ) ) );
			}
			$response = $this->guarded(
				function () use ( $request ) {
					return $this->server->dispatch( $request );
				}
			);
			$data     = $response->get_data();
			// An application password sent with a logged-in cookie and no
			// nonce is treated as cookie auth, so core clears the user.
			if ( in_array( $state, array( 'application-password-cookie', 'cookie-only' ), true ) ) {
				$this->assertSame( 401, $response->get_status(), wp_json_encode( $data ) );
				$this->assertSame( 'mainwp_permission_denied', $data['code'] );
			} elseif ( ! $granted ) {
				$this->assertSame( 403, $response->get_status(), wp_json_encode( $data ) );
				$this->assertSame( 'mainwp_insufficient_right', $data['code'] );
				$this->assertSame( array( 'status' => 403 ), $data['data'] );
				$this->assertSame( $right, $data['additional_data'][0]['right'] );
			} else {
				$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
				$this->assertTrue( rest_validate_value_from_schema( $data, wp_get_ability( 'mainwp/' . $name . '-v1' )->get_output_schema() ) );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
	}

	/** List access is checked again when an integration bypasses the permission callback. */
	public function test_task_list_checks_right_at_permission_and_execute() {
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'execute_backups' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			foreach ( array( wp_get_ability( 'mainwp/list-backup-tasks-v1' )->check_permissions( array() ), MainWP_Abilities_Backups::execute_list_backup_tasks( array() ) ) as $error ) {
				$this->assertWPError( $error );
				$this->assertSame( 'mainwp_insufficient_right', $error->get_error_code() );
				$this->assertSame(
					array(
						'status' => 403,
						'right'  => 'execute_backups',
					),
					$error->get_error_data()
				);
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
		$this->assertIsArray( MainWP_Abilities_Backups::execute_list_backup_tasks( array() ) );
	}

	/**
	 * MainWP's wrapper bypass must not apply to an authenticated API-key owner's rights.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_key_owner_cannot_bypass_restore_rights() {
		$_GET                                    = array();
		$_POST                                   = array();
		$_REQUEST                                = array();
		$_COOKIE                                 = array();
		$credentials                             = $this->create_rest_api_key( $this->fixture['user'] );
		$auth                                    = \MainWP_REST_Authentication::get_instance();
		$_SERVER['HTTPS']                        = 'on';
		$_SERVER['PHP_AUTH_USER']                = $credentials['consumer_key'];
		$_SERVER['PHP_AUTH_PW']                  = $credentials['consumer_secret'];
		$previous_route                          = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		$GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites';
		wp_set_current_user( 0 );
		try {
			$this->assertSame( $this->fixture['user'], (int) $auth->authenticate( false ) );
			wp_set_current_user( $this->fixture['user'] );
			$request = new \WP_REST_Request( 'GET', '/mainwp/v2/sites' );
			$this->assertNull( $auth->check_user_permissions( null, $this->server, $request ) );
			$this->assertTrue( MAINWP_REST_API_DOING );
			$deny = static function ( $allowed, $type, $right ) {
				return 'dashboard' === $type && 'restore_backups' === $right ? false : $allowed;
			};
			add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
			try {
				$this->assertTrue( \mainwp_current_user_can( 'dashboard', 'restore_backups' ) );
				foreach ( array( 'restore-site-snapshot', 'rollback-restore', 'cancel-restore' ) as $name ) {
					$method = 'execute_' . str_replace( '-', '_', $name );
					foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
						$input = $this->input( $name );
						unset( $input['dry_run'] );
						$input[ $mode ] = true;
						$errors         = $this->guarded(
							static function () use ( $name, $method, $input ) {
								return array( wp_get_ability( 'mainwp/' . $name . '-v1' )->check_permissions( $input ), MainWP_Abilities_Backups::$method( $input ) );
							}
						);
						foreach ( $errors as $error ) {
							$this->assertWPError( $error );
							$this->assertSame( 'mainwp_insufficient_right', $error->get_error_code() );
							$this->assertSame(
								array(
									'status' => 403,
									'right'  => 'restore_backups',
								),
								$error->get_error_data()
							);
						}
					}
				}
			} finally {
				remove_filter( 'mainwp_currentusercan', $deny, 10 );
			}
		} finally {
			if ( null === $previous_route ) {
				unset( $GLOBALS['wp']->query_vars['rest_route'] );
			} else {
				$GLOBALS['wp']->query_vars['rest_route'] = $previous_route;
			}
			global $wpdb;
			$hashed = mainwp_api_hash( $credentials['consumer_key'] );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'api_keys' ), array( 'consumer_key' => $hashed ) );
			wp_cache_delete( 'mainwp_api_key_' . md5( $hashed ) );
		}
	}
}
