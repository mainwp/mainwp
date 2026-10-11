<?php
/**
 * REST must preserve read methods and target refusals at the execution boundary.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB;

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
}
