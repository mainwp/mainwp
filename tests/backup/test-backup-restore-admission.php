<?php
/**
 * Browser admission races must resolve without a second destructive restore.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

/** Admission remains real while continuation scheduling stays inside the fixture. */
class Admission_Test_Orchestrator extends MainWP_Backup_Restore_Orchestrator {
	/**
	 * Optional failure after the job is persisted.
	 *
	 * @var \Throwable|null
	 */
	public $schedule_error;

	/**
	 * A failed scheduler must not hide an admitted job.
	 *
	 * @throws \Throwable When the test scripts a scheduler failure.
	 */
	protected function schedule_continuation(): void {
		if ( null !== $this->schedule_error ) {
			throw $this->schedule_error;
		}
	}
}

/** Named locks, options rows and checkpoints use the real test database. */
class MainWP_Backup_Restore_Admission_Test extends MainWP_Abilities_Test_Case {
	use Backup_Private_Root_Fixture;

	/**
	 * Owned fixture identities and scratch files.
	 *
	 * @var array
	 */
	private $fixture = array();

	/**
	 * Orchestrator singleton restored at teardown.
	 *
	 * @var \ReflectionProperty
	 */
	private $singleton;

	/**
	 * Original singleton.
	 *
	 * @var object|null
	 */
	private $original;

	/**
	 * Scheduling seam.
	 *
	 * @var Admission_Test_Orchestrator
	 */
	private $orchestrator;

	/** Connected sites and local artifacts permit real admission. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->fixture['site']   = $this->create_test_site();
		$this->fixture['other']  = $this->create_test_site();
		$this->fixture['tokens'] = array();
		$this->fixture['dir']    = sys_get_temp_dir() . '/mainwp-admission-' . uniqid();
		mkdir( $this->fixture['dir'] );
		$this->fixture['snapshots'] = array();
		$this->fixture['snapshot']  = $this->make_chain_snapshot(
			null,
			'db',
			array(
				array(
					'file' => 'db.sql.gz',
					'role' => 'db',
				),
			)
		);
		$this->singleton            = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'instance' );
		$this->original             = $this->singleton->getValue();
		$this->orchestrator         = new Admission_Test_Orchestrator();
		$this->singleton->setValue( null, $this->orchestrator );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 4 );
	}

	/** Release request markers even when an assertion fails. */
	public function tear_down() {
		global $wpdb;
		foreach ( $this->fixture['tokens'] as $token ) {
			MainWP_Backup_Restore_Job_Store::instance()->release_admission( $token );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_restore_admission_' . $token ) );
		}
		foreach ( array( 'site', 'other' ) as $key ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'site_id' => $this->fixture[ $key ] ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $this->fixture[ $key ] ) );
		}
		foreach ( $this->fixture['snapshots'] as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'snapshot_id' => $id ) );
		}
		foreach ( glob( $this->fixture['dir'] . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->fixture['dir'] );
		$this->singleton->setValue( null, $this->original );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
		delete_option( 'mainwp_enableNativeBackups' );
		$_POST    = array();
		$_REQUEST = array();
		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Child traffic must stay in the fixture.
	 *
	 * @param mixed  $pre Prior result.
	 * @param object $website Target site.
	 * @param string $what Child operation.
	 * @param array  $params Child parameters.
	 * @return array
	 */
	public function child_reply( $pre, $website, $what, $params ): array {
		return 'backup_preflight' === $what ? array( 'restore_create_idempotency' => 1 ) : array( 'result' => 'ok' );
	}

	/**
	 * Chain artifacts use separate paths, as real snapshot runs do.
	 *
	 * @param int|null $parent_id Parent identity.
	 * @param string   $type Snapshot type.
	 * @param array    $archives Artifact descriptors.
	 * @return int
	 */
	private function make_chain_snapshot( $parent_id, $type, $archives ): int {
		global $wpdb;
		$serial = count( $this->fixture['snapshots'] );
		foreach ( $archives as &$archive ) {
			$archive['path'] = $this->fixture['dir'] . '/snap' . $serial . '-' . $archive['file'];
			file_put_contents( $archive['path'], 'artifact-bytes-' . $serial );
			$archive['size'] = (int) filesize( $archive['path'] );
		}
		unset( $archive );
		$this->assertSame(
			1,
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'task_id'       => 991,
					'site_id'       => $this->fixture['site'],
					'type'          => $type,
					'parent_id'     => $parent_id,
					'created'       => time(),
					'archives'      => wp_json_encode( $archives ),
					'manifest_path' => '',
					'status'        => 'complete',
				)
			)
		);
		$id                           = (int) $wpdb->insert_id;
		$this->fixture['snapshots'][] = $id;
		return $id;
	}

	/**
	 * Tokens remain unique across simulated requests in one PHP process.
	 *
	 * @return string
	 */
	private function token(): string {
		$token                     = bin2hex( random_bytes( 16 ) );
		$this->fixture['tokens'][] = $token;
		return $token;
	}

	/**
	 * The JSON termination seam observes the marker before wp_die unwinds.
	 *
	 * @param string        $method AJAX action.
	 * @param array         $fields Posted overrides.
	 * @param callable|null $at_reply Observer called at JSON termination.
	 * @return array
	 */
	private function invoke_ajax_handler( string $method, array $fields = array(), ?callable $at_reply = null ): array {
		$_POST    = $fields + array(
			'site_id'             => $this->fixture['site'],
			'snapshot_id'         => $this->fixture['snapshot'],
			'confirm_destructive' => '1',
			'security'            => wp_create_nonce( $method ),
		);
		$_REQUEST = $_POST;
		$throw    = static function () use ( $at_reply ) {
			return static function () use ( $at_reply ) {
				if ( null !== $at_reply ) {
					$at_reply();
				}
				throw new \RuntimeException( 'admission-json-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->$method();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'admission-json-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $throw );
		}
		$result = json_decode( $raw, true );
		$this->assertIsArray( $result, $raw );
		return $result;
	}

	/**
	 * Status requests use their own nonce.
	 *
	 * @param string $token Attempt identity.
	 * @param int    $site_id Optional site override.
	 * @return array
	 */
	private function status( string $token, int $site_id = 0 ): array {
		return $this->invoke_ajax_handler(
			'mainwp_restore_admission_status',
			array(
				'admission_token' => $token,
				'site_id'         => $site_id ?: $this->fixture['site'],
			)
		);
	}

	/** Row 1: a lost reply still identifies exactly the admitted job. */
	public function test_row_01_reply_lost_after_admission(): void {
		global $wpdb;
		$token    = $this->token();
		$response = $this->invoke_ajax_handler(
			'mainwp_restore_initiate',
			array( 'admission_token' => $token ),
			function () use ( $wpdb, $token ) {
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', MainWP_Backup_Restore_Job_Store::admission_lock_name( $token ) ) ), 'The marker must be free before JSON terminates the request.' );
			}
		);
		$this->assertGreaterThan( 0, $response['job_id'] );
		$this->assertSame(
			array(
				'state'  => 'admitted',
				'job_id' => $response['job_id'],
			),
			$this->status( $token )
		);
	}

	/** Row 2: a claimed request in preflight remains pending. */
	public function test_row_02_request_still_in_preflight(): void {
		$token = $this->token();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->lock_admission( $token ) );
		$this->assertTrue( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
		$this->assertSame( array( 'state' => 'pending' ), $this->status( $token ) );
		$this->assertNull( $store->get_active_job_for_site( $this->fixture['site'] ) );
	}

	/** Row 3: burning a queued request refuses its late admission. */
	public function test_row_03_queued_request_is_burned(): void {
		$token = $this->token();
		$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $token ) );
		$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
		$this->assertSame( 'mainwp_restore_admission_used', $response['error_code'] );
		$this->assertNull( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $this->fixture['site'] ) );
		$this->assertFalse( MainWP_Backup_Restore_Job_Store::instance()->site_is_reserved( $this->fixture['site'] ) );
	}

	/** Row 4: a refusal leaves a claimed record and a free marker. */
	public function test_row_04_request_refused_before_admission(): void {
		$token    = $this->token();
		$response = $this->invoke_ajax_handler(
			'mainwp_restore_initiate',
			array(
				'admission_token'     => $token,
				'confirm_destructive' => '0',
			)
		);
		$this->assertTrue( $response['needs_confirmation'] );
		$this->assertSame( 'claimed', MainWP_Backup_Restore_Job_Store::instance()->get_admission_record( $token )[1] );
		$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $token ) );
	}

	/** Row 5: both exception classes prefer an already persisted job. */
	public function test_row_05_throwable_after_admission(): void {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		foreach ( array( new \Error( 'Scheduler failed' ), new MainWP_Exception( 'Scheduler failed' ) ) as $error ) {
			$token                              = $this->token();
			$this->orchestrator->schedule_error = $error;
			$response                           = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
			$this->assertGreaterThan( 0, $response['job_id'] );
			$this->assertSame(
				array(
					'state'  => 'admitted',
					'job_id' => $response['job_id'],
				),
				$this->status( $token )
			);
			$store->complete_job( $response['job_id'] );
		}
	}

	/** Row 6: a dead request's checkpoint outlives its connection lock. */
	public function test_row_06_request_killed_after_admission(): void {
		$token = $this->token();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
		$id = $store->create_job( $this->fixture['site'], $this->fixture['snapshot'], array( 'admission_token' => $token ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame(
			array(
				'state'  => 'admitted',
				'job_id' => $id,
			),
			$this->status( $token )
		);
	}

	/** Row 7: a stranded reservation refuses new Start until its existing TTL. */
	public function test_row_07_request_killed_after_reservation(): void {
		$token = $this->token();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
		$id = $store->reserve_site( $this->fixture['site'] );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $token ) );
		$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $this->token() ) );
		$this->assertSame( 'mainwp_backup_already_running', $response['error_code'] );
		$this->assertStringContainsString( 'Another backup action is working on this site', $response['error'] );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::RESERVATION_SECONDS, (int) $store->get_job( $id )->locked_until - (int) $store->get_job( $id )->created );
	}

	/** Row 8: same-snapshot jobs from another click or the API cannot be adopted. */
	public function test_row_08_another_admin_or_api_restore(): void {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		foreach ( array( array( 'admission_token' => $this->token() ), array() ) as $checkpoint ) {
			$id = $store->create_job( $this->fixture['site'], $this->fixture['snapshot'], $checkpoint );
			$this->assertGreaterThan( 0, $id );
			$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $this->token() ) );
			$store->complete_job( $id );
		}
	}

	/** Row 9: a second connection proves busy before the ended token becomes used. */
	public function test_row_09_same_token_sent_twice(): void {
		global $wpdb;
		$token                        = $this->token();
		$store                        = MainWP_Backup_Restore_Job_Store::instance();
		$parse                        = new \ReflectionMethod( $wpdb, 'parse_db_host' );
		list( $host, $port, $socket ) = $parse->invoke( $wpdb, DB_HOST );
		$link                         = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions -- GET_LOCK is reentrant on the wpdb connection.
		$this->assertTrue( mysqli_real_connect( $link, $host, DB_USER, DB_PASSWORD, DB_NAME, $port ?: 0, $socket ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- the busy marker must be held by a second connection.
		$name = MainWP_Backup_Restore_Job_Store::admission_lock_name( $token );
		try {
			$locked = $link->query( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
			$this->assertSame( '1', (string) $locked->fetch_row()[0] );
			$this->assertTrue( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
			$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
			$this->assertSame( 'mainwp_restore_admission_busy', $response['error_code'] );
		} finally {
			$link->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$link->close();
		}
		$id = $store->create_job( $this->fixture['site'], $this->fixture['snapshot'], array( 'admission_token' => $token ) );
		$this->assertGreaterThan( 0, $id );
		$response = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
		$this->assertSame( 'mainwp_restore_admission_used', $response['error_code'] );
		$this->assertSame(
			array(
				'state'  => 'admitted',
				'job_id' => $id,
			),
			$this->status( $token )
		);
		$this->assertSame( $id, (int) $store->get_active_job_for_site( $this->fixture['site'] )->id );
	}

	/** Row 10: ownership failures disclose neither liveness nor job identity. */
	public function test_row_10_another_user_or_site_token(): void {
		$token = $this->token();
		$reply = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
		$this->assertGreaterThan( 0, $reply['job_id'] );
		$this->assertSame( array( 'error' => 'Restore attempt not found.' ), $this->status( $token, $this->fixture['other'] ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array( 'error' => 'Restore attempt not found.' ), $this->status( $token ) );
		$claimed = $this->token();
		$store   = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->lock_admission( $claimed ) );
		$this->assertTrue( $store->claim_admission( $claimed, $this->fixture['other'], 'rollback' ) );
		$this->assertSame( array( 'error' => 'Restore attempt not found.' ), $this->status( $claimed ) );
	}

	/** Row 11: invalid identities cannot claim a token or reserve a site. */
	public function test_row_11_malformed_or_missing_token(): void {
		global $wpdb;
		$options_before = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mainwp_restore_admission_' ) . '%' ), ARRAY_A );
		$before         = $wpdb->get_results( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), ARRAY_A );
		foreach ( array( null, '', str_repeat( 'A', 32 ), str_repeat( 'a', 31 ), str_repeat( 'a', 32 ) . "\n", array( 'x' ), "x' OR 1=1 --" ) as $token ) {
			foreach ( array( 'mainwp_restore_initiate', 'mainwp_restore_rollback', 'mainwp_restore_admission_status' ) as $action ) {
				$reply = $this->invoke_ajax_handler( $action, null === $token ? array() : array( 'admission_token' => $token ) );
				$this->assertSame( 'mainwp_invalid_input', $reply['error_code'] );
			}
		}
		$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), ARRAY_A ) );
		$this->assertSame( $options_before, $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mainwp_restore_admission_' ) . '%' ), ARRAY_A ) );
	}

	/** Row 12: lost rollback replies and a live rollback resolve through the same token. */
	public function test_row_12_rollback_reply_lost_or_hanging(): void {
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$id    = $store->create_job(
			$this->fixture['site'],
			$this->fixture['snapshot'],
			array(
				'pre_snapshot_artifacts' => array(
					array(
						'role' => 'full',
						'file' => 'safety.zip',
						'size' => 20,
					),
				),
			)
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertTrue( $store->complete_job( $id ) );
		$token                              = $this->token();
		$this->orchestrator->schedule_error = new \Error( 'Rollback scheduler failed' );
		$reply                              = $this->invoke_ajax_handler(
			'mainwp_restore_rollback',
			array(
				'job_id'          => $id,
				'admission_token' => $token,
			)
		);
		$this->assertGreaterThan( $id, $reply['job_id'] );
		$this->assertSame( $token, $store->get_job( $reply['job_id'] )->checkpoint['admission_token'] );
		$this->assertSame(
			array(
				'state'  => 'admitted',
				'job_id' => $reply['job_id'],
			),
			$this->status( $token )
		);
		$hanging = $this->token();
		$this->assertTrue( $store->lock_admission( $hanging ) );
		$this->assertTrue( $store->claim_admission( $hanging, $this->fixture['site'], 'rollback' ) );
		$this->assertSame( array( 'state' => 'pending' ), $this->status( $hanging ) );
	}

	/** A lock taken before claim cannot prevent reconcile from burning the queued attempt. */
	public function test_unclaimed_held_marker_is_burned_before_liveness_check(): void {
		$token = $this->token();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->lock_admission( $token ) );
		$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $token ) );
		$this->assertFalse( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
	}

	/** Phase saves retain the token that reconciliation follows. */
	public function test_admission_token_survives_orchestrator_checkpoint_save(): void {
		$token = $this->token();
		$reply = $this->invoke_ajax_handler( 'mainwp_restore_initiate', array( 'admission_token' => $token ) );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$store->set_status( $reply['job_id'], 'verifying' );
		$probe = static function () {
			return new \WP_Error( 'offline', 'Fixture offline' );
		};
		add_filter( 'pre_http_request', $probe );
		try {
			$this->orchestrator->process_pending_restore_jobs( 0, $reply['job_id'] );
		} finally {
			remove_filter( 'pre_http_request', $probe );
		}
		$job = $store->get_job_by_admission_token( $this->fixture['site'], $token );
		$this->assertSame( $reply['job_id'], (int) $job->id );
		$this->assertArrayHasKey( 'verification', $job->checkpoint );
		$this->assertFalse( $job->checkpoint['verification']['site_responds'] );
	}

	/** A failed evidence read must never authorize another Start. */
	public function test_status_sql_failure_returns_error_instead_of_not_admitted(): void {
		$token  = $this->token();
		$broken = static function ( $sql ) {
			if ( str_contains( $sql, 'checkpoint LIKE' ) && str_contains( $sql, 'admission' ) ) {
				throw new \Error( 'Fixture lookup failure' );
			}
			return $sql;
		};
		add_filter( 'query', $broken );
		try {
			$reply = $this->status( $token );
		} finally {
			remove_filter( 'query', $broken );
		}
		$this->assertArrayHasKey( 'error', $reply );
		$this->assertArrayNotHasKey( 'state', $reply );
	}

	/** A request can admit and release between the status lookup and marker read. */
	public function test_status_rechecks_job_after_request_releases_marker(): void {
		$token = $this->token();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$this->assertTrue( $store->claim_admission( $token, $this->fixture['site'], 'restore' ) );
		$id     = 0;
		$finish = function ( $sql ) use ( $store, $token, &$id ) {
			if ( 0 === $id && str_contains( $sql, 'IS_USED_LOCK(' ) && str_contains( $sql, 'mwp-admit-' ) ) {
				$id = $store->create_job( $this->fixture['site'], $this->fixture['snapshot'], array( 'admission_token' => $token ) );
			}
			return $sql;
		};
		add_filter( 'query', $finish );
		try {
			$reply = $this->status( $token );
		} finally {
			remove_filter( 'query', $finish );
		}
		$this->assertGreaterThan( 0, $id );
		$this->assertSame(
			array(
				'state'  => 'admitted',
				'job_id' => $id,
			),
			$reply
		);
	}

	/** SQL records must stay outside option caches and expire after one day. */
	public function test_admission_records_use_sql_without_option_caches(): void {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$old   = $this->token();
		$fresh = $this->token();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", 'mainwp_restore_admission_' . $old, ( time() - DAY_IN_SECONDS - 10 ) . ':claimed:' . $this->fixture['site'] . ':' . get_current_user_id() . ':restore', 'no' ) );
		$alloptions = wp_cache_get( 'alloptions', 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$this->assertTrue( $store->claim_admission( $fresh, $this->fixture['site'], 'rollback' ) );
		$record = $store->get_admission_record( $fresh );
		$this->assertSame( array( 'claimed', (string) $this->fixture['site'], (string) get_current_user_id(), 'rollback' ), array_slice( $record, 1 ) );
		$this->assertSame( array(), $store->get_admission_record( $old ) );
		$this->assertSame( 'no', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_restore_admission_' . $fresh ) ) );
		$this->assertSame( $alloptions, wp_cache_get( 'alloptions', 'options' ) );
		$this->assertSame( $notoptions, wp_cache_get( 'notoptions', 'options' ) );
	}

	/** Status traffic must expire burned attempts even when no restore starts. */
	public function test_status_request_expires_burned_admission_records(): void {
		global $wpdb;
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$old   = $this->token();
		$fresh = $this->token();
		$this->assertSame(
			1,
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", 'mainwp_restore_admission_' . $old, ( time() - DAY_IN_SECONDS - 10 ) . ':burned:' . $this->fixture['site'] . ':' . get_current_user_id() . ':restore', 'no' ) )
		);
		$this->assertSame( 'burned', $store->get_admission_record( $old )[1] );
		$this->assertSame( array( 'state' => 'not_admitted' ), $this->status( $fresh ) );
		$this->assertSame( 'burned', $store->get_admission_record( $fresh )[1] );
		$this->assertSame( array(), $store->get_admission_record( $old ) );
	}

	/** Activity is the list's exact dynamic subset, including uploads and test state. */
	public function test_snapshot_activity_equals_list_subset_and_marks_foreign_ids_missing(): void {
		global $wpdb;
		$id = $this->fixture['snapshot'];
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'test_restore_state' => 'pass',
				'test_restore_at'    => 1700000000,
			),
			array( 'id' => $id )
		);
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'snapshot_id'    => $id,
				'destination_id' => 999999,
				'provider'       => 's3',
				'status'         => 'uploading',
				'checkpoint'     => '{}',
				'last_error'     => '[mainwp_backup_s3_failed] Fixture failure',
				'updated'        => 1700000000,
			)
		);
		$foreign = $this->make_chain_snapshot(
			null,
			'files',
			array(
				array(
					'file' => 'files.zip',
					'role' => 'files',
				),
			)
		);
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $this->fixture['other'] ), array( 'id' => $foreign ) );
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$held  = $store->reserve_site( $this->fixture['site'], 'test_restore', $id );
		try {
			$this->assertTrue( $store->renew_reservation( $held ) );
			$list     = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_list' );
			$activity = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_activity', array( 'snapshot_ids' => array( $id, $foreign, 999999999 ) ) );
		} finally {
			$store->release_reservation( $held );
		}
		$this->assertSame( array( $foreign, 999999999 ), $activity['missing'] );
		$this->assertCount( 1, $activity['snapshots'] );
		$entry = $activity['snapshots'][0];
		$this->assertSame( array( 'id', 'status', 'offsite_state', 'uploads', 'test_restore_running', 'test_restore_state', 'test_restore_at', 'test_restore_label' ), array_keys( $entry ) );
		foreach ( $entry as $key => $value ) {
			$this->assertSame( $list['snapshots'][0][ $key ], $value, $key );
		}
		$this->assertTrue( $entry['test_restore_running'] );
		$this->assertIsInt( $activity['server_time'] );
	}

	/** Activity accepts at most fifty exact positive snapshot ids. */
	public function test_snapshot_activity_rejects_invalid_or_oversized_ids(): void {
		foreach ( array( '1', array( -1 ), array( '1x' ), array( array( 1 ) ), range( 1, 51 ) ) as $ids ) {
			$reply = $this->invoke_ajax_handler( 'mainwp_restore_snapshot_activity', array( 'snapshot_ids' => $ids ) );
			$this->assertSame( 'mainwp_invalid_input', $reply['error_code'] );
		}
	}
}
