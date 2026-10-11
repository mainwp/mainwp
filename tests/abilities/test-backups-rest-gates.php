<?php
/**
 * REST must preserve read methods and target refusals at the execution boundary.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';
require_once __DIR__ . '/test-backups-read-abilities.php';

/** Cookie-style authentication keeps each REST gate independent of machine constants. */
class MainWP_Backups_REST_Gates_Test extends \WP_Test_REST_TestCase {
	use Backup_Read_Fixtures;
	use Backup_Read_Purity_Guard;

	/**
	 * A fresh server isolates route state between cases.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Allocated destination for write previews.
	 *
	 * @var int
	 */
	private $write_destination_id;
	/**
	 * Allocated terminal restore job.
	 *
	 * @var int
	 */
	private $write_restore_job_id;

	/**
	 * Routes must be created after the ability registry is initialized.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		wp_get_abilities();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->seed_read_fixtures();
		$this->write_destination_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'REST stored destination',
				'settings' => array( 'base_path' => '/private/rest-preview-path' ),
			)
		);
		$this->write_restore_job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->site_id,
			987673,
			array(
				'sub_type'               => 'full',
				'pre_snapshot_artifacts' => array(
					array(
						'file' => 'safety.tar.gz',
						'role' => 'full',
						'size' => 1,
					),
				),
			)
		);
		MainWP_Backup_Restore_Job_Store::instance()->set_status( $this->write_restore_job_id, 'failed_permanent' );
		$this->insert_read_fixture(
			'wp_backup_storage',
			array(
				'id'       => 987676,
				'name'     => 'Read destination',
				'provider' => 's3',
				'settings' => 'unreadable',
			)
		);
	}

	/**
	 * Request state must not leak into another REST test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		MainWP_Backup_Storage_Destinations::instance()->delete_destination( $this->write_destination_id );
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'id' => $this->write_restore_job_id ) );
		$this->clear_read_fixtures();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tearDown();
	}

	/**
	 * The shared fixture needs only a roster entry and synced Child version.
	 *
	 * @param array $args Site values.
	 * @return int
	 */
	protected function create_test_site( array $args ): int {
		$this->insert_read_fixture(
			'wp',
			array_merge(
				array(
					'userid'     => get_current_user_id(),
					'adminname'  => 'admin',
					'pubkey'     => 'test-pubkey',
					'privkey'    => 'test-privkey',
					'is_staging' => 0,
				),
				$args
			)
		);
		$this->insert_read_fixture(
			'wp_sync',
			array(
				'wpid'        => $args['id'],
				'version'     => '5.0.0',
				'sync_errors' => '',
			)
		);
		return (int) $args['id'];
	}

	/**
	 * The direct tests and REST tests must cover the same reads.
	 *
	 * @return array
	 */
	public static function reads() {
		return MainWP_Backups_Read_Abilities_Test::reads();
	}

	/**
	 * All execution requests are guarded for purity, including refusals.
	 *
	 * @param string $method HTTP method.
	 * @param string $name Ability slug.
	 * @param array  $input Caller input.
	 * @return \WP_REST_Response
	 */
	private function request( $method, $name, $input ) {
		$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		$request->set_param( 'input', $input );
		return $this->guarded(
			function () use ( $request ) {
				return $this->server->dispatch( $request );
			}
		);
	}

	/**
	 * A successful GET proves both method routing and schema compatibility.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Caller input.
	 * @return void
	 */
	public function test_authorized_get_matches_schema( $name, $input ) {
		$response = $this->request( 'GET', $name, $input );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( rest_validate_value_from_schema( $response->get_data(), wp_get_ability( 'mainwp/' . $name . '-v1' )->get_output_schema() ) );
	}

	/**
	 * An unauthenticated request must fail before target lookup.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Caller input.
	 * @return void
	 */
	public function test_unauthenticated_get_is_401( $name, $input ) {
		wp_set_current_user( 0 );
		$response = $this->request( 'GET', $name, $input );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'mainwp_permission_denied', $response->get_data()['code'] );
	}

	/**
	 * Read annotations must reject POST with a method error.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Caller input.
	 * @return void
	 */
	public function test_post_is_405( $name, $input ) {
		$this->assertSame( 405, $this->request( 'POST', $name, $input )->get_status() );
	}

	/**
	 * Every scalar target field is tested independently of other valid fields.
	 *
	 * @return array
	 */
	public static function missing_targets() {
		$cases = array();
		foreach ( array( 'list-backup-tasks', 'get-site-backup-progress', 'list-backup-snapshots', 'list-restore-jobs', 'list-backup-history' ) as $name ) {
			foreach ( array( 999999, '999999', 'unknown-backup.example.org', 'https://unknown-backup.example.org/' ) as $value ) {
				$cases[ $name . '-site-' . gettype( $value ) . '-' . $value ] = array( $name, array( 'site_id_or_domain' => $value ), 'mainwp_site_not_found' );
			}
		}
		foreach ( array(
			'get-backup-task'        => 'task_id',
			'get-backup-task-run'    => 'task_id',
			'list-backup-history'    => 'task_id',
			'get-backup-snapshot'    => 'snapshot_id',
			'get-restore-job'        => 'restore_job_id',
			'get-backup-destination' => 'destination_id',
		) as $name => $key ) {
			$code = 'mainwp_backup_' . str_replace( '_id', '', $key ) . '_not_found';
			foreach ( array( 999999, '999999' ) as $id ) {
				$cases[ $name . '-' . gettype( $id ) ] = array( $name, array( $key => $id ), $code );
			}
		}
		return $cases;
	}

	/**
	 * Target refusals must reach execute so core cannot rewrite them as 403.
	 *
	 * @dataProvider missing_targets
	 * @param string $name Ability slug.
	 * @param array  $input Missing target.
	 * @param string $code Exact documented refusal.
	 * @return void
	 */
	public function test_missing_target_is_exact_404( $name, $input, $code ) {
		$response = $this->request( 'GET', $name, $input );
		$this->assertSame( 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/**
	 * Status uses a fleet filter whose unresolved members are omitted by contract.
	 *
	 * @return void
	 */
	public function test_status_drops_unresolved_fleet_filter_members() {
		$response = $this->request( 'GET', 'get-backup-status', array( 'site_ids_or_domains' => array( 999999, 'unknown-backup.example.org' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['items'] );
		$this->assertSame( 0, $response->get_data()['totals']['sites'] );
	}

	/**
	 * Consent and method gates must stay consistent across all backup writes.
	 *
	 * @return array
	 */
	public static function writes() {
		return array(
			array( 'run-site-backup', 'POST', 'mainwp_site_not_found' ),
			array( 'run-backup-task', 'POST', 'mainwp_backup_task_not_found' ),
			array( 'cancel-site-backup', 'DELETE', 'mainwp_site_not_found' ),
			array( 'reset-backup-task-run', 'DELETE', 'mainwp_backup_task_not_found' ),
			array( 'cleanup-site-backups', 'POST', 'mainwp_site_not_found' ),
			array( 'create-backup-task', 'POST', 'mainwp_site_not_found' ),
			array( 'update-backup-task', 'POST', 'mainwp_backup_task_not_found' ),
			array( 'delete-backup-task', 'POST', 'mainwp_backup_task_not_found' ),
			array( 'pause-backup-task', 'POST', 'mainwp_backup_task_not_found' ),
			array( 'resume-backup-task', 'POST', 'mainwp_backup_task_not_found' ),
			array( 'restore-site-snapshot', 'POST', 'mainwp_backup_snapshot_not_found' ),
			array( 'cancel-restore', 'DELETE', 'mainwp_backup_restore_job_not_found' ),
			array( 'rollback-restore', 'POST', 'mainwp_backup_restore_job_not_found' ),
			array( 'test-backup-destination', 'POST', 'mainwp_backup_destination_not_found' ),
			array( 'set-default-backup-destination', 'POST', 'mainwp_backup_destination_not_found' ),
			array( 'update-backup-destination', 'POST', 'mainwp_backup_destination_not_found' ),
			array( 'delete-backup-destination', 'POST', 'mainwp_backup_destination_not_found' ),
			array( 'update-backup-settings', 'POST', 'rest_ability_not_found' ),

		);
	}

	/**
	 * Valid fields isolate transport and target errors from schema errors.
	 *
	 * @param string $name Action slug.
	 * @param bool   $missing Whether to use a missing target.
	 * @return array
	 */
	private function write_input( $name, $missing = false ) {
		$id = $missing ? 9007199254740991 : 987671;
		if ( 'update-backup-settings' === $name ) {
			return array(
				'on_demand_retention_count' => 7,
				'dry_run'                   => true,
			);
		}
		if ( false !== strpos( $name, 'destination' ) ) {
			return array(
				'destination_id' => $missing ? $id : $this->write_destination_id,
				'dry_run'        => true,
			) + ( 'update-backup-destination' === $name ? array( 'name' => 'REST rename' ) : array() );
		}
		if ( 'restore-site-snapshot' === $name ) {
			return array(
				'site_id_or_domain' => $this->site_id,
				'snapshot_id'       => $missing ? $id : 987673,
				'dry_run'           => true,
			);
		}
		if ( 'cancel-restore' === $name || 'rollback-restore' === $name ) {
			return array(
				'restore_job_id' => $missing ? $id : $this->write_restore_job_id,
				'dry_run'        => true,
			);
		}
		if ( 'create-backup-task' === $name ) {
			return array(
				'name'                => 'REST preview task',
				'type'                => 'full',
				'schedule'            => 'daily',
				'site_ids_or_domains' => array( $missing ? $id : $this->site_id ),
				'dry_run'             => true,
			);
		}
		if ( false !== strpos( $name, 'task' ) ) {
			return array(
				'task_id' => $id,
				'dry_run' => true,
			) + ( 'update-backup-task' === $name ? array( 'name' => 'REST edited task' ) : array() );
		}
		return array(
			'site_id_or_domain' => $missing ? $id : $this->site_id,
			'dry_run'           => true,
		);
	}

	/**
	 * The REST controller reads write input from the body before its callbacks run.
	 *
	 * @param string $method HTTP method.
	 * @param string $name Action slug.
	 * @param array  $input Caller input.
	 * @return \WP_REST_Response
	 */
	private function write_request( $method, $name, array $input ) {
		$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		if ( 'GET' === $method ) {
			$request->set_query_params( array( 'input' => $input ) );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		}
		return $this->guarded(
			function () use ( $request ) {
				return $this->server->dispatch( $request );
			}
		);
	}

	/**
	 * Authorized write previews must expose the envelope over the documented method.
	 *
	 * @dataProvider writes
	 * @param string $name Slug.
	 * @param string $method Method.
	 */
	public function test_authorized_write_dry_run_returns_200( $name, $method ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( 'reset-backup-task-run' === $name ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				987671,
				array(
					'last_run'  => time() - 7200,
					'completed' => 0,
				)
			);
		}
		$response = $this->write_request( $method, $name, $this->write_input( $name ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['dry_run'] );
		$valid = rest_validate_value_from_schema( $response->get_data(), wp_get_ability( 'mainwp/' . $name . '-v1' )->get_output_schema() );
		$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
	}

	/**
	 * Authentication must be required for previews as well as confirmed actions.
	 *
	 * @dataProvider writes
	 * @param string $name Slug.
	 * @param string $method Method.
	 */
	public function test_unauthenticated_write_returns_401( $name, $method ) {
		wp_set_current_user( 0 );
		$response = $this->write_request( $method, $name, $this->write_input( $name ) );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Missing targets must keep their exact refusal code through REST.
	 *
	 * @dataProvider writes
	 * @param string $name Slug.
	 * @param string $method Method.
	 * @param string $code Target code.
	 */
	public function test_write_missing_target_returns_exact_404( $name, $method, $code ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Global settings have no target, so their 404 is an unavailable ability.
		if ( 'update-backup-settings' === $name ) {
			$this->assertTrue( wp_has_ability( 'mainwp/update-backup-settings-v1' ) );
			$registry = \WP_Abilities_Registry::get_instance();
			$property = new \ReflectionProperty( \WP_Abilities_Registry::class, 'registered_abilities' );
			$property->setAccessible( true );
			$abilities = $property->getValue( $registry );
			$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
			wp_unregister_ability( 'mainwp/update-backup-settings-v1' );
		}
		try {
			$response = $this->write_request( $method, $name, $this->write_input( $name, true ) );
		} finally {
			if ( 'update-backup-settings' === $name ) {
				$property->setValue( $registry, $abilities );
			}
		}
		$this->assertSame( 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/** Unsupported destination fields must keep the ability refusal code over REST. */
	public function test_destination_update_unknown_fields_are_ability_invalid_input() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'settings', 'base_path', 'destination_ids', 'restore_job_id', 'unknown' ) as $key ) {
			$response = $this->write_request( 'POST', 'update-backup-destination', $this->write_input( 'update-backup-destination' ) + array( $key => 'private' ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'ability_invalid_input', $response->get_data()['code'], $key );
		}
	}

	/**
	 * Method annotations must prevent a preview from using the wrong verb.
	 *
	 * @dataProvider writes
	 * @param string $name Slug.
	 * @param string $method Method.
	 */
	public function test_write_wrong_method_returns_405( $name, $method ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wrong    = 'POST' === $method ? 'GET' : 'POST';
		$response = $this->write_request( $wrong, $name, $this->write_input( $name ) );
		$this->assertSame( 405, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_ability_invalid_method', $response->get_data()['code'] );
	}
}
