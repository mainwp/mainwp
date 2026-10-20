<?php
/**
 * Scheduled backup browser rights and site scope for staff without manage_options.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Page;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Storage_View;
use MainWP\Dashboard\MainWP_Backup_Task_Form;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Utility;

require_once __DIR__ . '/class-mainwp-backup-settings-staff-fixtures.php';

/** Browser admission must precede any task, poll or cancellation mutation. */
class MainWP_Backup_Task_UI_Staff_Rights_Test extends MainWP_Abilities_Test_Case {

	use Backup_Settings_Staff_Fixtures;

	/**
	 * Request globals restored between cases.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Actor credentials, two sites and task identities.
	 *
	 * @var array
	 */
	private $fixture = array();

	/**
	 * Team Control filters owned by the current actor.
	 *
	 * @var array
	 */
	private $access_filters = array();

	/**
	 * Raw response used to catch existence oracles including JSON shape.
	 *
	 * @var string
	 */
	private $last_body = '';

	/**
	 * Custom table rows are cleaned even on testbeds without transactional tables.
	 *
	 * @var array
	 */
	private $task_ids = array();

	/** Seed tasks as an administrator before granting any staff scope. */
	public function setUp(): void {
		parent::setUp();
		$this->skip_when_capabilities_bypassed();
		foreach ( array( '_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', 'current_user', 'current_screen', 'wp_rest_auth_cookie', 'wp_rest_application_password_status', 'wp_rest_application_password_uuid' ) as $key ) {
			$this->saved[ $key ] = array( array_key_exists( $key, $GLOBALS ), $GLOBALS[ $key ] ?? null );
		}
		$this->saved['query_vars'] = $GLOBALS['wp']->query_vars;
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core must resolve the browser cookie outside a simulated admin screen.
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		delete_site_option( MainWP_Install::BACKUP_TASK_RUN_CANCEL_REPAIR_PENDING );
		$this->fixture['a'] = $this->create_test_site( array( 'name' => 'Staff site A' ) );
		$this->fixture['b'] = $this->create_test_site( array( 'name' => 'Staff site B' ) );
		foreach ( array(
			'ta'       => array( 'a' ),
			'tab'      => array( 'a', 'b' ),
			'tb'       => array( 'b' ),
			'template' => array( 'b' ),
		) as $key => $sites ) {
			$ids  = array_map( fn( $site ) => $this->fixture[ $site ], $sites );
			$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Staff task ' . $key, 'daily', 'full', '', implode( ',', $ids ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
			$this->assertNotFalse( $task );
			$this->fixture[ $key ] = (int) $task->id;
			$this->task_ids[]      = (int) $task->id;
			$this->update(
				$task->id,
				array(
					'authorized_sites' => implode( ',', $ids ),
					'template'         => 'template' === $key ? 1 : 0,
					'last_run'         => 1700000000,
					'completed'        => 1700000001,
				)
			);
		}
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $this->fixture['a'],
					'task_id'  => $this->fixture['tab'],
					'type'     => 'full',
					'created'  => time(),
					'archives' => '[]',
				)
			)
		);
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Role caches and browser state must not outlive the fixture. */
	public function tearDown(): void {
		if ( ! array_key_exists( 'query_vars', $this->saved ) ) {
			parent::tearDown();
			return;
		}
		$this->clear_access();
		global $wpdb;
		foreach ( array_unique( $this->task_ids ) as $id ) {
			foreach ( array( 'wp_backup_progress', 'wp_backup_snapshot', 'wp_backup_run_history' ) as $table ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( $table ), array( 'task_id' => $id ) );
			}
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $id ) );
		}
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->clear_settings_staff_fixtures();
		delete_option( 'mainwp_enableNativeBackups' );
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
	 * Keep fixture writes explicit and checked.
	 *
	 * @param int   $id Task identity.
	 * @param array $values Stored overrides.
	 */
	private function update( int $id, array $values ): void {
		$this->assertNotFalse( MainWP_DB_Backup::instance()->update_backup_task_with_values( $id, $values ) );
	}

	/**
	 * Registration rights remain those of the browser action.
	 *
	 * @return array
	 */
	private static function paths(): array {
		return array(
			'mainwp_addbackup'                     => 'add_backup_tasks',
			'mainwp_updatebackup'                  => 'edit_backup_tasks',
			'mainwp_removebackup'                  => 'delete_backup_tasks',
			'mainwp_pausebackup'                   => 'pause_resume_backup_tasks',
			'mainwp_resumebackup'                  => 'pause_resume_backup_tasks',
			'mainwp_native_backup_task_reset'      => 'pause_resume_backup_tasks',
			'mainwp_native_backup_task_cancel_run' => 'pause_resume_backup_tasks',
			'mainwp_backuptask_run_now'            => 'run_backup_tasks',
			'mainwp_backuptask_run_status'         => 'run_backup_tasks',
		);
	}

	/**
	 * Nonce and role failures must be checked on all nine actions.
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
	 * Staff hold only read and level_10 at WordPress level.
	 *
	 * @param string $actor Actor code.
	 * @param string $missing Withheld Dashboard right.
	 */
	private function actor( string $actor = 'S+', string $missing = '' ): void {
		$this->clear_access();
		$id = str_starts_with( $actor, 'S' ) ? $this->settings_actor( 'S' ) : self::factory()->user->create( array( 'role' => 'N-sub' === $actor ? 'subscriber' : 'administrator' ) );
		wp_set_current_user( $id );
		if ( str_starts_with( $actor, 'S' ) ) {
			$this->assertFalse( current_user_can( 'manage_options' ) );
			$this->assertTrue( MainWP_System_Utility::is_admin() );
		}
		$this->fixture['user'] = $id;
		$password              = \WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Task browser rights' ) );
		$this->assertNotWPError( $password );
		$this->fixture['password'] = $password[0];
		$this->fixture['cookie']   = wp_generate_auth_cookie( $id, time() + HOUR_IN_SECONDS, 'logged_in' );
		if ( ! in_array( $actor, array( 'N', 'N-sub' ), true ) ) {
			$sites                = 'S-site' === $actor ? array( $this->fixture['a'] ) : array( $this->fixture['a'], $this->fixture['b'] );
			$rights               = static function ( $allowed, $type, $cap ) use ( $actor, $missing, $sites ) {
				if ( 'A' === $actor ) {
					return true;
				}
				if ( 'site' === $type ) {
					return in_array( (int) $cap, $sites, true );
				}
				return 'dashboard' === $type && in_array( $cap, array( 'execute_backups', 'add_backup_tasks', 'edit_backup_tasks', 'delete_backup_tasks', 'pause_resume_backup_tasks', 'run_backup_tasks' ), true ) && ( 'S-right' !== $actor || $cap !== $missing );
			};
			$scope                = static fn() => 'A' === $actor ? 'all' : $sites;
			$this->access_filters = array(
				'mainwp_currentusercan'                => $rights,
				'mainwp_currentuserallowedaccesssites' => $scope,
			);
			add_filter( 'mainwp_currentusercan', $rights, 10, 3 );
			add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/** Scope memoization must not carry rights from another actor. */
	private function clear_access(): void {
		foreach ( $this->access_filters as $hook => $filter ) {
			remove_filter( $hook, $filter );
		}
		$this->access_filters = array();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
	}

	/**
	 * Refused requests must not mutate any SQL state or contact a provider.
	 *
	 * @param callable $call Request invocation.
	 * @param bool     $refused Whether admission must fail.
	 * @return mixed
	 */
	private function observe( callable $call, bool $refused = true ) {
		$http   = 0;
		$writes = array();
		$deny   = static function () use ( &$http ) {
			++$http;
			return new \WP_Error( 'unexpected_http', 'No network in task rights tests.' );
		};
		$query  = static function ( $sql ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		global $wpdb;
		$table      = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$before     = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
		$cron       = get_option( 'cron' );
		$transients = array();
		$changed    = static function ( $key ) use ( &$transients ) {
			$transients[] = $key;
		};
		foreach ( array( 'set_transient', 'deleted_transient', 'set_site_transient', 'deleted_site_transient' ) as $hook ) {
			add_action( $hook, $changed );
		}
		add_filter( 'pre_http_request', $deny );
		add_filter( 'mainwp_fetch_url_authed_pre', $deny );
		add_filter( 'query', $query );
		try {
			$result = $call();
		} finally {
			remove_filter( 'pre_http_request', $deny );
			remove_filter( 'mainwp_fetch_url_authed_pre', $deny );
			remove_filter( 'query', $query );
			foreach ( array( 'set_transient', 'deleted_transient', 'set_site_transient', 'deleted_site_transient' ) as $hook ) {
				remove_action( $hook, $changed );
			}
		}
		$after          = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
		$this->task_ids = array_merge( $this->task_ids, array_map( 'intval', array_diff( array_column( $after, 'id' ), array_column( $before, 'id' ) ) ) );
		$this->assertSame( 0, $http, 'Authorization must finish before any remote request.' );
		if ( $refused ) {
			$this->assertSame( array(), $writes, 'A refusal wrote task, run, cancellation, transient or cron state.' );
			$this->assertSame( $before, $after );
			$this->assertSame( array(), $transients, 'A refusal changed transient state.' );
			$this->assertSame( $cron, get_option( 'cron' ) );
		}
		return $result;
	}

	/**
	 * Paused run answers and closed reset/cancel answers require no Child work.
	 *
	 * @param string $action Action.
	 */
	private function prepare( string $action ): void {
		$this->update( $this->fixture['tab'], array( 'paused' => in_array( $action, array( 'mainwp_resumebackup', 'mainwp_backuptask_run_now', 'mainwp_backuptask_run_status' ), true ) ? 1 : 0 ) );
	}

	/**
	 * Exercise the real registration decisions without polluting global callbacks.
	 *
	 * @param string $action Action.
	 * @param string $state Authentication state.
	 * @param array  $input Input overrides.
	 * @return array
	 */
	private function request( string $action, string $state = 'cookie-nonce', array $input = array() ): array {
		$_POST = array_merge(
			array(
				'id'               => $this->fixture['tab'],
				'task_id'          => $this->fixture['tab'],
				'name'             => 'Staff submitted task',
				'type'             => 'full',
				'schedule'         => 'daily',
				'sites'            => array( $this->fixture['a'], $this->fixture['b'] ),
				'archiveFormat'    => 'zip',
				'delete_snapshots' => '0',
				'run'              => 1700000000,
				'started'          => 0,
				'click'            => time(),
				'initiation_id'    => 'c536fcec-3ade-4e19-8153-113c097b14cf',
			),
			$input
		);
		if ( 'cookie-nonce' === $state ) {
			$_POST['security'] = wp_create_nonce( $action );
		} elseif ( 'cookie-invalid-nonce' === $state ) {
			$_POST['security'] = 'invalid-nonce';
		}
		$_REQUEST = $_POST;
		$handler  = $this->ajax_handler();
		$hooks    = array();
		foreach ( array( 'admin_post_mainwp_backup_diagnostics_download', 'admin_post_mainwp_backup_snapshot_download', 'admin_post_nopriv_mainwp_backup_snapshot_download', 'mainwp_delete_site' ) as $hook ) {
			$hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
		}
		try {
			$handler->init();
		} finally {
			foreach ( $hooks as $hook => $original ) {
				if ( null === $original ) {
					unset( $GLOBALS['wp_filter'][ $hook ] );
				} else {
					$GLOBALS['wp_filter'][ $hook ] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the callbacks present before registration.
				}
			}
		}
		if ( ! isset( $handler->registered[ $action ] ) ) {
			$this->last_body = '0';
			return array();
		}
		return $this->ajax( $handler->registered[ $action ] );
	}

	/**
	 * Literal payloads prove admission without treating any error as a pass.
	 *
	 * @param string $action Action.
	 * @param array  $result Response.
	 */
	private function assert_admitted( string $action, array $result ): void {
		if ( 'mainwp_addbackup' === $action || 'mainwp_updatebackup' === $action ) {
			$this->assertSame( array( 'result' => 'mainwp_addbackup' === $action ? 'Task created successfully.' : 'Task updated successfully.' ), $result );
		} elseif ( in_array( $action, array( 'mainwp_removebackup', 'mainwp_pausebackup', 'mainwp_resumebackup' ), true ) ) {
			$this->assertSame( array( 'result' => 'SUCCESS' ), $result );
		} elseif ( str_starts_with( $action, 'mainwp_native_backup_task_' ) ) {
			$this->assertSame( array( 'error' => 'This task is not currently running.' ), $result );
		} else {
			$this->assertSame( array( 'error' => 'This task is paused. Resume it before running it.' ), $result );
		}
	}

	/**
	 * Real role, site and nonce combinations cover every browser entry.
	 *
	 * @dataProvider browser_paths
	 * @param string $action Action.
	 * @param string $right Required right.
	 * @param string $state Browser authentication.
	 * @param string $actor Actor.
	 */
	public function test_task_browser_auth_matrix( string $action, string $right, string $state, string $actor ): void {
		$this->prepare( $action );
		$this->actor( $actor, $right );
		$this->authenticate( $state );
		$pass   = 'cookie-nonce' === $state && in_array( $actor, array( 'S+', 'A', 'N' ), true );
		$result = $this->observe( fn() => $this->request( $action, $state ), ! $pass );
		if ( $pass ) {
			$this->assert_admitted( $action, $result );
		} elseif ( 'cookie-nonce' !== $state || in_array( $actor, array( 'S-right', 'N-sub' ), true ) ) {
			$this->assertSame( array(), $result );
		} else {
			$this->assertTrue( isset( $result['error'] ) || ! empty( $result['notask'] ), 'Scope refusal missing.' );
		}
	}

	/**
	 * Add has no entity id; its submitted scope is tested separately.
	 *
	 * @return array
	 */
	public static function entity_paths(): array {
		$paths = array_keys( self::paths() );
		array_shift( $paths );
		return array_map( static fn( $action ) => array( $action ), $paths );
	}

	/**
	 * Hidden, empty and template tasks must have byte-identical missing responses.
	 *
	 * @dataProvider entity_paths
	 * @param string $action Action.
	 */
	public function test_hidden_task_matches_missing( string $action ): void {
		$this->prepare( $action );
		$this->actor( 'S-site' );
		$this->authenticate( 'cookie-nonce' );
		$this->observe( fn() => $this->request( $action ) );
		$hidden = $this->last_body;
		if ( in_array( $action, array( 'mainwp_removebackup', 'mainwp_pausebackup', 'mainwp_resumebackup' ), true ) ) {
			$this->assertSame( array( 'notask' => true ), json_decode( $hidden, true ) );
		} elseif ( in_array( $action, array( 'mainwp_updatebackup', 'mainwp_backuptask_run_now', 'mainwp_backuptask_run_status' ), true ) ) {
			$this->assertSame( array( 'error' => 'Insufficient permissions. Is this task set by you?' ), json_decode( $hidden, true ) );
		}
		$this->observe(
			fn() => $this->request(
				$action,
				'cookie-nonce',
				array(
					'id'      => 999999999,
					'task_id' => 999999999,
				)
			)
		);
		$this->assertSame( $hidden, $this->last_body );
	}

	/** Empty and template run sets must not become staff authorization loopholes. */
	public function test_empty_and_template_tasks_match_missing(): void {
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$this->update(
			$this->fixture['tb'],
			array(
				'sites'            => '999999998',
				'authorized_sites' => '999999998',
			)
		);
		foreach ( self::entity_paths() as list( $action ) ) {
			$this->observe(
				fn() => $this->request(
					$action,
					'cookie-nonce',
					array(
						'id'      => 999999999,
						'task_id' => 999999999,
					)
				)
			);
			$missing = $this->last_body;
			foreach ( array( 'tb', 'template' ) as $key ) {
				$id = $this->fixture[ $key ];
				$this->observe(
					fn() => $this->request(
						$action,
						'cookie-nonce',
						array(
							'id'      => $id,
							'task_id' => $id,
						)
					)
				);
				$this->assertSame( $missing, $this->last_body, $action . '/' . $key );
			}
		}
	}

	/** A new selection never grants access beyond the staff's two allowed sites. */
	public function test_add_rejects_submitted_site_outside_scope(): void {
		$outside = $this->create_test_site();
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$result = $this->observe( fn() => $this->request( 'mainwp_addbackup', 'cookie-nonce', array( 'sites' => array( $outside ) ) ) );
		$this->assertArrayHasKey( 'error', $result );
	}

	/** An already visible task may report a bad replacement selection. */
	public function test_visible_task_rejects_new_hidden_selection(): void {
		$this->actor( 'S-site' );
		$this->authenticate( 'cookie-nonce' );
		$result = $this->observe( fn() => $this->request( 'mainwp_updatebackup', 'cookie-nonce', array( 'id' => $this->fixture['ta'] ) ) );
		$this->assertSame( array( 'error' => 'You are not allowed to schedule backups for one or more of the selected sites.' ), $result );
	}

	/** History scope must remain enforced when staff can edit the current run sites. */
	public function test_visible_task_cannot_change_hidden_history(): void {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $this->fixture['b'],
					'task_id'  => $this->fixture['ta'],
					'type'     => 'full',
					'created'  => time(),
					'archives' => '[]',
				)
			)
		);
		$this->actor( 'S-site' );
		$this->authenticate( 'cookie-nonce' );
		foreach ( array( 'mainwp_updatebackup', 'mainwp_removebackup' ) as $action ) {
			$result = $this->observe(
				fn() => $this->request(
					$action,
					'cookie-nonce',
					array(
						'id'    => $this->fixture['ta'],
						'sites' => array( $this->fixture['a'] ),
					)
				)
			);
			$this->assertSame( array( 'error' => 'You are not allowed to schedule backups for one or more of the selected sites.' ), $result );
		}
	}

	/**
	 * Both starting entry points must make the same admission decision.
	 *
	 * @return array
	 */
	public static function run_actors(): array {
		return array( array( 'S+' ), array( 'S-right' ), array( 'S-site' ), array( 'A' ), array( 'N' ) );
	}

	/**
	 * A refused poll cannot claim with a valid click or consume an acknowledged token.
	 *
	 * @dataProvider run_actors
	 * @param string $actor Actor.
	 */
	public function test_run_now_and_status_agree( string $actor ): void {
		$this->prepare( 'mainwp_backuptask_run_now' );
		$this->actor( $actor, 'run_backup_tasks' );
		$this->authenticate( 'cookie-nonce' );
		$refused = in_array( $actor, array( 'S-right', 'S-site' ), true );
		$now     = $this->observe( fn() => $this->request( 'mainwp_backuptask_run_now' ), $refused );
		$raw     = $this->last_body;
		$status  = $this->observe( fn() => $this->request( 'mainwp_backuptask_run_status' ), $refused );
		$this->assertSame( $now, $status );
		$this->assertSame( $raw, $this->last_body );
		if ( ! $refused ) {
			$this->assert_admitted( 'mainwp_backuptask_run_now', $now );
			return;
		}
		$this->update( $this->fixture['tab'], array( 'paused' => 0 ) );
		$key = 'mainwp_backup_click_' . $this->fixture['tab'] . '_' . $this->fixture['user'] . '_c536fcec-3ade-4e19-8153-113c097b14cf';
		set_transient(
			$key,
			array(
				'click'    => time(),
				'baseline' => 1700000000,
			),
			600
		);
		$before = get_transient( $key );
		$this->observe( fn() => $this->request( 'mainwp_backuptask_run_status', 'cookie-nonce', array( 'click' => $before['click'] ) ) );
		$this->observe(
			fn() => $this->request(
				'mainwp_backuptask_run_status',
				'cookie-nonce',
				array(
					'started' => 1700000000,
					'click'   => $before['click'],
				)
			)
		);
		$this->assertSame( $before, get_transient( $key ) );
		delete_transient( $key );
	}

	/** Staff cancellation preserves D2's busy lease and original deadline on retry. */
	public function test_staff_cancel_run_keeps_request_deadline(): void {
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$id  = $this->fixture['tab'];
		$run = time() - 100;
		$this->update(
			$id,
			array(
				'last_run'  => $run,
				'completed' => $run - 1,
			)
		);
		$db = MainWP_DB_Backup::instance();
		foreach ( array( 'a', 'b' ) as $site ) {
			$site_id = $this->fixture[ $site ];
			$db->add_backup_task_progress( $id, $site_id, array() );
			$this->assertNotFalse(
				$db->update_backup_task_progress(
					$id,
					$site_id,
					array(
						'dtsFetched'           => $run,
						'child_backup_task_id' => MainWP_Backup_Executor::CREATING_MARKER_PREFIX . $site,
						'child_phase'          => 'creating',
					)
				)
			);
		}
		$before = time();
		$result = $this->observe( fn() => $this->request( 'mainwp_native_backup_task_cancel_run', 'cookie-nonce', array( 'run' => $run ) ), false );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 2,
			),
			$result
		);
		$task  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$first = json_decode( $task->run_cancel, true );
		$this->assertSame( $run, $first['run'] );
		$this->assertSame( $this->fixture['user'], $first['user'] );
		$this->assertGreaterThanOrEqual( $before + 1800, $first['deadline'] );
		$this->assertLessThanOrEqual( time() + 1800, $first['deadline'] );
		$this->assertSame( 1800, $first['deadline'] - $first['requested'] );
		$this->assertSame( '', $first['lease'] );
		$first['lease']       = 'other-cancel-worker';
		$first['lease_until'] = time() + 120;
		$bytes                = wp_json_encode( $first );
		$this->update( $id, array( 'run_cancel' => $bytes ) );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 2,
			),
			$this->observe( fn() => $this->request( 'mainwp_native_backup_task_cancel_run', 'cookie-nonce', array( 'run' => $run ) ), false )
		);
		$this->assertSame( $bytes, MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->run_cancel );
		$first['lease']       = '';
		$first['lease_until'] = 0;
		$first['deadline']    = time() - 1;
		$this->update( $id, array( 'run_cancel' => wp_json_encode( $first ) ) );
		$this->assertSame( array( 'result' => 'not_confirmed' ), $this->observe( fn() => $this->request( 'mainwp_native_backup_task_cancel_run', 'cookie-nonce', array( 'run' => $run ) ), false ) );
		$this->assertGreaterThanOrEqual( $run, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->completed );
	}

	/** Losing the cancel CAS must recheck the same staff context before adopting it. */
	public function test_staff_cancel_reauthorizes_after_lost_compare_and_swap(): void {
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$id  = $this->fixture['tab'];
		$run = time() - 100;
		$this->update(
			$id,
			array(
				'last_run'  => $run,
				'completed' => $run - 1,
			)
		);
		$winner = wp_json_encode(
			array(
				'run'         => $run,
				'requested'   => time() - 10,
				'deadline'    => time() + 1790,
				'user'        => $this->fixture['user'],
				'lease'       => 'other-cancel-worker',
				'lease_until' => time() + 120,
				'settled'     => (object) array(),
			)
		);
		$lost   = false;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$race   = function ( $sql ) use ( &$lost, $table, $id, $winner ) {
			if ( ! $lost && str_starts_with( $sql, 'UPDATE ' . $table . ' SET run_cancel' ) ) {
				$lost = true;
				$this->update( $id, array( 'run_cancel' => $winner ) );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$result = $this->observe( fn() => $this->request( 'mainwp_native_backup_task_cancel_run', 'cookie-nonce', array( 'run' => $run ) ), false );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertTrue( $lost );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 0,
			),
			$result
		);
		$this->assertSame( $winner, MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->run_cancel );
	}

	/** Reset can close a stale Dashboard run without guessing any Child task id. */
	public function test_staff_reset_closes_stale_run(): void {
		$this->actor();
		$this->authenticate( 'cookie-nonce' );
		$id  = $this->fixture['tab'];
		$run = time() - MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS - 10;
		$this->update(
			$id,
			array(
				'last_run'  => $run,
				'completed' => $run - 1,
			)
		);
		$result = $this->observe( fn() => $this->request( 'mainwp_native_backup_task_reset' ), false );
		$this->assertSame(
			array(
				'status'      => 'reset',
				'cancelled'   => 0,
				'uncancelled' => 2,
			),
			$result
		);
		$this->assertGreaterThanOrEqual( $run, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->completed );
	}

	/** A refused poll cannot drive a pending cancellation, even on a paused task. */
	public function test_refused_poll_preserves_cancel_request(): void {
		$id  = $this->fixture['tab'];
		$run = time() - 100;
		$this->update(
			$id,
			array(
				'last_run'   => $run,
				'completed'  => $run - 1,
				'paused'     => 1,
				'run_cancel' => wp_json_encode(
					array(
						'run'         => $run,
						'requested'   => time() - 10,
						'deadline'    => time() - 1,
						'lease'       => '',
						'lease_until' => 0,
					)
				),
			)
		);
		$this->actor( 'S-site' );
		$this->authenticate( 'cookie-nonce' );
		$this->observe( fn() => $this->request( 'mainwp_backuptask_run_status' ) );
	}

	/**
	 * Rendering must reveal only the permitted names and task rows.
	 *
	 * @param int $id Edit task id, or zero for the list.
	 * @return string
	 */
	private function manager( int $id = 0 ): string {
		$_GET = array(
			'page'    => 'ManageBackups',
			'task_id' => $id,
		);
		return $this->observe(
			static function () {
				ob_start();
				try {
					MainWP_Backup_Page::render_manager();
				} finally {
					$html = ob_get_clean();
				}
				return $html;
			},
			false
		);
	}

	/** Staff lists use complete run scopes; unrestricted lists keep all original rows. */
	public function test_task_list_filters_complete_scope(): void {
		$deleted = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Staff deleted sites task', 'daily', 'full', '', '999999998', '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $deleted );
		$this->task_ids[] = (int) $deleted->id;
		$this->update( $deleted->id, array( 'authorized_sites' => '999999998' ) );
		foreach ( array( 'S-site', 'S+', 'A', 'N' ) as $actor ) {
			$this->actor( $actor );
			$full = MainWP_DB_Backup::instance()->get_backup_tasks_for_user();
			$html = $this->manager();
			preg_match_all( '/href="[^"]*page=ManageBackups(?:&amp;|&#038;|&)task_id=(\d+)"/', $html, $rows );
			$actual = array_values( array_unique( array_map( 'intval', $rows[1] ) ) );
			$want   = 'S-site' === $actor ? array( $this->fixture['ta'] ) : ( 'S+' === $actor ? array( $this->fixture['ta'], $this->fixture['tab'], $this->fixture['tb'] ) : array_map( 'intval', array_column( $full, 'id' ) ) );
			sort( $actual );
			sort( $want );
			$this->assertSame( $want, $actual, $actor );
		}
	}

	/**
	 * Nonce values and all other markup remain part of the disclosure comparison.
	 *
	 * @param string $html Rendered manager page.
	 * @return string
	 */
	private function normalize_generated_nonce_ids( string $html ): string {
		foreach ( array(
			'<form method="POST" action="" name="mainwp_overview_screen_options_form" id="mainwp-overview-screen-options-form">',
			'<form method="post" class="mainwp-table-container">',
		) as $form ) {
			$html = preg_replace(
				'/(' . preg_quote( $form, '/' ) . '\s*<input type="hidden" name="_wpnonce" id=")[A-Za-z0-9]{12}(" )/',
				'$1GENERATED_NONCE_ID$2',
				$html,
				-1,
				$count
			);
			$this->assertSame( 1, $count, 'Expected one auto-generated nonce input id in ' . $form );
		}
		return $html;
	}

	/** Hidden schedule and on-demand site links must match the missing edit page. */
	public function test_edit_form_hides_inaccessible_task_and_template(): void {
		$this->actor( 'S-site' );
		$missing = $this->normalize_generated_nonce_ids( $this->manager( 999999999 ) );
		foreach ( array( 'tab', 'template' ) as $key ) {
			$html = $this->manager( $this->fixture[ $key ] );
			$this->assertSame( $missing, $this->normalize_generated_nonce_ids( $html ), $key );
			$this->assertStringNotContainsString( 'Staff task ' . $key, $html );
			$this->assertStringNotContainsString( 'site_id=' . $this->fixture['b'], $html );
		}
		$this->actor();
		$this->assertStringContainsString( 'value="Staff task tab"', $this->manager( $this->fixture['tab'] ) );
		$this->assertStringContainsString( 'site_id=' . $this->fixture['b'], $this->manager( $this->fixture['template'] ) );
	}

	/** Staff can select shared destinations but cannot create them from a task. */
	public function test_staff_destination_page_and_task_link(): void {
		foreach ( array( 'S+', 'A', 'N' ) as $actor ) {
			$this->actor( $actor );
			$store = MainWP_Backup_Storage_Destinations::instance();
			$id    = $store->add_destination(
				array(
					'name'     => 'Staff visible destination',
					'provider' => 'local',
					'settings' => array(),
				)
			);
			$this->assertGreaterThan( 0, $id );
			try {
				$html = $this->observe(
					static function () {
						ob_start();
						MainWP_Backup_Storage_View::render();
						return ob_get_clean();
					},
					false
				);
			} finally {
				$store->delete_destination( $id );
			}
			$this->assertStringContainsString( 'Staff visible destination', $html );
			$notice = 'Only WordPress administrators can add or change storage destinations. Destinations are shared by every site on this Dashboard.';
			if ( 'S+' === $actor ) {
				$this->assertStringContainsString( $notice, $html );
				$this->assertStringNotContainsString( 'id="mainwp-storage-add-new"', $html );
				$this->assertStringNotContainsString( 'id="mainwp-storage-form"', $html );
				$dom = new \DOMDocument();
				@$dom->loadHTML( $html );
				$xpath = new \DOMXPath( $dom );
				foreach ( array( 'edit', 'test', 'make-default', 'delete' ) as $control ) {
					$this->assertSame( 0, $xpath->query( '//*[self::button or self::a][contains(concat(" ", @class, " "), " mainwp-storage-' . $control . ' ")]' )->length, $control );
				}
			} else {
				$this->assertStringNotContainsString( $notice, $html );
				$this->assertStringContainsString( 'id="mainwp-storage-add-new"', $html );
			}
			foreach ( array( null, MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->fixture['ta'] ) ) as $task ) {
				$form = $this->observe(
					static function () use ( $task ) {
						ob_start();
						MainWP_Backup_Task_Form::render_destinations_field( $task );
						return ob_get_clean();
					}
				);
				if ( 'S+' === $actor ) {
					$this->assertStringNotContainsString( 'Add a destination', $form );
				} else {
					$this->assertStringContainsString( 'Add a destination', $form );
				}
			}
		}
	}

	/** Only the exact browser context may bypass the ability capability gate. */
	public function test_task_non_ui_contexts_and_abilities_stay_denied(): void {
		$this->actor();
		$id    = $this->fixture['tab'];
		$calls = array(
			static fn() => MainWP_Backup_Operations::authorize_task( $id, 'run_backup_tasks' ),
			static fn() => MainWP_Backup_Operations::reset_task_run( $id ),
			static fn() => MainWP_Backup_Operations::cancel_task_run( $id, 1700000000 ),
		);
		foreach ( array( 'ability', 'cli', 'UI', 'unknown' ) as $context ) {
			$calls[] = static fn() => MainWP_Backup_Operations::authorize_task( $id, 'run_backup_tasks', $context );
			$calls[] = static fn() => MainWP_Backup_Operations::create_task( array(), $context );
			$calls[] = static fn() => MainWP_Backup_Operations::update_task( $id, array(), $context );
			$calls[] = static fn() => MainWP_Backup_Operations::delete_task( $id, false, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::pause_task( $id, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::resume_task( $id, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::run_task( $id, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::reset_task_run( $id, $context );
			$calls[] = static fn() => MainWP_Backup_Operations::cancel_task_run( $id, 1700000000, $context );
		}
		foreach ( $calls as $call ) {
			$result = $this->observe( $call );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
		}
		$method = new \ReflectionMethod( MainWP_Abilities_Backups::class, 'permission_callback' );
		$method->setAccessible( true );
		foreach ( array( 'create-backup-task', 'update-backup-task', 'delete-backup-task', 'pause-backup-task', 'resume-backup-task', 'reset-backup-task-run', 'run-backup-task', 'get-backup-task', 'get-backup-task-run', 'list-backup-tasks' ) as $ability ) {
			$callback = $method->invoke( null, 'mainwp/' . $ability . '-v1' );
			$result   = $this->observe( static fn() => $callback( array( 'task_id' => $id ) ) );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_permission_denied', $result->get_error_code(), $ability );
		}
	}

	/**
	 * Application passwords must not authenticate browser requests.
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

	/** Browser seams preserve the real role and nonce guards. */
	private function ajax_handler(): MainWP_Post_Backup_Handler {
		return new class() extends MainWP_Post_Backup_Handler {
			/**
			 * Callbacks admitted by the real registration gates.
			 *
			 * @var array
			 */
			public $registered = array();

			/**
			 * Avoid adding duplicate callbacks to the test process.
			 *
			 * @param string $action AJAX action.
			 * @param mixed  $callback Handler.
			 * @param int    $priority Priority.
			 * @param int    $accepted Argument count.
			 */
			public function add_action( $action, $callback, $priority = 10, $accepted = 2 ) {
				$this->registered[ $action ] = $callback;
			}

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
	 * Capture the JSON boundary without replacing authorization.
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
		$this->last_body = $raw;
		return json_decode( $raw, true ) ?? array();
	}
}
