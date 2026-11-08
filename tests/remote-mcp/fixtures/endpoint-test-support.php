<?php
/**
 * Shared support for the Remote MCP endpoint tests: an endpoint wired to a test store, doubles for
 * the slots outside the endpoint's own classes, request builders and test abilities.
 *
 * It lives under fixtures/ so the suite never runs it as a test file.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Approval_Gate;
use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Control_Contract;
use MainWP\Dashboard\Remote_MCP\Job_Runner;
use MainWP\Dashboard\Remote_MCP\Known_Clients;
use MainWP\Dashboard\Remote_MCP\MCP_Endpoint;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once dirname( __DIR__ ) . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter

if ( class_exists( Remote_MCP_Services::class ) && ! class_exists( Remote_MCP_Endpoint_Test_Services::class, false ) ) {

	/**
	 * Registry whose store is the test's clocked store.
	 */
	class Remote_MCP_Endpoint_Test_Services extends Remote_MCP_Services {

		public $test_store;

		public function __construct( array $overrides, Remote_MCP_Urls $urls, $store ) {
			parent::__construct( $overrides, $urls );
			$this->test_store = $store;
		}

		public function store(): ?OAuth_Store {
			return $this->test_store;
		}
	}

	/**
	 * Client lookup with fixed entries.
	 */
	class Remote_MCP_Endpoint_Test_Known_Clients implements Known_Clients {

		public $clients = array();

		public function __construct() {
			$this->add( 'https://claude.ai/oauth/mcp-oauth-client-metadata', 'bundled', 'result', 'challenge' );
			$this->add( 'https://claude.ai/oauth/claude-code-client-metadata', 'bundled', 'elicitation', 'challenge' );
			$this->add( 'https://chatgpt.com/oauth/client.json', 'bundled', 'result', 'chatgpt_meta' );
			$this->add( 'mainwp-cursor', 'static', 'result', 'result' );
			$this->add( OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, 'token', 'result', 'result' );
		}

		public function add( string $client_id, string $kind, string $delivery, string $step_up ): void {
			$this->clients[ $client_id ] = array(
				'client_id'                   => $client_id,
				'kind'                        => $kind,
				'label'                       => $client_id,
				'client_name'                 => null,
				'redirect_rule'               => 'exact',
				'redirect_uris'               => array(),
				'token_endpoint_auth_methods' => array( 'none' ),
				'oauth_endpoints'             => 'token' !== $kind,
				'approval_delivery'           => $delivery,
				'enabled'                     => true,
				'step_up'                     => $step_up,
			);
		}

		public function lookup( string $client_id ): ?array {
			return $this->clients[ $client_id ] ?? null;
		}

		public function redirect_allowed( array $client, string $redirect_uri ): bool {
			return false;
		}

		public function validate_redirect( string $kind, string $redirect_uri ): bool {
			return false;
		}
	}

	/**
	 * Gate that records every call. check() answers `run` with a one-entry plan, or the decision
	 * set in $decision; deliver() runs a `run` plan through the tool executor, the way the real
	 * gate does after its consume, unless $deliver_result is set.
	 */
	class Remote_MCP_Endpoint_Test_Gate implements Approval_Gate {

		public $services;
		public $decision       = null;
		public $deliver_result = null;
		public $checks         = array();
		public $delivers       = array();
		public $consumes       = 0;

		public function __construct( $services = null ) {
			$this->services = $services;
		}

		public function check( Remote_MCP_Context $context, string $ability, array $arguments ): array {
			$this->checks[] = array(
				'context'   => $context,
				'ability'   => $ability,
				'arguments' => $arguments,
			);
			if ( null !== $this->decision ) {
				return $this->decision;
			}
			unset( $arguments['user_confirmed'], $arguments['confirmation_token'] );
			return array(
				'decision'    => 'run',
				'tier'        => 'read',
				'reason'      => null,
				'approval_id' => null,
				'plan'        => Call_Plan::from_entries(
					array(
						array(
							'ability'    => $ability,
							'args'       => empty( $arguments ) ? new \stdClass() : $arguments,
							'target_ids' => null,
						),
					)
				),
			);
		}

		public function deliver( Remote_MCP_Context $context, array $decision ): array {
			$this->delivers[] = array(
				'context'  => $context,
				'decision' => $decision,
			);
			if ( null !== $this->deliver_result ) {
				return $this->deliver_result;
			}
			if ( 'run' !== $decision['decision'] || ! $decision['plan'] instanceof Call_Plan || null === $this->services ) {
				return array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'gate: ' . $decision['decision'],
						),
					),
					'isError' => 'run' !== $decision['decision'],
				);
			}
			$run = $this->services->get( 'tool_executor' )->execute_plan_entry( $decision['plan'], 0, $context->user_id, $context->grant_id );
			if ( ! $run['ok'] ) {
				$message = $run['result'] instanceof \WP_Error ? $run['result']->get_error_code() : (string) $run['reason'];
				return array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $message,
						),
					),
					'isError' => true,
				);
			}
			return array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $run['result'] ),
					),
				),
				'structuredContent' => $run['result'],
				'isError'           => false,
			);
		}

		public function consume( Remote_MCP_Context $context, array $decision ): ?Call_Plan {
			++$this->consumes;
			return null;
		}
	}

	/**
	 * Job runner answering status from a fixed map of job id => [ user id, status ].
	 */
	class Remote_MCP_Endpoint_Test_Job_Runner implements Job_Runner {

		public $jobs  = array();
		public $calls = array();

		public function enqueue( Remote_MCP_Context $context, Call_Plan $plan, ?int $approval_id ): ?int {
			$this->calls[] = 'enqueue';
			return null;
		}

		public function tick( int $job_id ): bool {
			return false;
		}

		public function cancel( int $job_id, string $reason ): bool {
			return false;
		}

		public function status( int $job_id, int $user_id ): ?array {
			$this->calls[] = array( $job_id, $user_id );
			if ( ! isset( $this->jobs[ $job_id ] ) || $this->jobs[ $job_id ][0] !== $user_id ) {
				return null;
			}
			return $this->jobs[ $job_id ][1];
		}
	}

	/**
	 * Control contracts with declared contracts by ability.
	 */
	class Remote_MCP_Endpoint_Test_Contracts implements Control_Contract {

		public $declared = array();

		public function contract_for( Remote_MCP_Context $context, string $ability ): ?array {
			return $this->declared[ $ability ] ?? null;
		}

		public function declared_contract( string $ability ): ?array {
			return $this->declared[ $ability ] ?? null;
		}

		public function refresh_native_token( Call_Plan $plan, int $index, int $user_id ): ?array {
			return null;
		}

		public static function contract( string $tier ): array {
			return array(
				'source'             => 'declared',
				'preview'            => array(
					'kind'          => 'plan',
					'ability'       => null,
					'arg_map'       => null,
					'controls'      => array(),
					'capture_token' => null,
				),
				'execute'            => array(
					'controls'           => array(),
					'native_token_field' => null,
				),
				'sensitive'          => array(),
				'deferred'           => false,
				'credential_bearing' => false,
				'gate_supplied'      => array(),
				'compare_fields'     => array(),
				'tier'               => $tier,
			);
		}
	}
}

/**
 * Endpoint fixtures: real tables and a clocked store, an enabled connector on an https root
 * install, connections, MCP requests and test abilities.
 */
trait Remote_MCP_Endpoint_Fixtures {

	use Remote_MCP_Store_Fixtures {
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
	}

	/**
	 * Known clients double.
	 *
	 * @var Remote_MCP_Endpoint_Test_Known_Clients
	 */
	protected $clients;

	/**
	 * Gate double.
	 *
	 * @var Remote_MCP_Endpoint_Test_Gate
	 */
	protected $gate;

	/**
	 * Job runner double.
	 *
	 * @var Remote_MCP_Endpoint_Test_Job_Runner
	 */
	protected $jobs;

	/**
	 * Control contract double.
	 *
	 * @var Remote_MCP_Endpoint_Test_Contracts
	 */
	protected $contracts;

	/**
	 * Abilities registered by the test, removed afterwards.
	 *
	 * @var string[]
	 */
	protected $test_abilities = array();

	/**
	 * Ability categories registered by the test.
	 *
	 * @var string[]
	 */
	protected $test_categories = array();

	/**
	 * Execution count per test ability.
	 *
	 * @var array
	 */
	public static $executions = array();

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	protected $saved_server;

	public function set_up() {
		$this->store_set_up();
		$this->saved_server = $_SERVER;
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		delete_option( Remote_MCP_Settings::OPTION_COUNTERS );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->clients          = new Remote_MCP_Endpoint_Test_Known_Clients();
		$this->gate             = new Remote_MCP_Endpoint_Test_Gate();
		$this->jobs             = new Remote_MCP_Endpoint_Test_Job_Runner();
		$this->contracts        = new Remote_MCP_Endpoint_Test_Contracts();
		self::$executions       = array();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		$registry = \WP_Abilities_Registry::get_instance();
		foreach ( $this->test_abilities as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}
		$categories = \WP_Ability_Categories_Registry::get_instance();
		foreach ( $this->test_categories as $slug ) {
			if ( $categories->is_registered( $slug ) ) {
				$categories->unregister( $slug );
			}
		}
		if ( is_array( $this->saved_server ) ) {
			$_SERVER = $this->saved_server;
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Services over the test store, with doubles for the slots outside the endpoint's own classes.
	 *
	 * @param array       $overrides Slot overrides.
	 * @param string|null $home      Home URL.
	 * @param mixed       $store     Store, the test store when null.
	 * @return Remote_MCP_Endpoint_Test_Services
	 */
	protected function services( array $overrides = array(), ?string $home = null, $store = null ) {
		$services = new Remote_MCP_Endpoint_Test_Services(
			$overrides + array(
				'known_clients'    => $this->clients,
				'approval_gate'    => $this->gate,
				'job_runner'       => $this->jobs,
				'control_contract' => $this->contracts,
			),
			new Remote_MCP_Urls( $home ?? 'https://dash.example.org' ),
			$store ?? $this->store
		);
		if ( null === $this->gate->services ) {
			$this->gate->services = $services;
		}
		return $services;
	}

	/**
	 * Endpoint over services.
	 *
	 * @param mixed $services Services, default ones when null.
	 * @return MCP_Endpoint
	 */
	protected function endpoint( $services = null ) {
		return new MCP_Endpoint( $services ?? $this->services() );
	}

	/**
	 * A connection through consent and code exchange; returns the exchange result.
	 *
	 * @param string $scope     Approved scope.
	 * @param string $policy    Approval policy.
	 * @param string $client_id Client id.
	 * @param int    $user_id   Owner.
	 * @return array
	 */
	protected function connection( string $scope = 'mainwp:read mainwp:write', string $policy = 'standard', string $client_id = self::CLIENT_ID, int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : $this->user_id;
		$params  = $this->request_params(
			$user_id,
			array(
				'client_id' => $client_id,
				'scope'     => 'mainwp:read mainwp:write offline_access',
			)
		);
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $user_id, true, $scope . ' offline_access', $policy ), 'consent claim' );
		$code   = $this->store->issue_code( $request['id'] );
		$result = $this->store->redeem_code( $code, $client_id, self::VERIFIER );
		$this->assertSame( OAuth_Store::OK, $result['outcome'], 'code exchange' );
		return $result;
	}

	/**
	 * Access token of a new connection.
	 *
	 * @param string $scope     Approved scope.
	 * @param string $policy    Approval policy.
	 * @param string $client_id Client id.
	 * @return string
	 */
	protected function token( string $scope = 'mainwp:read mainwp:write', string $policy = 'standard', string $client_id = self::CLIENT_ID ): string {
		return $this->connection( $scope, $policy, $client_id )['access_token'];
	}

	/**
	 * Connection token for the default user.
	 *
	 * @param string $scope Scope.
	 * @param int    $days  Lifetime in days.
	 * @return array Mint result.
	 */
	protected function connect_token( string $scope = 'mainwp:read', int $days = 90 ): array {
		$minted = $this->store->mint_connection_token( $this->user_id, $scope, 'standard', 'Test app', $this->clock->now + $days * DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'], 'mint' );
		return $minted;
	}

	/**
	 * Server variables for an MCP request.
	 *
	 * @param string|null $token   Bearer token, or null for none.
	 * @param array       $headers HTTP_* server keys.
	 * @return array
	 */
	protected function server( ?string $token, array $headers = array() ): array {
		$server = array(
			'REQUEST_METHOD' => 'POST',
			'HTTPS'          => 'on',
			'REMOTE_ADDR'    => '203.0.113.9',
			'CONTENT_TYPE'   => 'application/json',
		);
		if ( null !== $token ) {
			$server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
		return array_merge( $server, $headers );
	}

	/**
	 * Send one raw MCP request.
	 *
	 * @param string      $body     Raw body.
	 * @param string|null $token    Bearer token.
	 * @param array       $headers  HTTP_* server keys.
	 * @param mixed       $endpoint Endpoint.
	 * @param string      $query    Raw query string.
	 * @param string      $method   HTTP method.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	protected function raw( string $body, ?string $token, array $headers = array(), $endpoint = null, string $query = '', string $method = 'POST' ) {
		$server                   = $this->server( $token, $headers );
		$server['REQUEST_METHOD'] = $method;
		$request                  = new Remote_MCP_Request( 'route_mcp', $method, $query, $body, $server );
		return ( $endpoint ?? $this->endpoint() )->handle( $request );
	}

	/**
	 * A 2026-07-28 request with every required header and _meta field.
	 *
	 * @param string      $method   JSON-RPC method.
	 * @param array       $params   Params, without _meta.
	 * @param string|null $token    Bearer token.
	 * @param mixed       $endpoint Endpoint.
	 * @param array       $caps     Client capabilities.
	 * @param array       $headers  Header overrides.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	protected function modern( string $method, array $params, ?string $token, $endpoint = null, array $caps = array(), array $headers = array() ) {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => empty( $caps ) ? new \stdClass() : $caps,
		);
		$base            = array(
			'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
			'HTTP_MCP_METHOD'           => $method,
		);
		if ( 'tools/call' === $method && isset( $params['name'] ) ) {
			$base['HTTP_MCP_NAME'] = $params['name'];
		}
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 7,
			'method'  => $method,
			'params'  => $params,
		);
		return $this->raw( (string) wp_json_encode( $body ), $token, array_merge( $base, $headers ), $endpoint );
	}

	/**
	 * A 2025-11-25 request.
	 *
	 * @param string      $method   JSON-RPC method.
	 * @param array       $params   Params.
	 * @param string|null $token    Bearer token.
	 * @param mixed       $endpoint Endpoint.
	 * @param array       $headers  HTTP_* server keys; the version header is 2025-11-25 unless overridden.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	protected function legacy( string $method, array $params, ?string $token, $endpoint = null, array $headers = array( 'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25' ) ) {
		$body = array(
			'jsonrpc' => '2.0',
			'id'      => 'req-1',
			'method'  => $method,
			'params'  => empty( $params ) ? new \stdClass() : $params,
		);
		return $this->raw( (string) wp_json_encode( $body ), $token, $headers, $endpoint );
	}

	/**
	 * Decoded JSON body.
	 *
	 * @param \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response $response Response.
	 * @return array
	 */
	protected function json( $response ): array {
		$data = json_decode( $response->body(), true );
		$this->assertIsArray( $data, 'JSON body: ' . $response->body() );
		return $data;
	}

	/**
	 * Register a test ability category outside a core category.
	 *
	 * @param string $slug Category slug.
	 */
	protected function category( string $slug = 'remote-mcp-test' ): void {
		$registry = \WP_Ability_Categories_Registry::get_instance();
		if ( ! $registry->is_registered( $slug ) ) {
			$registry->register(
				$slug,
				array(
					'label'       => 'Remote MCP test',
					'description' => 'Abilities registered by the remote MCP tests.',
				)
			);
			$this->test_categories[] = $slug;
		}
	}

	/**
	 * Register a test ability. Its execute callback counts runs and returns its input.
	 *
	 * @param string $name        Ability name.
	 * @param array  $annotations Annotations.
	 * @param array  $args        Extra ability args (input_schema, permission_callback, category, execute_callback).
	 * @param bool   $in_rest     show_in_rest.
	 * @return \WP_Ability
	 */
	protected function ability( string $name, array $annotations = array(
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	), array $args = array(), bool $in_rest = true ) {
		$category = $args['category'] ?? 'remote-mcp-test';
		$this->category( $category );
		$ability = \WP_Abilities_Registry::get_instance()->register(
			$name,
			array_merge(
				array(
					'label'               => 'Test ' . $name,
					'description'         => 'Test ability ' . $name . '.',
					'category'            => $category,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'site_id' => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => function ( $input = null ) use ( $name ) {
						self::$executions[ $name ] = ( self::$executions[ $name ] ?? 0 ) + 1;
						return array(
							'ran'   => $name,
							'input' => $input,
							'user'  => get_current_user_id(),
						);
					},
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'annotations'  => $annotations,
						'show_in_rest' => $in_rest,
					),
				),
				array_diff_key( $args, array( 'category' => true ) )
			)
		);
		$this->assertInstanceOf( \WP_Ability::class, $ability, 'test ability registered: ' . $name );
		$this->test_abilities[] = $name;
		return $ability;
	}

	/**
	 * Context for the default user.
	 *
	 * @param array $overrides Constructor fields: scopes, policy, grant_id, user_id, client_id, client_kind, era, url, state.
	 * @return Remote_MCP_Context
	 */
	protected function context( array $overrides = array() ): Remote_MCP_Context {
		return new Remote_MCP_Context(
			$overrides['grant_id'] ?? 1,
			$overrides['user_id'] ?? $this->user_id,
			$overrides['client_id'] ?? self::CLIENT_ID,
			$overrides['client_kind'] ?? 'bundled',
			$overrides['scopes'] ?? array( 'mainwp:read', 'mainwp:write' ),
			$overrides['policy'] ?? 'standard',
			$overrides['policy_version'] ?? 1,
			$overrides['token_type'] ?? 'access',
			$overrides['era'] ?? 'modern',
			$overrides['url'] ?? false,
			$overrides['state'] ?? null
		);
	}

	/**
	 * Tool descriptor by name from a tools/list result.
	 *
	 * @param array  $tools Tools.
	 * @param string $name  Tool name.
	 * @return array|null
	 */
	protected function tool( array $tools, string $name ): ?array {
		foreach ( $tools as $tool ) {
			if ( ( $tool['name'] ?? null ) === $name ) {
				return $tool;
			}
		}
		return null;
	}
}
