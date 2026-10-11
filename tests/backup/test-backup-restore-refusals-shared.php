<?php
/**
 * Restore admission must refuse the same request through every transport.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_CLI_Restore_Runner;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/** A missed refusal must terminate promptly without contacting a real Child. */
class Shared_Refusal_Orchestrator extends MainWP_Backup_Restore_Orchestrator {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test double.
	/** No cron is needed to inspect admission. */
	protected function schedule_continuation(): void {}

	/**
	 * Admission is the subject here; the engine drive has its own tests.
	 *
	 * @param int      $budget_seconds Slice budget.
	 * @param int|null $only_job_id Target job.
	 */
	public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $only_job_id = null ): void {
		MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $only_job_id, 'completed' );
	}
}

/** Shared refusals and authenticated CLI authorization. */
class MainWP_Backup_Restore_Refusals_Shared_Test extends MainWP_Abilities_Test_Case {
	/**
	 * Singleton substitution.
	 *
	 * @var \ReflectionProperty
	 */
	private $singleton;
	/**
	 * Original orchestrator.
	 *
	 * @var object|null
	 */
	private $original;
	/**
	 * Target site.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Snapshot identity.
	 *
	 * @var int
	 */
	private $snapshot_id;
	/**
	 * Fixture artifact.
	 *
	 * @var string
	 */
	private $artifact;
	/**
	 * Mocked preflight response.
	 *
	 * @var array
	 */
	private $preflight = array();

	/** A real catalog entry ensures a missed gate could admit a restore. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id  = $this->create_test_site();
		$this->artifact = tempnam( sys_get_temp_dir(), 'mainwp-refusal-' );
		file_put_contents( $this->artifact, 'snapshot fixture' );
		$this->snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'  => 0,
				'site_id'  => $this->site_id,
				'type'     => 'db',
				'archives' => array(
					array(
						'file' => 'db.sql.gz',
						'role' => 'db',
						'path' => $this->artifact,
						'size' => 16,
					),
				),
			)
		);
		$this->singleton   = new \ReflectionProperty( MainWP_Backup_Restore_Orchestrator::class, 'instance' );
		$this->singleton->setAccessible( true );
		$this->original = $this->singleton->getValue();
		$this->singleton->setValue( null, new Shared_Refusal_Orchestrator() );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 3 );
	}

	/** Fixtures and the substituted singleton must not affect other restores. */
	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		$this->singleton->setValue( null, $this->original );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
		unlink( $this->artifact );
		parent::tearDown();
	}

	/**
	 * Only the Woo preflight fixture needs a successful child response.
	 *
	 * @param mixed  $pre Prior response.
	 * @param object $website Target site.
	 * @param string $what Child action.
	 * @return array
	 */
	public function child_reply( $pre, $website, $what ): array {
		return 'restore_preflight' === $what ? $this->preflight : array( 'error' => 'No real Child request is allowed.' );
	}

	/**
	 * CLI rows run first so the baseline captures its missing refusals.
	 *
	 * @return array Caller and fixture combinations.
	 */
	public static function refusals(): array {
		$cases = array();
		foreach ( array( 'cli', 'service', 'ajax' ) as $caller ) {
			foreach ( array(
				'disconnected'        => 'mainwp_backup_site_disconnected',
				'consent'             => 'mainwp_confirmation_required',
				'woo'                 => 'mainwp_restore_woo_legacy_orders',
				'rollback-native-off' => 'mainwp_backup_legacy_disabled',
			) as $fixture => $code ) {
				$cases[ $caller . '-' . $fixture ] = array( $caller, $fixture, $code );
			}
		}
		return $cases;
	}

	/**
	 * Legacy AJAX payloads identify consent and disconnection without a code field.
	 *
	 * @dataProvider refusals
	 * @param string $caller Transport.
	 * @param string $fixture Admission refusal.
	 * @param string $code Expected shared code.
	 */
	public function test_refusals_match_without_creating_a_job( string $caller, string $fixture, string $code ): void {
		global $wpdb;
		$options = array(
			'confirm_destructive' => 'consent' !== $fixture,
			'preserve_orders'     => 'woo' === $fixture,
		);
		$job_id  = 0;
		if ( 'disconnected' === $fixture ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'sync_errors' => 'Disconnected fixture' ), array( 'wpid' => $this->site_id ) );
		} elseif ( 'woo' === $fixture ) {
			$this->preflight = array( 'woo_hpos_enabled' => false );
		} elseif ( 'rollback-native-off' === $fixture ) {
			$job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job( $this->site_id, $this->snapshot_id, array( 'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ) ) );
			MainWP_Backup_Restore_Job_Store::instance()->set_status( $job_id, 'failed_permanent' );
			update_option( 'mainwp_enableNativeBackups', 0 );
		}
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$before = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A );
		if ( 'cli' === $caller ) {
			$result = ( new MainWP_Backup_CLI_Restore_Runner( null, null, null, 30, 0, 0, 1 ) )->run( $this->site_id, $this->snapshot_id, $options, $options['confirm_destructive'] );
			$this->assertSame( $code, $result['error_code'] );
			$this->assertSame( 'consent' === $fixture, $result['needs_confirmation'] );
		} elseif ( 'service' === $caller ) {
			$result = $job_id ? MainWP_Backup_Operations::rollback_restore( $job_id ) : MainWP_Backup_Operations::restore_snapshot( $this->site_id, $this->snapshot_id, $options, 'ability' );
			$this->assertWPError( $result );
			$this->assertSame( $code, $result->get_error_code() );
			if ( 'consent' === $fixture ) {
				$this->assertArrayHasKey( 'age_days', $result->get_error_data() );
				$this->assertArrayHasKey( 'site_has_woocommerce', $result->get_error_data() );
			}
		} else {
			$result = $this->ajax(
				$job_id ? 'mainwp_restore_rollback' : 'mainwp_restore_initiate',
				array(
					'site_id'             => $this->site_id,
					'snapshot_id'         => $this->snapshot_id,
					'job_id'              => $job_id,
					'confirm_destructive' => $options['confirm_destructive'] ? '1' : '0',
					'preserve_orders'     => $options['preserve_orders'] ? '1' : '0',
				)
			);
			if ( 'consent' === $fixture ) {
				$this->assertSame( array( 'needs_confirmation', 'site_has_woocommerce', 'age_days' ), array_keys( $result ) );
				$this->assertTrue( $result['needs_confirmation'] );
			} elseif ( 'disconnected' === $fixture ) {
				$this->assertSame( array( 'error' => MainWP_Post_Backup_Handler::disconnected_site_error() ), $result );
			} else {
				$this->assertSame( $code, $result['error_code'] );
			}
		}
		$this->assertSame( $before, $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A ), 'A refused request cannot reserve or create a restore job.' );
	}

	/** Internal reservations must disclose no job or site through rollback. */
	public function test_ability_rollback_hides_reserved_job(): void {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job( $this->site_id, $this->snapshot_id, array() );
		$this->assertGreaterThan( 0, $job_id );
		$this->assertTrue( $store->set_status( $job_id, 'reserved' ) );
		$error = MainWP_Backup_Operations::rollback_restore( $job_id, 'ability' );
		$this->assertWPError( $error );
		$this->assertSame( 'mainwp_backup_restore_job_not_found', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
		$this->assertStringNotContainsString( (string) $this->site_id, $error->get_error_message() );
		$this->assertSame( 'reserved', $store->get_job( $job_id )->status );
	}

	/** An inaccessible job must answer like a missing job without its site identity. */
	public function test_ability_rollback_hides_inaccessible_job(): void {
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job( $this->site_id, $this->snapshot_id, array( 'pre_snapshot_artifacts' => array( array( 'file' => 'safety.tar.gz' ) ) ) );
		$this->assertGreaterThan( 0, $job_id );
		$this->assertTrue( $store->set_status( $job_id, 'failed_permanent' ) );
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		try {
			$error = MainWP_Backup_Operations::rollback_restore( $job_id, 'ability' );
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_restore_job_not_found', $error->get_error_code() );
			$this->assertSame( 404, $error->get_error_data()['status'] );
			$this->assertStringNotContainsString( (string) $this->site_id, $error->get_error_message() );
			$this->assertSame( 'failed_permanent', $store->get_job( $job_id )->status );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		}
	}

	/** Authenticated CLI callers need WordPress capability, operation right and scope. */
	public function test_cli_subscriber_and_denied_admin_are_refused_before_admission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$runner = new MainWP_Backup_CLI_Restore_Runner( null, null, null, 30, 0, 0, 1 );
		$this->assertSame( 'mainwp_permission_denied', $runner->run( $this->site_id, $this->snapshot_id, array(), true )['error_code'] );
		$this->set_current_user_as_admin();
		add_filter( 'mainwp_currentusercan', '__return_false' );
		try {
			$this->assertSame( 'mainwp_insufficient_right', $runner->run( $this->site_id, $this->snapshot_id, array(), true )['error_code'] );
		} finally {
			remove_filter( 'mainwp_currentusercan', '__return_false' );
		}
		add_filter( 'mainwp_check_site_access', '__return_false' );
		try {
			$this->assertSame( 'mainwp_site_not_found', $runner->run( $this->site_id, $this->snapshot_id, array(), true )['error_code'] );
		} finally {
			remove_filter( 'mainwp_check_site_access', '__return_false' );
		}
	}

	/**
	 * WordPress's termination hook permits inspection of the JSON body.
	 *
	 * @param string $action Handler method.
	 * @param array  $fields Posted fields.
	 * @return array
	 */
	private function ajax( string $action, array $fields ): array {
		$_POST                = $fields;
		$nonce                = wp_create_nonce( $action );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$throw                = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->$action();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $throw );
		}
		$response = json_decode( $raw, true );
		$this->assertIsArray( $response, $raw );
		return $response;
	}
}
