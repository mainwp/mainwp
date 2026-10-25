<?php
/**
 * Regression tests for REST v2 reconnect, checking and disconnect job URLs.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;

/**
 * API key authentication defines a process-wide REST constant.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_REST_V2_Site_Operations extends \WP_Test_REST_TestCase {

	use Site_Fixture;

	/**
	 * Fixture consumer key.
	 *
	 * @var string
	 */
	private $consumer_key;

	/**
	 * Fixture consumer secret.
	 *
	 * @var string
	 */
	private $consumer_secret;

	/**
	 * Captured Child requests.
	 *
	 * @var array
	 */
	private $child_calls = array();

	/**
	 * Monitoring transport result.
	 *
	 * @var array
	 */
	private $monitor_result = array();

	/**
	 * Whether monitoring transport throws.
	 *
	 * @var bool
	 */
	private $monitor_throws = false;

	/** Set up the same route registration and key authentication as the REST v2 tests. */
	public function setUp(): void {
		parent::setUp();
		$property = new \ReflectionProperty( \MainWP_Rest_Server::class, 'controllers' );
		$property->setAccessible( true );
		$property->setValue( \MainWP_Rest_Server::instance(), array() );
		\MainWP_REST_Authentication::$instance = null;
		if ( ! has_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ) ) ) {
			add_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ) );
		}
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$this->consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_api_keys',
				array(
					'user_id'         => $user_id,
					'description'     => 'REST site operations fixture',
					'permissions'     => 'read_write',
					'consumer_key'    => mainwp_api_hash( $this->consumer_key ),
					'consumer_secret' => $this->consumer_secret,
					'truncated_key'   => substr( $this->consumer_key, -7 ),
					'enabled'         => 1,
					'last_access'     => current_time( 'mysql' ),
				)
			)
		);
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'capture_child_call' ), 10, 3 );
		add_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'refuse_registration' ) );
		add_filter( 'mainwp_fetch_uptime_monitor_pre', array( $this, 'monitor_transport' ) );
		add_filter( 'pre_http_request', array( $this, 'refuse_http' ) );
	}

	/** Remove transport fixtures and REST singletons. */
	public function tearDown(): void {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'capture_child_call' ), 10 );
		remove_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'refuse_registration' ) );
		remove_filter( 'mainwp_fetch_uptime_monitor_pre', array( $this, 'monitor_transport' ) );
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ) );
		$GLOBALS['wp_rest_server']             = null;
		\MainWP_REST_Authentication::$instance = null;
		$property                              = new \ReflectionProperty( \MainWP_Rest_Server::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		parent::tearDown();
	}

	/**
	 * Capture the reconnect sync and return a successful Child response.
	 *
	 * @param mixed  $pre     Unused short-circuit value.
	 * @param object $website Site being contacted.
	 * @param string $action  Child command.
	 * @return array Child response.
	 */
	public function capture_child_call( $pre, $website, $action ) {
		$this->child_calls[] = array( (int) $website->id, $action );
		$this->assertSame( 'stats', $action );
		return array( 'version' => '6.4' );
	}

	/** A reconnect must succeed through the captured sync, without a registration request. */
	public function refuse_registration() {
		$this->fail( 'Unexpected Child registration request.' );
	}

	/** Reject any WordPress HTTP request, including cron loopbacks. */
	public function refuse_http() {
		return new \WP_Error( 'fixture_no_network', 'Network disabled in route fixture.' );
	}

	/**
	 * Return or throw from the existing monitoring transport fixture.
	 *
	 * @throws \RuntimeException When the exception fixture is enabled.
	 */
	public function monitor_transport() {
		if ( $this->monitor_throws ) {
			throw new \RuntimeException( 'Fixture monitoring failure & retry' );
		}
		return $this->monitor_result;
	}

	/**
	 * Authenticate and dispatch through the registered WordPress REST server.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST path.
	 * @param array  $params Request parameters.
	 * @return \WP_REST_Response Response.
	 */
	private function request( $method, $route, $params = array() ) {
		$original_get    = $_GET;
		$original_server = $_SERVER;
		$original_query  = $GLOBALS['wp']->query_vars;
		try {
			$_GET['consumer_key']                    = $this->consumer_key;
			$_GET['consumer_secret']                 = $this->consumer_secret;
			$_SERVER['HTTPS']                        = 'on';
			$_SERVER['REQUEST_URI']                  = '/wp-json' . $route;
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
			\MainWP_REST_Authentication::$instance   = null;
			\MainWP_REST_Authentication::get_instance()->authenticate( 0 );
			$request = new \WP_REST_Request( $method, $route );
			$request->set_body_params( $params );
			return rest_get_server()->dispatch( $request );
		} finally {
			$_GET                      = $original_get;
			$_SERVER                   = $original_server;
			$GLOBALS['wp']->query_vars = $original_query;
		}
	}

	/** A healthy first or intervening site must not stop later reconnects. */
	public function test_reconnect_skips_healthy_sites_and_attempts_every_broken_site(): void {
		$this->create_test_site(
			array(
				'url'         => 'https://a.example/',
				'sync_errors' => '',
			)
		);
		$broken_one = $this->create_test_site(
			array(
				'url'         => 'https://b.example/',
				'sync_errors' => 'Disconnected',
			)
		);
		$this->create_test_site(
			array(
				'url'         => 'https://c.example/',
				'sync_errors' => '',
			)
		);
		$broken_two = $this->create_test_site(
			array(
				'url'         => 'https://d.example/',
				'sync_errors' => 'Timeout',
			)
		);

		$response = $this->request( 'POST', '/mainwp/v2/sites/reconnect' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'total' => 2,
				'data'  => array(
					$broken_one => 'success',
					$broken_two => 'success',
				),
			),
			$response->get_data()
		);
		$this->assertSame( array( array( $broken_one, 'stats' ), array( $broken_two, 'stats' ) ), $this->child_calls );
	}

	/** Include and exclude still constrain reconnects. */
	public function test_reconnect_preserves_include_and_exclude(): void {
		$included = $this->create_test_site(
			array(
				'url'         => 'https://a.example/',
				'sync_errors' => 'Disconnected',
			)
		);
		$excluded = $this->create_test_site(
			array(
				'url'         => 'https://b.example/',
				'sync_errors' => 'Disconnected',
			)
		);
		$this->create_test_site(
			array(
				'url'         => 'https://c.example/',
				'sync_errors' => 'Disconnected',
			)
		);
		$response = $this->request(
			'POST',
			'/mainwp/v2/sites/reconnect',
			array(
				'include' => array( $included, $excluded ),
				'exclude' => array( $excluded ),
			)
		);
		$this->assertSame(
			array(
				'total' => 1,
				'data'  => array( $included => 'success' ),
			),
			$response->get_data()
		);
		$this->assertSame( array( array( $included, 'stats' ) ), $this->child_calls );
	}

	/**
	 * Check responses retain actual monitoring status, including DOWN zero.
	 *
	 * @dataProvider monitoring_results
	 * @param array $result Transport result.
	 * @param int   $status Expected response status value.
	 */
	public function test_check_uses_monitor_result_or_http_fallback( $result, $status ): void {
		$id = $this->create_test_site();
		$this->assertGreaterThan(
			0,
			MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
				array(
					'wpid'  => $id,
					'issub' => 0,
				)
			)
		);
		$this->monitor_result = $result;
		$response             = $this->request( 'POST', '/mainwp/v2/sites/' . $id . '/check' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'] );
		$this->assertSame( $status, $data['data']['status'] );
		$this->assertSame( $result['httpCode'], $data['data']['http_code'] );
	}

	/**
	 * Monitoring results emitted by the real handler.
	 *
	 * @return array
	 */
	public static function monitoring_results(): array {
		return array(
			'pending overrides HTTP 200' => array(
				array(
					'httpCode'          => 200,
					'new_uptime_status' => MainWP_Uptime_Monitoring_Connect::PENDING,
				),
				2,
			),
			'down overrides HTTP 200'    => array(
				array(
					'httpCode'          => 200,
					'new_uptime_status' => MainWP_Uptime_Monitoring_Connect::DOWN,
				),
				0,
			),
			'legacy online fallback'     => array( array( 'httpCode' => 200 ), 1 ),
			'legacy offline fallback'    => array( array( 'httpCode' => 503 ), 0 ),
		);
	}

	/** An exception returns its escaped message without an undefined-variable warning. */
	public function test_check_returns_exception_message_without_a_warning(): void {
		$id = $this->create_test_site();
		$this->assertGreaterThan(
			0,
			MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
				array(
					'wpid'  => $id,
					'issub' => 0,
				)
			)
		);
		$this->monitor_throws = true;
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Any warning must fail this regression test.
			static function ( $severity, $message, $file, $line ) {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			},
			E_WARNING | E_NOTICE
		);
		try {
			$response = $this->request( 'POST', '/mainwp/v2/sites/' . $id . '/check' );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['success'] );
		$this->assertSame( 'Fixture monitoring failure &amp; retry', $response->get_data()['error'] );
	}

	/** The disconnect tool returns a URL that reaches its registered status route. */
	public function test_disconnect_status_url_dispatches_to_the_status_route(): void {
		$this->create_test_site();
		$response = $this->request( 'POST', '/mainwp/v2/settings/tools/disconnect-all-sites' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'] );
		$transient = 'mainwp_tool_disconnect_job_' . $data['disconnect_id'];
		$job       = get_transient( $transient );
		$this->assertIsArray( $job );
		// The status reader expects started_at, which the pending job does not yet set.
		$job['started_at'] = $job['created_at'];
		set_transient( $transient, $job, DAY_IN_SECONDS );
		$route = substr( $data['status_url'], strlen( rest_url() ) );
		try {
			$status = $this->request( 'GET', '/' . ltrim( $route, '/' ) );
			$this->assertSame( 200, $status->get_status(), wp_json_encode( $status->get_data() ) );
			$this->assertSame( 'pending', $status->get_data()['status'] );
			$this->assertSame( 1, $status->get_data()['total'] );
			$this->assertSame( array(), $this->child_calls );
		} finally {
			delete_transient( $transient );
			wp_clear_scheduled_hook( 'mainwp_cron_disconnect_batch', array( $data['disconnect_id'] ) );
		}
	}
}
