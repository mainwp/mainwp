<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Match neighboring REST test class filenames.
/**
 * REST settings tool job timestamps.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Verifies queued and completed jobs through the registered REST routes.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_REST_Settings_Tool_Timestamps extends MainWP_Abilities_Test_Case {

	/**
	 * Original request state and fixtures.
	 *
	 * @var array
	 */
	private $original = array();

	/**
	 * Queued tool jobs.
	 *
	 * @var array
	 */
	private $jobs = array();

	/**
	 * Child requests intercepted by the fixture.
	 *
	 * @var int
	 */
	private $child_calls = 0;

	/** Set up an authenticated administrator and prevent outbound requests. */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->original['server'] = $GLOBALS['wp_rest_server'] ?? null;
		$auth                     = \MainWP_REST_Authentication::get_instance();
		$user                     = new \ReflectionProperty( $auth, 'user' );
		$user->setAccessible( true );
		$this->original['user'] = $user->getValue( $auth );
		$user->setValue(
			$auth,
			(object) array(
				'permissions' => 'read_write',
				'user_id'     => get_current_user_id(),
				'key_id'      => 0,
			)
		);
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		( new \MainWP_Rest_Settings_Controller() )->register_routes();
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
		add_filter( 'mainwp_disable_rest_api_access_log', '__return_true' );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10, 3 );
	}

	/** Restore request state and remove scheduled fixture jobs. */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		remove_filter( 'mainwp_disable_rest_api_access_log', '__return_true' );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10 );
		foreach ( $this->jobs as $job ) {
			delete_transient( 'mainwp_tool_' . $job[0] . '_job_' . $job[1] );
			wp_clear_scheduled_hook( $job[2], array( $job[1] ) );
		}
		$user = new \ReflectionProperty( \MainWP_REST_Authentication::class, 'user' );
		$user->setAccessible( true );
		$user->setValue( \MainWP_REST_Authentication::get_instance(), $this->original['user'] );
		$GLOBALS['wp_rest_server'] = $this->original['server'];
		parent::tearDown();
	}

	/**
	 * Prevent WordPress HTTP and cron loopback requests.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new \WP_Error( 'test_no_http', 'HTTP is disabled in this fixture.' );
	}

	/**
	 * Stub at MainWP's transport boundary, which precedes its direct cURL calls.
	 *
	 * @param mixed  $pre Existing response.
	 * @param object $website Child site.
	 * @param string $what Requested operation.
	 * @return array Child response.
	 */
	public function child_response( $pre, $website, $what ) {
		++$this->child_calls;
		return 'renew' === $what ? array( 'error' => 'Fixture renewal refused.' ) : array(
			'result'  => 'success',
			'success' => 1,
		);
	}

	/**
	 * Tool route, job prefix, worker, and scheduling hook.
	 *
	 * @return array
	 */
	public static function tools() {
		return array(
			'disconnect' => array( 'disconnect-all-sites', 'disconnect', 'process_disconnect_batch', 'mainwp_cron_disconnect_batch' ),
			'destroy'    => array( 'destroy-sessions', 'destroy', 'process_destroy_session_batch', 'mainwp_cron_destroy_session_batch' ),
			'renew'      => array( 'renew-connections', 'renew', 'process_renew_connection_batch', 'mainwp_cron_renew_connection_batch' ),
		);
	}

	/**
	 * Queue a job through REST and retain its cleanup details.
	 *
	 * @param string $route Tool route.
	 * @param string $prefix Job prefix.
	 * @param string $hook Scheduled hook.
	 * @return string Job ID.
	 */
	private function queue_job( $route, $prefix, $hook ) {
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/mainwp/v2/settings/tools/' . $route ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id           = $response->get_data()[ $prefix . '_id' ];
		$this->jobs[] = array( $prefix, $id, $hook );
		$this->assertSame( 0, $this->child_calls, 'Queueing must not contact a Child.' );
		return $id;
	}

	/** A fresh disconnect status must retain the existing response keys without warnings. */
	public function test_fresh_disconnect_status_has_empty_started_at_and_unchanged_keys(): void {
		$this->create_test_site();
		$id       = $this->queue_job( 'disconnect-all-sites', 'disconnect', 'mainwp_cron_disconnect_batch' );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/mainwp/v2/settings/tools/disconnect-all-sites-status/' . $id ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '', $data['started_at'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertNull( $data['completed_at'] );
		$this->assertSame( array( 'success', 'status', 'total', 'processed', 'failed', 'progress', 'errors', 'started_at', 'completed_at' ), array_keys( $data ) );
	}

	/**
	 * First-batch timestamps survive later batches and completion.
	 *
	 * @dataProvider tools
	 * @param string $route Tool route.
	 * @param string $prefix Job prefix.
	 * @param string $worker Worker method.
	 * @param string $hook Scheduled hook.
	 */
	public function test_worker_records_started_at_once_and_preserves_response_keys( $route, $prefix, $worker, $hook ): void {
		for ( $i = 0; $i < 6; ++$i ) {
			$this->create_test_site();
		}
		$id         = $this->queue_job( $route, $prefix, $hook );
		$key        = 'mainwp_tool_' . $prefix . '_job_' . $id;
		$created_at = get_transient( $key )['created_at'];
		$controller = \MainWP_Rest_Settings_Controller::instance();
		$controller->$worker( $id );
		$status_route = '/mainwp/v2/settings/tools/' . $route . '-status/' . $id;
		$response     = rest_do_request( new \WP_REST_Request( 'GET', $status_route ) );
		$this->assertSame( 200, $response->get_status() );
		$first = $response->get_data();
		$this->assertSame( 5, $first['processed'] );
		$this->assertIsInt( $first['started_at'] );
		$this->assertGreaterThanOrEqual( $created_at, $first['started_at'] );
		$this->assertLessThanOrEqual( time(), $first['started_at'] );
		$this->assertSame( array( 'success', 'status', 'total', 'processed', 'failed', 'progress', 'errors', 'started_at', 'completed_at' ), array_keys( $first ) );
		// Cross a clock second so an unconditional timestamp assignment cannot pass.
		sleep( 1 );
		$controller->$worker( $id );
		$response = rest_do_request( new \WP_REST_Request( 'GET', $status_route ) );
		$this->assertSame( 200, $response->get_status() );
		$completed = $response->get_data();
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 6, $completed['processed'] );
		$this->assertSame( 6, $this->child_calls );
		$this->assertSame( $first['started_at'], $completed['started_at'] );
		$this->assertIsInt( $completed['completed_at'] );
		$this->assertSame( 100.0, $completed['progress'] );
		$this->assertSame( array_keys( $first ), array_keys( $completed ) );
		$controller->$worker( $id );
		$this->assertSame( $completed, rest_do_request( new \WP_REST_Request( 'GET', $status_route ) )->get_data() );
		$this->assertSame( 6, $this->child_calls, 'A completed job must not repeat Child operations.' );
	}
}
