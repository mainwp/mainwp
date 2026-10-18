<?php
/**
 * Native snapshot browser rights must work for staff without manage_options.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Download;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';
require_once __DIR__ . '/class-mainwp-backup-settings-staff-fixtures.php';
require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Browser transport and site scope remain independent of WordPress admin caps. */
class MainWP_Backup_Snapshot_UI_Staff_Rights_Test extends MainWP_Abilities_Test_Case {

	use Backup_Read_Fixtures;
	use Backup_Settings_Staff_Fixtures;
	use Backup_Private_Root_Fixture;

	/**
	 * Request globals restored after each case.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Real browser credentials and archive.
	 *
	 * @var array
	 */
	private $fixture = array();

	/**
	 * Download records owned by this test.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Team Control filters removed between actors.
	 *
	 * @var array
	 */
	private $access_filters = array();

	/** A completed manual backup permits reads without driving the Child. */
	public function setUp(): void {
		parent::setUp();
		$this->skip_when_capabilities_bypassed();
		foreach ( array( '_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', 'current_user', 'current_screen', 'wp_rest_auth_cookie', 'wp_rest_application_password_status', 'wp_rest_application_password_uuid' ) as $key ) {
			$this->saved[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null );
		}
		$this->saved['query_vars'] = $GLOBALS['wp']->query_vars;
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core must resolve the browser cookie outside a simulated admin screen.
		$this->seed_read_fixtures();
		$this->set_up_private_root();
		$this->fixture['site']     = $this->site_id;
		$this->fixture['snapshot'] = 987673;
		$this->fixture['path']     = MainWP_Backup_Private_Root::site_dir( $this->site_id ) . 'full.zip';
		file_put_contents( $this->fixture['path'], 'archive bytes' );
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'task_id'  => 987672,
					'archives' => wp_json_encode(
						array(
							array(
								'file' => 'full.zip',
								'path' => $this->fixture['path'],
								'size' => 13,
								'role' => 'full',
							),
						)
					),
				),
				array( 'id' => 987673 )
			)
		);
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup' ),
			array(
				'backup_errors'  => '',
				'backup_notices' => '',
			),
			array( 'id' => 987672 )
		);
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'status' => 'completed' ), array( 'id' => 987674 ) );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'mainwp_restore_foreground_budget', array( $this, 'no_foreground_work' ) );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Persistent tokens and role caches must not affect another case. */
	public function tearDown(): void {
		if ( ! array_key_exists( 'query_vars', $this->saved ) ) {
			parent::tearDown();
			return;
		}
		$this->clear_access();
		foreach ( $this->tokens as $key ) {
			delete_site_transient( $key );
		}
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'mainwp_restore_foreground_budget', array( $this, 'no_foreground_work' ) );
		$this->clear_settings_staff_fixtures();
		$this->tear_down_private_root();
		$this->clear_read_fixtures();
		$GLOBALS['wp']->query_vars = $this->saved['query_vars'];
		unset( $this->saved['query_vars'] );
		foreach ( $this->saved as $key => list( $exists, $value ) ) {
			if ( $exists ) {
				$GLOBALS[ $key ] = $value;
			} else {
				unset( $GLOBALS[ $key ] );
			}
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tearDown();
	}

	/**
	 * A progress read must not compete with a restore worker.
	 *
	 * @return int
	 */
	public function no_foreground_work(): int {
		return -1;
	}

	/**
	 * Each action withholds its distinguishing right.
	 *
	 * @return array Action and right.
	 */
	private static function paths(): array {
		return array(
			'mainwp_restore_snapshot_list'          => 'execute_backups',
			'mainwp_backup_snapshot_delete_preview' => 'execute_backups',
			'mainwp_backup_snapshot_delete'         => 'execute_backups',
			'mainwp_backup_snapshot_download_url'   => 'download_backups',
			'download_redeem'                       => 'download_backups',
			'mainwp_restore_initiate'               => 'restore_backups',
			'mainwp_restore_progress'               => 'execute_backups',
			'mainwp_restore_cancel'                 => 'restore_backups',
			'mainwp_restore_rollback'               => 'restore_backups',
			'mainwp_native_backup_initiate'         => 'execute_backups',
			'mainwp_native_backup_progress'         => 'execute_backups',
			'mainwp_native_backup_cancel'           => 'execute_backups',
			'mainwp_snapshots_cleanup'              => 'execute_backups',
		);
	}

	/**
	 * Invalid nonces extend the missing-nonce browser states.
	 *
	 * @return array
	 */
	public static function browser_paths(): array {
		$cases = array();
		foreach ( self::paths() as $action => $right ) {
			foreach ( array( 'application-password', 'application-password-cookie', 'cookie-nonce', 'cookie-only', 'cookie-invalid-nonce' ) as $state ) {
				foreach ( array( 'S+', 'S-right', 'S-site', 'A', 'N', 'N-sub' ) as $actor ) {
					$cases[ $action . '/' . $state . '/' . $actor ] = array( $action, $right, $state, $actor );
				}
			}
		}
		return $cases;
	}

	/**
	 * Staff retain the real role shape even when every backup right is granted.
	 *
	 * @param string $actor Actor code.
	 * @param string $missing Right withheld from S-right.
	 */
	private function actor( string $actor = 'S+', string $missing = 'execute_backups' ): void {
		$this->clear_access();
		$id = str_starts_with( $actor, 'S' ) ? $this->settings_actor( 'S' ) : self::factory()->user->create( array( 'role' => 'N-sub' === $actor ? 'subscriber' : 'administrator' ) );
		wp_set_current_user( $id );
		if ( str_starts_with( $actor, 'S' ) ) {
			$this->assertFalse( current_user_can( 'manage_options' ) );
			$this->assertTrue( MainWP_System_Utility::is_admin() );
		}
		$this->fixture['user'] = $id;
		$password              = \WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Snapshot browser rights' ) );
		$this->assertNotWPError( $password );
		$this->fixture['password'] = $password[0];
		$this->fixture['cookie']   = wp_generate_auth_cookie( $id, time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->access( $actor, $missing );
	}

	/**
	 * Both Team Control site filters must agree on the actor's scope.
	 *
	 * @param string $actor Actor code.
	 * @param string $missing Withheld right.
	 */
	private function access( string $actor, string $missing = 'execute_backups' ): void {
		$this->clear_access();
		if ( in_array( $actor, array( 'N', 'N-sub' ), true ) ) {
			return;
		}
		$site                 = $this->site_id;
		$rights               = static function ( $allowed, $type, $cap ) use ( $actor, $missing, $site ) {
			if ( 'A' === $actor ) {
				return true;
			}
			if ( 'site' === $type ) {
				return 'S-site' !== $actor && $site === (int) $cap;
			}
			return 'dashboard' === $type && in_array( $cap, array( 'execute_backups', 'download_backups', 'restore_backups' ), true ) && ( 'S-right' !== $actor || $missing !== $cap );
		};
		$scope                = static function () use ( $actor, $site ) {
			return 'A' === $actor ? 'all' : ( 'S-site' === $actor ? array() : array( $site ) );
		};
		$this->access_filters = array(
			'mainwp_currentusercan'                => $rights,
			'mainwp_currentuserallowedaccesssites' => $scope,
		);
		add_filter( 'mainwp_currentusercan', $rights, 10, 3 );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
	}

	/** Scope filters outlive the current user object. */
	private function clear_access(): void {
		foreach ( $this->access_filters as $hook => $filter ) {
			remove_filter( $hook, $filter );
		}
		$this->access_filters = array();
	}

	/**
	 * A previously issued token lets refusal cases run before UI issuance works.
	 *
	 * @param int $snapshot_id Stored snapshot identity.
	 * @return array
	 */
	private function issued_token( int $snapshot_id = 987673 ): array {
		$token          = wp_generate_password( 32, false );
		$key            = 'mainwp_backup_snapshot_download_' . hash( 'sha256', $token );
		$this->tokens[] = $key;
		$this->assertTrue(
			set_site_transient(
				$key,
				array(
					'user_id'     => $this->fixture['user'],
					'site_id'     => $this->site_id,
					'snapshot_id' => $snapshot_id,
					'file'        => 'full.zip',
					'expires'     => time() + 600,
				),
				600
			)
		);
		return array(
			'token'       => $token,
			'site_id'     => $this->site_id,
			'snapshot_id' => $snapshot_id,
			'file'        => 'full.zip',
			'_wpnonce'    => wp_create_nonce( 'mainwp_backup_snapshot_download' ),
		);
	}

	/**
	 * Refusals must leave database writes, scheduling, tokens and bytes untouched.
	 *
	 * @param callable $call Surface invocation.
	 * @param bool     $refused Whether authorization must fail.
	 * @return mixed
	 */
	private function observe( callable $call, bool $refused = true ) {
		$http   = 0;
		$writes = array();
		$deny   = static function () use ( &$http ) {
			++$http;
			return new \WP_Error( 'unexpected_http', 'No network in snapshot rights tests.' );
		};
		$query  = static function ( $sql ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		$tokens = array_map( 'get_site_transient', $this->tokens );
		$cron   = get_option( 'cron' );
		$bytes  = file_get_contents( $this->fixture['path'] );
		add_filter( 'pre_http_request', $deny );
		add_filter( 'mainwp_fetch_url_authed_pre', $deny );
		add_filter( 'query', $query );
		try {
			$result = $call();
		} finally {
			remove_filter( 'pre_http_request', $deny );
			remove_filter( 'mainwp_fetch_url_authed_pre', $deny );
			remove_filter( 'query', $query );
		}
		$this->assertSame( 0, $http, 'Authorization tests must never contact a Child or remote storage.' );
		if ( $refused ) {
			$this->assertSame( array(), $writes, 'An authorization refusal changed database state.' );
			$this->assertSame( $tokens, array_map( 'get_site_transient', $this->tokens ), 'A refused redemption consumed its token.' );
			$this->assertSame( $cron, get_option( 'cron' ) );
			$this->assertSame( $bytes, file_get_contents( $this->fixture['path'] ) );
		}
		return $result;
	}

	/**
	 * IDs stay valid so the request reaches authorization before business checks.
	 *
	 * @param string $action AJAX action.
	 * @param string $state Browser state.
	 * @param array  $input Input overrides.
	 * @return array
	 */
	private function request( string $action, string $state = 'cookie-nonce', array $input = array() ): array {
		$this->authenticate( $state );
		$_POST = array_merge(
			array(
				'site_id'               => (string) $this->site_id,
				'snapshot_id'           => '987673',
				'job_id'                => '987674',
				'expected_snapshot_ids' => array( '987673', '999999999' ),
				'delete_cloud_copies'   => '0',
				'keep'                  => '1',
			),
			$input
		);
		if ( 'cookie-nonce' === $state ) {
			$_POST['security'] = wp_create_nonce( $action );
		} elseif ( 'cookie-invalid-nonce' === $state ) {
			$_POST['security'] = 'invalid-nonce';
		}
		$_REQUEST = $_POST;
		return $this->ajax( array( $this->ajax_handler(), $action ) );
	}

	/**
	 * The expected successes are literal payloads or refusals beyond authorization.
	 *
	 * @param string $action Native surface.
	 * @param mixed  $result Response.
	 */
	private function assert_admitted( string $action, $result ): void {
		switch ( $action ) {
			case 'mainwp_restore_snapshot_list':
				$this->assertSame( array( 987673 ), array_column( $result['snapshots'], 'id' ) );
				break;
			case 'mainwp_backup_snapshot_delete_preview':
				$this->assertSame( array( 987673 ), $result['would_affect']['snapshot_ids'] );
				$this->assertTrue( $result['dry_run'] );
				break;
			case 'mainwp_backup_snapshot_delete':
				$this->assertSame( 'changed', $result['status'] );
				$this->assertSame( array( 987673 ), $result['preview']['would_affect']['snapshot_ids'] );
				$this->assertSame( array(), $result['deleted_ids'] );
				break;
			case 'mainwp_backup_snapshot_download_url':
				$this->assertArrayHasKey( 'url', $result );
				$this->track_link( $result['url'] );
				break;
			case 'download_redeem':
				$this->assertSame( realpath( $this->fixture['path'] ), $result );
				break;
			case 'mainwp_restore_initiate':
				$this->assertTrue( $result['needs_confirmation'] );
				break;
			case 'mainwp_restore_progress':
				$this->assertSame( 'full', $result['sub_type'] );
				$this->assertSame( 1, $result['artifact_total'] );
				$this->assertSame( 'completed', $result['status'] );
				break;
			case 'mainwp_restore_cancel':
				$this->assertSame( 'This restore has already finished on the site and can no longer be cancelled. If it failed, you can roll it back instead.', $result['error'] );
				break;
			case 'mainwp_restore_rollback':
				$this->assertSame( 'mainwp_restore_rollback_status', $result['error_code'] );
				$this->assertSame( 'No safety backup was taken before this restore, so there is nothing to roll back to.', $result['error'] );
				break;
			case 'mainwp_native_backup_initiate':
				$this->assertSame( 'This site is disconnected from the Dashboard. Reconnect it, then try again.', $result['error'] );
				break;
			case 'mainwp_native_backup_progress':
				$this->assertSame( 'completed', $result['status'] );
				$this->assertSame( 987673, $result['snapshot_id'] );
				break;
			case 'mainwp_native_backup_cancel':
				$this->assertSame(
					array(
						'status' => 'not_running',
						'result' => 'not_running',
					),
					$result
				);
				break;
			case 'mainwp_snapshots_cleanup':
				$this->assertSame( 'ok', $result['result'] );
				$this->assertSame( array(), $result['deleted_ids'] );
				$this->assertSame( 0, $result['snapshots_pruned'] );
				break;
			default:
				$this->fail( 'Missing authorization proof for ' . $action );
		}
	}

	/**
	 * Every native browser surface must honor the same role, nonce and scope rules.
	 *
	 * @dataProvider browser_paths
	 * @param string $action Surface.
	 * @param string $right Distinguishing right.
	 * @param string $state Authentication state.
	 * @param string $actor Actor.
	 */
	public function test_native_ui_auth_matrix( string $action, string $right, string $state, string $actor ): void {
		$this->actor( $actor, $right );
		$pass = 'cookie-nonce' === $state && in_array( $actor, array( 'S+', 'A', 'N' ), true );
		if ( 'mainwp_native_backup_initiate' === $action ) {
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'sync_errors' => 'Disconnected fixture' ), array( 'wpid' => $this->site_id ) );
		}
		if ( 'download_redeem' === $action ) {
			$this->authenticate( 'cookie-nonce' );
			$query = $this->issued_token();
			$this->authenticate( $state );
			if ( 'cookie-nonce' !== $state ) {
				$query['_wpnonce'] = 'cookie-invalid-nonce' === $state ? 'invalid-nonce' : '';
			}
			$result = $this->observe( static fn() => MainWP_Backup_Download::redeem_download( $query ), ! $pass );
			if ( $pass ) {
				$this->assert_admitted( $action, $result );
				$this->assertFalse( get_site_transient( end( $this->tokens ) ) );
			} else {
				$this->assertWPError( $result );
				$permission = 'application-password' === $state || 'cookie-nonce' === $state;
				$this->assertSame( 'mainwp_backup_download_' . ( $permission ? 'permission' : 'link' ), $result->get_error_code() );
				if ( $permission ) {
					$this->assertSame( 'You do not have permission to download this backup.', $result->get_error_message() );
				}
			}
			return;
		}
		// Resolve credentials before observing writes by the handler itself.
		$this->authenticate( $state );
		$result = $this->observe( fn() => $this->request( $action, $state ), ! $pass );
		if ( $pass ) {
			$this->assert_admitted( $action, $result );
		} elseif ( 'cookie-nonce' !== $state || 'N-sub' === $actor ) {
			$this->assertSame( array(), $result );
		} elseif ( 'mainwp_backup_snapshot_download_url' === $action ) {
			$this->assertSame( array( 'error' => 'You do not have permission to download this backup.' ), $result );
		} elseif ( in_array( $action, array( 'mainwp_restore_progress', 'mainwp_restore_cancel', 'mainwp_restore_rollback' ), true ) ) {
			$this->assertSame( array( 'error' => 'Restore job not found.' ) + ( 'mainwp_restore_rollback' === $action ? array( 'retryable' => false ) : array() ), $result );
		} elseif ( str_starts_with( $action, 'mainwp_backup_snapshot_delete' ) ) {
			$this->assertContains( $result['error_code'], array( 'mainwp_permission_denied', 'S-site' === $actor ? 'mainwp_site_not_found' : 'mainwp_insufficient_right' ) );
			$messages = array(
				'mainwp_permission_denied'  => 'You do not have permission to perform this action.',
				'mainwp_insufficient_right' => 'Your role does not permit this operation.',
				'mainwp_site_not_found'     => 'No site found with ID 987670.',
			);
			$this->assertSame( $messages[ $result['error_code'] ], $result['error'] );
		} else {
			$this->assertSame( array( 'error' => 'You are not allowed to manage backups for this site.' ), $result );
		}
	}

	/** The list must carry snapshot details rather than an empty table. */
	public function test_staff_list_contains_snapshot_rows(): void {
		$this->actor();
		$result = $this->observe( fn() => $this->request( 'mainwp_restore_snapshot_list' ), false );
		$this->assert_admitted( 'mainwp_restore_snapshot_list', $result );
		$this->assertSame( 'full', $result['snapshots'][0]['type'] );
		$this->assertSame( '13.00B', $result['snapshots'][0]['size_label'] );
	}

	/** A real issued link remains bound to its staff user and is consumed once. */
	public function test_staff_download_round_trip_and_user_binding(): void {
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$url = $this->observe( static fn() => MainWP_Backup_Download::create_download_url( 987673 ), false );
		$this->assertIsString( $url );
		$query = $this->track_link( $url );
		$user  = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$other             = $query;
		$other['_wpnonce'] = wp_create_nonce( 'mainwp_backup_snapshot_download' );
		$result            = $this->observe( static fn() => MainWP_Backup_Download::redeem_download( $other ) );
		$this->assertSame( 'mainwp_backup_download_link', $result->get_error_code() );
		wp_set_current_user( $user );
		$this->assertSame( realpath( $this->fixture['path'] ), $this->observe( static fn() => MainWP_Backup_Download::redeem_download( $query ), false ) );
		$this->assertFalse( get_site_transient( end( $this->tokens ) ) );
	}

	/** Progress is a read: staff without restore_backups still follow a restore. */
	public function test_progress_needs_no_restore_right(): void {
		$this->actor( 'S-right', 'restore_backups' );
		$this->assertWPError( MainWP_Backup_Operations::authorize_ui( 'restore_backups' ) );
		$result = $this->observe( fn() => $this->request( 'mainwp_restore_progress' ), false );
		$this->assert_admitted( 'mainwp_restore_progress', $result );
	}

	/** Withholding only download_backups must preserve the issued token. */
	public function test_missing_download_right_preserves_token(): void {
		$this->actor( 'S-right', 'download_backups' );
		$this->authenticate( 'cookie-nonce' );
		$query = $this->issued_token();
		foreach ( array( static fn() => MainWP_Backup_Download::create_download_url( 987673 ), static fn() => MainWP_Backup_Download::redeem_download( $query ) ) as $call ) {
			$result = $this->observe( $call );
			$this->assertSame( 'mainwp_backup_download_permission', $result->get_error_code() );
			$this->assertSame( 'You do not have permission to download this backup.', $result->get_error_message() );
		}
	}

	/**
	 * Every entity surface must conceal inaccessible identities.
	 *
	 * @return array
	 */
	public static function entity_paths(): array {
		return array_map( static fn( $action ) => array( $action ), array_keys( self::paths() ) );
	}

	/**
	 * A real out-of-scope entity and a missing one produce identical responses.
	 *
	 * @dataProvider entity_paths
	 * @param string $action Native surface.
	 */
	public function test_hidden_entity_matches_missing( string $action ): void {
		$this->actor( 'S-site' );
		$this->authenticate( 'cookie-nonce' );
		if ( 'download_redeem' === $action ) {
			$hidden  = $this->issued_token();
			$missing = $this->issued_token( 999999999 );
			$a       = $this->observe( static fn() => MainWP_Backup_Download::redeem_download( $hidden ) );
			$b       = $this->observe( static fn() => MainWP_Backup_Download::redeem_download( $missing ) );
			$this->assertEquals( $a, $b );
		} else {
			$a            = $this->observe( fn() => $this->request( $action ) );
			$missing_site = str_starts_with( $action, 'mainwp_backup_snapshot_delete' ) ? (string) $this->site_id : '999999999';
			$b            = $this->observe(
				fn() => $this->request(
					$action,
					'cookie-nonce',
					array(
						'site_id'     => $missing_site,
						'snapshot_id' => '999999999',
						'job_id'      => '999999999',
					)
				)
			);
			$this->assertSame( $a, $b );
		}
	}

	/** A hidden snapshot must stay opaque even when the submitted site is allowed. */
	public function test_cross_site_snapshot_matches_missing_on_allowed_site(): void {
		global $wpdb;
		$other = $this->create_test_site();
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $other ), array( 'id' => 987673 ) );
		$this->actor();
		foreach ( array( 'mainwp_backup_snapshot_delete_preview', 'mainwp_backup_snapshot_delete', 'mainwp_restore_initiate', 'mainwp_backup_snapshot_download_url' ) as $action ) {
			$a = $this->observe( fn() => $this->request( $action ) );
			$b = $this->observe( fn() => $this->request( $action, 'cookie-nonce', array( 'snapshot_id' => '999999999' ) ) );
			$this->assertSame( $a, $b, $action );
		}
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $this->site_id ), array( 'id' => 987673 ) );
	}

	/** Default, CLI and unrecognized contexts must retain the manage_options gate. */
	public function test_staff_ability_and_non_ui_contexts_stay_denied(): void {
		$this->actor();
		$calls = array(
			static fn() => MainWP_Backup_Operations::authorize_site( 987670, 'execute_backups' ),
			static fn() => MainWP_Backup_Operations::authorize_snapshot( 987673 ),
			static fn() => MainWP_Backup_Operations::authorize_restore_job( 987674, true ),
			static fn() => MainWP_Backup_Operations::cancel_site_backup( 987670 ),
			static fn() => MainWP_Backup_Operations::preview_delete_snapshot( 987670, 987673 ),
			static fn() => MainWP_Backup_Operations::delete_snapshot( 987670, 987673, array( 987673 ) ),
			static fn() => MainWP_Backup_Operations::cleanup_site_backups( 987670, 1 ),
			static fn() => MainWP_Backup_Operations::rollback_restore( 987674 ),
		);
		foreach ( array( 'ability', 'cli', 'UI' ) as $context ) {
			$calls[] = static fn() => MainWP_Backup_Operations::authorize_site( 987670, 'execute_backups', $context );
			$calls[] = static fn() => MainWP_Backup_Operations::authorize_snapshot( 987673, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::authorize_restore_job( 987674, true, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::restore_snapshot( 987670, 987673, array(), $context );
		}
		foreach ( array( 'ability', 'cli' ) as $context ) {
			$calls[] = static fn() => MainWP_Backup_Operations::start_site_backup( 987670, $context );
		}
		foreach ( $calls as $call ) {
			$result = $this->observe( $call );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
		}
		$method = new \ReflectionMethod( MainWP_Abilities_Backups::class, 'permission_callback' );
		$method->setAccessible( true );
		foreach ( array( 'list-backup-snapshots', 'get-backup-snapshot', 'list-restore-jobs', 'get-restore-job', 'delete-backup-snapshot', 'restore-site-snapshot', 'rollback-restore', 'cancel-restore' ) as $ability ) {
			$callback = $method->invoke( null, 'mainwp/' . $ability . '-v1' );
			$result   = $this->observe(
				static fn() => $callback(
					array(
						'site_id_or_domain' => 987670,
						'snapshot_id'       => 987673,
						'restore_job_id'    => 987674,
					)
				)
			);
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code(), $ability );
		}
	}

	/**
	 * Browser identity and Dashboard role precede granular rights.
	 *
	 * @return array
	 */
	public static function ui_actors(): array {
		return array(
			'logged out' => array( 'logged-out', 'mainwp_permission_denied', 401 ),
			'subscriber' => array( 'N-sub', 'mainwp_permission_denied', 403 ),
			'no right'   => array( 'S-right', 'mainwp_insufficient_right', 403 ),
			'staff'      => array( 'S+', '', 0 ),
			'admin'      => array( 'A', '', 0 ),
			'default'    => array( 'N', '', 0 ),
		);
	}

	/**
	 * The UI gate never relies on manage_options or permissive default filters.
	 *
	 * @dataProvider ui_actors
	 * @param string $actor Actor.
	 * @param string $code Expected error code, or empty for success.
	 * @param int    $status Expected HTTP status.
	 */
	public function test_authorize_ui( string $actor, string $code, int $status ): void {
		$this->actor( 'logged-out' === $actor ? 'S+' : $actor );
		if ( 'logged-out' === $actor ) {
			wp_set_current_user( 0 );
		}
		$this->assertTrue( is_callable( array( MainWP_Backup_Operations::class, 'authorize_ui' ) ) );
		$result = $this->observe( static fn() => MainWP_Backup_Operations::authorize_ui( 'execute_backups' ), '' !== $code );
		if ( '' === $code ) {
			$this->assertTrue( $result );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertSame( $status, $result->get_error_data()['status'] );
			if ( 'S-right' === $actor ) {
				$this->assertSame( 'execute_backups', $result->get_error_data()['right'] );
				$this->assertSame( 'Your role does not permit this operation.', $result->get_error_message() );
			}
		}
	}

	/** Native access must not change the legacy archive gate. */
	public function test_staff_legacy_archive_refresh_stays_denied(): void {
		$this->actor();
		$this->assertSame(
			array( 'error' => 'You are not allowed to manage backups for this site.' ),
			$this->observe( fn() => $this->request( 'mainwp_backup_refresh_archives' ) )
		);
	}

	/**
	 * Application passwords are not API credentials on browser endpoints.
	 *
	 * @param string $state Authentication state.
	 */
	private function authenticate( string $state ): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$GLOBALS['wp_rest_auth_cookie']                 = null;
		$GLOBALS['wp_rest_application_password_status'] = null;
		$GLOBALS['wp_rest_application_password_uuid']   = null;
		if ( 'application-password' !== $state ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->fixture['cookie'];
		}
		if ( str_starts_with( $state, 'application-password' ) ) {
			$_SERVER['PHP_AUTH_USER'] = get_userdata( $this->fixture['user'] )->user_login;
			$_SERVER['PHP_AUTH_PW']   = $this->fixture['password'];
		}
		unset( $GLOBALS['current_user'] );
		$id = (int) apply_filters( 'determine_current_user', false );
		$this->assertSame( 'application-password' === $state ? 0 : $this->fixture['user'], $id );
		$GLOBALS['current_user'] = new \WP_User( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retain the identity core resolved without calling wp_set_current_user().
		$this->assertFalse( $GLOBALS['wp_rest_application_password_status'] instanceof \WP_User );
		$this->assertNotFalse( has_filter( 'determine_current_user', 'wp_validate_application_password' ) );
		$this->assertNotFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
	}

	/**
	 * Literal die calls need a returning seam so PHPUnit can observe refusals.
	 *
	 * @return MainWP_Post_Backup_Handler
	 */
	private function ajax_handler(): MainWP_Post_Backup_Handler {
		return new class() extends MainWP_Post_Backup_Handler {
			/**
			 * Preserve the base guard before its literal die.
			 *
			 * @param string $action Nonce action.
			 * @param string $query_arg Nonce field.
			 * @throws \RuntimeException When no browser user is authorized.
			 */
			public function secure_request( $action = '', $query_arg = 'security' ) {
				if ( ! MainWP_System_Utility::is_admin() ) {
					throw new \RuntimeException( 'no user' );
				}
				parent::secure_request( $action, $query_arg );
			}

			/**
			 * Retain the real nonce check while replacing its literal die.
			 *
			 * @param string $action Nonce action.
			 * @param string $query_arg Nonce field.
			 * @param bool   $out_die Whether refusal terminates.
			 * @return bool
			 * @throws \RuntimeException When the nonce is absent or invalid.
			 */
			public function check_security( $action = -1, $query_arg = 'security', $out_die = true ) {
				if ( ! parent::check_security( $action, $query_arg, false ) ) {
					throw new \RuntimeException( 'nonce' );
				}
				return true;
			}
		};
	}

	/**
	 * Capture JSON without replacing authentication or operation rights.
	 *
	 * @param callable $callback Handler invocation.
	 * @return array
	 */
	private function ajax( callable $callback ): array {
		$die = static function () {
			return static function () {
				throw new \RuntimeException( 'json' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			$callback();
		} catch ( \RuntimeException $error ) {
			$this->assertContains( $error->getMessage(), array( 'json', 'no user', 'nonce' ) );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_die_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true ) ?? array();
	}

	/**
	 * Token expiry and bindings stay real across the next simulated request.
	 *
	 * @param string $url Issued URL.
	 * @return array
	 */
	private function track_link( string $url ): array {
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->tokens[] = ( new \ReflectionClassConstant( MainWP_Backup_Download::class, 'TRANSIENT_PREFIX' ) )->getValue() . hash( 'sha256', $query['token'] );
		return $query;
	}
}
