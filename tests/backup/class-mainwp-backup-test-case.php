<?php
/**
 * Base test case for backup download integration tests.
 *
 * Starts a PHP built-in server serving the fixture routes before the
 * class runs and stops it afterwards. Picks a free port dynamically and
 * waits for the server to accept connections to avoid flaky starts.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions -- test harness process and socket management.

/**
 * Class MainWP_Backup_Test_Case
 */
abstract class MainWP_Backup_Test_Case extends \WP_UnitTestCase {

	/**
	 * Fixture server process handle.
	 *
	 * @var resource|null
	 */
	protected static $server_process = null;

	/**
	 * Fixture server port.
	 *
	 * @var int
	 */
	protected static $server_port = 0;

	/**
	 * Each test represents a new request for backup indicator caching.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$cache = new \ReflectionProperty( \MainWP\Dashboard\MainWP_Backup_Executor::class, 'recent_native_backup_maps' );
		$cache->setValue( null, array() );
	}

	/**
	 * Start the fixture server once for the test class.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		static::start_fixture_server();
	}

	/**
	 * Stop the fixture server after the test class.
	 *
	 * @return void
	 */
	public static function tear_down_after_class() {
		static::stop_fixture_server();
		parent::tear_down_after_class();
	}

	/**
	 * Deterministic body content matching the fixture server.
	 *
	 * @param int $size Byte length.
	 *
	 * @return string Content.
	 */
	protected static function fixture_content( $size ) {
		return substr( str_repeat( '0123456789ABCDEF', (int) ceil( $size / 16 ) ), 0, $size );
	}

	/**
	 * Build a fixture URL for a route.
	 *
	 * @param string $route Route path including query, e.g. '/200?size=4096'.
	 *
	 * @return string Absolute URL.
	 */
	protected static function fixture_url( $route ) {
		return 'http://127.0.0.1:' . static::$server_port . $route;
	}

	/**
	 * Create a temp file path for a download target.
	 *
	 * @return string Path (file not created).
	 */
	protected function temp_download_path() {
		$dir = get_temp_dir() . 'mainwp-backup-tests/';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		return $dir . uniqid( 'download-', true ) . '.bin';
	}

	/**
	 * Start the PHP built-in server on a free port.
	 *
	 * @return void
	 * @throws \RuntimeException When the server does not come up.
	 */
	protected static function start_fixture_server() {
		if ( null !== static::$server_process ) {
			return;
		}

		$docroot = __DIR__ . '/fixtures/server.php';

		for ( $try = 0; $try < 3; $try++ ) {
			$port = static::find_free_port();
			// An argument array skips the shell, so proc_terminate() signals the server itself.
			$cmd = array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $docroot );

			$descriptors = array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			);

			$process = proc_open( $cmd, $descriptors, $pipes );
			if ( ! is_resource( $process ) ) {
				continue;
			}

			// Wait for the server to accept connections.
			$deadline = microtime( true ) + 5;
			while ( microtime( true ) < $deadline ) {
				$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
				if ( false !== $socket ) {
					fclose( $socket );
					static::$server_process = $process;
					static::$server_port    = $port;
					return;
				}
				usleep( 50000 );
			}

			proc_terminate( $process );
			proc_close( $process );
		}

		throw new \RuntimeException( 'Could not start fixture HTTP server for backup download tests.' );
	}

	/**
	 * Stop the fixture server.
	 *
	 * @return void
	 */
	protected static function stop_fixture_server() {
		if ( null !== static::$server_process && is_resource( static::$server_process ) ) {
			proc_terminate( static::$server_process );
			proc_close( static::$server_process );
		}
		static::$server_process = null;
		static::$server_port    = 0;
	}

	/**
	 * Find a free TCP port.
	 *
	 * @return int Port number.
	 * @throws \RuntimeException When no port could be allocated.
	 */
	protected static function find_free_port() {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( false === $server ) {
			throw new \RuntimeException( 'Could not allocate a free port: ' . $errstr );
		}
		$name = stream_socket_get_name( $server, false );
		fclose( $server );
		$parts = explode( ':', $name );
		return (int) end( $parts );
	}
}

/**
 * A caught transport exception must not hide an unsafe cancellation request.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- shared backup transport guard.
trait Backup_Cancel_Transport_Guard {

	/**
	 * Unsafe requests observed during this test.
	 *
	 * @var int
	 */
	private $empty_cancel_requests = 0;

	/**
	 * Install before each test, including tests without fixture setup.
	 *
	 * @before
	 * @return void
	 */
	public function install_cancel_transport_guard() {
		$this->empty_cancel_requests = 0;
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'guard_cancel_transport' ), -9999, 4 );
	}

	/**
	 * Track before response stubs can short-circuit the request.
	 *
	 * @param mixed  $response Stubbed response.
	 * @param object $website Child site.
	 * @param string $what Request action.
	 * @param array  $params Request arguments.
	 * @return mixed Unchanged response.
	 */
	public function guard_cancel_transport( $response, $website, $what, $params ) {
		if ( 'backup_task_cancel' === $what && empty( $params['task_id'] ) ) {
			++$this->empty_cancel_requests;
		}
		return $response;
	}

	/**
	 * Assert after the handler so caught errors cannot mask the violation.
	 *
	 * @after
	 * @return void
	 */
	public function assert_cancel_transport_guard() {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'guard_cancel_transport' ), -9999 );
		$this->assertSame( 0, $this->empty_cancel_requests, 'A backup cancel must never send an empty task_id.' );
	}
}
