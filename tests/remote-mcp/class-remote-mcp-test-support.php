<?php
/**
 * Shared support for the Remote MCP store tests: a store with an injectable clock and
 * interleave seams, and the real-table setup the store statements need.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

// The module only loads on PHP 8.1+ single sites (its bootstrap returns early otherwise). Declaring
// a subclass of a missing class would fatal while PHPUnit collects tests, so the subclass is only
// declared when the module is loaded, and the tests skip themselves.
if ( class_exists( OAuth_Store::class ) ) {

	/**
	 * OAuth_Store with a test clock and seams that run a competing call at a chosen point.
	 */
	class Remote_MCP_Test_Store extends OAuth_Store {

		/**
		 * Shared clock object; every store built by one test reads the same `now`.
		 *
		 * @var \stdClass
		 */
		public $clock;

		/**
		 * Pending seam callbacks, keyed by seam name.
		 *
		 * @var array
		 */
		private $seams = array();

		/**
		 * How often each seam was reached.
		 *
		 * @var array
		 */
		public $seam_calls = array();

		/**
		 * Constructor.
		 *
		 * @param OAuth_Token_Hasher $hasher   Hasher.
		 * @param string             $resource Canonical resource.
		 * @param \stdClass          $clock    Shared clock.
		 */
		public function __construct( OAuth_Token_Hasher $hasher, string $resource, \stdClass $clock ) {
			parent::__construct( $hasher, $resource );
			$this->clock = $clock;
		}

		/**
		 * Run $callback the next $times times the seam is reached.
		 *
		 * @param string   $seam     Seam method name.
		 * @param callable $callback Competing call.
		 * @param int      $times    Number of firings.
		 */
		public function on( string $seam, callable $callback, int $times = 1 ): void {
			$this->seams[ $seam ] = array(
				'callback' => $callback,
				'times'    => $times,
			);
		}

		/**
		 * Test clock.
		 *
		 * @return int
		 */
		protected function now(): int {
			return (int) $this->clock->now;
		}

		/**
		 * Fire a seam.
		 *
		 * @param string $seam Seam name.
		 * @param array  $args Seam arguments.
		 */
		private function fire( string $seam, array $args ): void {
			$this->seam_calls[ $seam ] = ( $this->seam_calls[ $seam ] ?? 0 ) + 1;
			if ( empty( $this->seams[ $seam ] ) ) {
				return;
			}
			$callback = $this->seams[ $seam ]['callback'];
			--$this->seams[ $seam ]['times'];
			if ( $this->seams[ $seam ]['times'] <= 0 ) {
				unset( $this->seams[ $seam ] );
			}
			$callback( ...$args );
		}

		protected function after_code_read(): void {
			$this->fire( 'after_code_read', array() );
		}

		protected function after_claim( int $grant_id ): void {
			$this->fire( 'after_claim', array( $grant_id ) );
		}

		protected function before_publish( int $grant_id, array $tokens ): void {
			$this->fire( 'before_publish', array( $grant_id, $tokens ) );
		}

		protected function after_grant_read( string $transition ): void {
			$this->fire( 'after_grant_read', array( $transition ) );
		}

		protected function after_grace_check(): void {
			$this->fire( 'after_grace_check', array() );
		}
	}
}

/**
 * Detach the boot provider's revocation hooks for one test: store tests exercise transitions
 * directly, and lifecycle tests register their own provider. WP_UnitTestCase restores the hooks.
 */
function remote_mcp_detach_boot_revocation_hooks() {
	foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof \MainWP\Dashboard\Remote_MCP\OAuth_Revocation ) {
					$hook->remove_filter( $hook_name, $function, $priority );
				}
			}
		}
	}
}

/**
 * Real tables, a clock and connection helpers for store-level tests.
 */
trait Remote_MCP_Store_Fixtures {

	/**
	 * Test clock.
	 *
	 * @var \stdClass
	 */
	protected $clock;

	/**
	 * Hasher.
	 *
	 * @var OAuth_Token_Hasher
	 */
	protected $hasher;

	/**
	 * Store under test.
	 *
	 * @var Remote_MCP_Test_Store
	 */
	protected $store;

	/**
	 * Administrator who owns the default connections.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Create the module tables before the class's first test.
	 *
	 * The harness rewrites CREATE TABLE to CREATE TEMPORARY TABLE inside each test, and MySQL
	 * cannot open one temporary table twice in a statement (ER_CANT_REOPEN_TABLE, 1137). Grant
	 * publication, the consent claim and the token lookup each join mainwp_oauth_generations
	 * twice, and production SQL should not change to suit the tests, so the tables are
	 * created as real tables here, where the per-test rewrite filters are not attached. Rows each
	 * test writes still roll back with the harness transaction; the tables are dropped after the class.
	 *
	 * @param mixed $factory Unused factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		if ( ! class_exists( OAuth_Store::class ) ) {
			return;
		}
		self::drop_module_tables();
		Remote_MCP_Install::install();
	}

	/**
	 * Drop the real tables and the options the before-class install committed.
	 */
	public static function wpTearDownAfterClass() {
		if ( ! class_exists( OAuth_Store::class ) ) {
			return;
		}
		self::drop_module_tables();
	}

	/**
	 * Drop module tables, including temporary ones an interrupted install test may have left on
	 * this connection, which would otherwise shadow the real tables.
	 */
	private static function drop_module_tables(): void {
		global $wpdb;
		foreach ( array_merge( Remote_MCP_Install::TABLES, array( 'approvals', 'jobs', 'clients' ) ) as $name ) {
			$table = Remote_MCP_Install::table( $name );
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS `{$table}`" );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( Remote_MCP_Settings::OPTION_DB_VERSION );
		delete_option( Remote_MCP_Settings::OPTION_SECRET );
	}

	/**
	 * Per-test clock, store and user.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! class_exists( OAuth_Store::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		remote_mcp_detach_boot_revocation_hooks();
		$this->clock   = (object) array( 'now' => 1800000000 );
		$this->hasher  = new OAuth_Token_Hasher();
		$this->store   = $this->new_store();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * A store sharing this test's clock.
	 *
	 * @param string $resource Canonical resource.
	 * @return Remote_MCP_Test_Store
	 */
	protected function new_store( string $resource = self::RESOURCE ): Remote_MCP_Test_Store {
		return new Remote_MCP_Test_Store( $this->hasher, $resource, $this->clock );
	}

	/**
	 * S256 challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	protected function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Consent request parameters.
	 *
	 * @param int   $user_id   Owner.
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	protected function request_params( int $user_id = 0, array $overrides = array() ): array {
		return array_merge(
			array(
				'user_id'        => $user_id ? $user_id : $this->user_id,
				'client_id'      => self::CLIENT_ID,
				'client_host'    => 'claude.ai',
				'redirect_uri'   => self::REDIRECT,
				'scope'          => 'mainwp:read offline_access',
				'resource'       => self::RESOURCE,
				'code_challenge' => $this->challenge( self::VERIFIER ),
				'state'          => 'client-state',
			),
			$overrides
		);
	}

	/**
	 * Run consent approval and return the issued code.
	 *
	 * @param int                        $user_id   Owner.
	 * @param array                      $overrides Request field overrides.
	 * @param Remote_MCP_Test_Store|null $store     Store.
	 * @return string
	 */
	protected function issue_code( int $user_id = 0, array $overrides = array(), $store = null ): string {
		$store   = $store ? $store : $this->store;
		$params  = $this->request_params( $user_id, $overrides );
		$request = $store->create_request( $params );
		$this->assertTrue( $store->claim_request( $request['id'], $params['user_id'], true ), 'consent claim' );
		$code = $store->issue_code( $request['id'] );
		$this->assertIsString( $code, 'code issued' );
		return $code;
	}

	/**
	 * Redeem a code with the default client and verifier.
	 *
	 * @param string                     $code  Code.
	 * @param Remote_MCP_Test_Store|null $store Store.
	 * @return array
	 */
	protected function redeem( string $code, $store = null ): array {
		$store = $store ? $store : $this->store;
		return $store->redeem_code( $code, self::CLIENT_ID, self::VERIFIER );
	}

	/**
	 * Full connection: request, approval, code, exchange.
	 *
	 * @param int   $user_id   Owner.
	 * @param array $overrides Request field overrides.
	 * @return array Successful exchange result.
	 */
	protected function connect( int $user_id = 0, array $overrides = array() ): array {
		$code   = $this->issue_code( $user_id, $overrides );
		$result = $this->store->redeem_code( $code, $overrides['client_id'] ?? self::CLIENT_ID, self::VERIFIER );
		$this->assertSame( OAuth_Store::OK, $result['outcome'], 'connect' );
		return $result;
	}

	/**
	 * Present a refresh token with the default client.
	 *
	 * @param string                     $token Refresh token.
	 * @param Remote_MCP_Test_Store|null $store Store.
	 * @return array
	 */
	protected function refresh( string $token, $store = null ): array {
		$store = $store ? $store : $this->store;
		return $store->refresh( $token, self::CLIENT_ID );
	}

	/**
	 * Whether an access token is accepted.
	 *
	 * @param string                     $token Access token.
	 * @param Remote_MCP_Test_Store|null $store Store.
	 * @return bool
	 */
	protected function access_ok( string $token, $store = null ): bool {
		$store = $store ? $store : $this->store;
		return OAuth_Store::OK === $store->check_access_token( $token )['outcome'];
	}

	/**
	 * Grant row.
	 *
	 * @param int $grant_id Grant id.
	 * @return array
	 */
	protected function grant( int $grant_id ): array {
		$grant = $this->store->get_grant( $grant_id );
		$this->assertIsArray( $grant, 'grant row exists' );
		return $grant;
	}

	/**
	 * Rows of a module table.
	 *
	 * @param string $name  Table short name.
	 * @param string $where Trusted WHERE clause.
	 * @return array
	 */
	protected function rows( string $name, string $where = '1=1' ): array {
		global $wpdb;
		$table = Remote_MCP_Install::table( $name );
		return $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY 1", ARRAY_A );
	}

	/**
	 * Token pairs stored for a grant, as "type:pair" => count.
	 *
	 * @param int $grant_id Grant id.
	 * @return array
	 */
	protected function pair_counts( int $grant_id ): array {
		$counts = array();
		foreach ( $this->rows( 'tokens', 'grant_id = ' . (int) $grant_id ) as $row ) {
			$key            = $row['token_type'] . ':' . $row['pair'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * Current generation of a subject, 0 when its row is missing.
	 *
	 * @param int $subject_id Subject id; 0 is the site.
	 * @return int
	 */
	protected function generation( int $subject_id ): int {
		global $wpdb;
		$table = Remote_MCP_Install::table( 'generations' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT generation FROM `{$table}` WHERE subject_id = %d", $subject_id ) );
	}

	/**
	 * Make statements containing $needle fail while `failures` is above zero, counting every attempt.
	 * The harness restores hooks after each test, so the filter never outlives it.
	 *
	 * @param string $needle Substring identifying the targeted statement.
	 * @return \stdClass Control with `needle`, `failures` and `attempts`.
	 */
	protected function fail_queries( string $needle ): \stdClass {
		$control = (object) array(
			'needle'   => $needle,
			'failures' => 0,
			'attempts' => 0,
		);
		add_filter(
			'query',
			function ( $query ) use ( $control ) {
				if ( false === strpos( $query, $control->needle ) ) {
					return $query;
				}
				++$control->attempts;
				if ( $control->failures > 0 ) {
					--$control->failures;
					return 'SELECT 1 FROM `remote_mcp_injected_failure`';
				}
				return $query;
			}
		);
		return $control;
	}

	/**
	 * Advance the clock.
	 *
	 * @param int $seconds Seconds.
	 */
	protected function tick( int $seconds ): void {
		$this->clock->now += $seconds;
	}
}

if ( interface_exists( \MainWP\Dashboard\Remote_MCP\Route_Handler::class ) ) {

	/**
	 * Route handler that records each request and answers with a fixed response.
	 */
	class Remote_MCP_Spy_Route implements \MainWP\Dashboard\Remote_MCP\Route_Handler {

		/**
		 * Requests received.
		 *
		 * @var \MainWP\Dashboard\Remote_MCP\Remote_MCP_Request[]
		 */
		public $calls = array();

		/**
		 * Response to return.
		 *
		 * @var \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
		 */
		public $response;

		/**
		 * Constructor.
		 *
		 * @param \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response|null $response Response to return.
		 */
		public function __construct( $response = null ) {
			$this->response = $response ? $response : \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response::json( 200, array( 'spy' => true ) );
		}

		/**
		 * Record and answer.
		 *
		 * @param \MainWP\Dashboard\Remote_MCP\Remote_MCP_Request $request Request.
		 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
		 */
		public function handle( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Request $request ): \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response {
			$this->calls[] = $request;
			return $this->response;
		}
	}
}

/**
 * Front controller fixtures: an enabled connector on an HTTPS home, spy route handlers and a
 * request helper that never touches the real php://input.
 */
trait Remote_MCP_Front_Fixtures {

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	protected $saved_server;

	/**
	 * Spy handler per route slot.
	 *
	 * @var Remote_MCP_Spy_Route[]
	 */
	protected $spies = array();

	/**
	 * How often the controller read the request body.
	 *
	 * @var int
	 */
	protected $body_reads = 0;

	/**
	 * Enabled connector on an HTTPS root install with pretty permalinks.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! class_exists( Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		remote_mcp_detach_boot_revocation_hooks();
		$this->saved_server = $_SERVER;
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		delete_option( Remote_MCP_Settings::OPTION_COMPETING_RULES );
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['SERVER_PORT']    = '443';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->body_reads          = 0;
	}

	/**
	 * Restore $_SERVER.
	 */
	public function tear_down() {
		if ( is_array( $this->saved_server ) ) {
			$_SERVER = $this->saved_server;
		}
		parent::tear_down();
	}

	/**
	 * Services whose route slots are spies.
	 *
	 * @param array $responses Optional response per slot.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Services
	 */
	protected function spy_services( array $responses = array() ) {
		$overrides = array();
		foreach ( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Services::ROUTE_SLOTS as $slot ) {
			$this->spies[ $slot ] = new Remote_MCP_Spy_Route( $responses[ $slot ] ?? null );
			$overrides[ $slot ]   = $this->spies[ $slot ];
		}
		return new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Services( $overrides );
	}

	/**
	 * Front controller over spy services.
	 *
	 * @param callable|null $is_active Active predicate.
	 * @param array         $responses Optional response per slot.
	 * @return \MainWP\Dashboard\Remote_MCP\MCP_Front_Controller
	 */
	protected function controller( $is_active = null, array $responses = array() ) {
		return new \MainWP\Dashboard\Remote_MCP\MCP_Front_Controller( $this->spy_services( $responses ), $is_active );
	}

	/**
	 * Dispatch one request the way WordPress would hand it to parse_request.
	 *
	 * @param \MainWP\Dashboard\Remote_MCP\MCP_Front_Controller $controller Controller.
	 * @param string                                            $uri        REQUEST_URI, query string included.
	 * @param string                                            $method     HTTP method.
	 * @param array                                             $query_vars Query vars WordPress resolved.
	 * @param string                                            $body       Raw body.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response|null
	 */
	protected function dispatch( $controller, string $uri, string $method = 'POST', array $query_vars = array(), string $body = '' ) {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$query                     = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$_SERVER['QUERY_STRING']   = $query;
		$wp                        = new \WP();
		$wp->query_vars            = $query_vars;
		return $controller->dispatch(
			$wp,
			function () use ( $body ) {
				++$this->body_reads;
				return $body;
			}
		);
	}

	/**
	 * Query vars for a REST route.
	 *
	 * @param string $route Route.
	 * @return array
	 */
	protected function rest( string $route ): array {
		return array( 'rest_route' => $route );
	}

	/**
	 * Total calls across every spy.
	 *
	 * @return int
	 */
	protected function spy_calls(): int {
		$total = 0;
		foreach ( $this->spies as $spy ) {
			$total += count( $spy->calls );
		}
		return $total;
	}
}
