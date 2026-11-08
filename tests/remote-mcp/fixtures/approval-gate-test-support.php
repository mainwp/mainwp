<?php // phpcs:disable WordPress.Files.FileName -- test doubles live beside their test class, not in class-*.php files.
/**
 * Doubles and fixtures for the approval gate, control contract, page and audit tests.
 * Lives under fixtures/ so the suite never collects it as a test file.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Execution_Adapter;
use MainWP\Dashboard\Remote_MCP\Job_Runner;
use MainWP\Dashboard\Remote_MCP\Known_Clients;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Scope_Policy;
use MainWP\Dashboard\Remote_MCP\Target_Resolver;
use MainWP\Dashboard\Remote_MCP\Tier_Registry;
use MainWP\Dashboard\Remote_MCP\Tool_Executor;

require_once dirname( __DIR__ ) . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.CodeAnalysis.UnusedFunctionParameter

if ( interface_exists( Tier_Registry::class ) ) {

	/**
	 * The test bootstrap disables logs; keep its settings predicate with a test module constant.
	 */
	class Gate_Test_Log_Settings extends Remote_MCP_Settings {
		const LOG_MODULE_CONSTANT = 'MAINWP_GATE_TEST_LOG_MODULE';
	}

	/**
	 * Registry over a test table.
	 */
	class Gate_Test_Registry_Double implements Tier_Registry {
		public $entries = array();

		public function entry( string $ability ): ?array {
			return $this->entries[ $ability ] ?? null;
		}
	}

	/**
	 * Tier policy double: the entry's tier, its conditional callback, then the connection policy
	 * with the Hands-off exceptions. The gate's tier tests use it; the integration tests repeat
	 * them against the real policy.
	 */
	class Gate_Test_Scope_Policy_Double implements Scope_Policy {
		public $registry;
		public $calls        = array();
		public $conditionals = array();
		// A tier table can still answer for a refused entry; the gate must refuse anyway.
		public $tier_for_refused = null;

		public function __construct( Gate_Test_Registry_Double $registry ) {
			$this->registry = $registry;
		}

		public function effective_scopes( string $token_scope, string $grant_scope ): array {
			return OAuth_Store::intersect_scopes( $token_scope, $grant_scope );
		}

		public function effective_tier( Remote_MCP_Context $context, string $ability, array $arguments ): ?string {
			$this->calls[] = array(
				'policy'    => $context->approval_policy,
				'ability'   => $ability,
				'arguments' => $arguments,
			);
			$entry         = $this->registry->entry( $ability );
			if ( null === $entry ) {
				return null;
			}
			if ( 'refused' === $entry['source'] ) {
				return $this->tier_for_refused;
			}
			$tier = $entry['tier'];
			if ( null !== $entry['conditional'] && isset( $this->conditionals[ $entry['conditional'] ] ) ) {
				$tier = call_user_func( $this->conditionals[ $entry['conditional'] ], $arguments );
			}
			if ( null === $tier || 'read' === $tier ) {
				return $tier;
			}
			if ( 'strict' === $context->approval_policy && in_array( $tier, array( 'write', 'confirm' ), true ) ) {
				return 'dashboard';
			}
			if ( 'hands_off' === $context->approval_policy && 'dashboard' === $tier && ! $this->never_downgraded( $ability, $arguments ) ) {
				return 'confirm';
			}
			return $tier;
		}

		private function never_downgraded( string $ability, array $arguments ): bool {
			$base = substr( $ability, (int) strpos( $ability, '/' ) + 1 );
			if ( in_array( $base, array( 'add-site-v1', 'replace-custom-dashboard-snippet-v1' ), true ) ) {
				return true;
			}
			return 'update-site-v1' === $base && array() !== array_intersect( array( 'url', 'verify_certificate', 'ssl_version' ), array_keys( $arguments ) );
		}
	}

	/**
	 * Trusted executor double: runs the entry's ability as the user and records each call.
	 */
	class Gate_Test_Executor_Double implements Tool_Executor {
		public $calls          = array();
		public $queued         = false;
		public $deferred_store = null;

		public function list_tools( Remote_MCP_Context $context ): array {
			return array();
		}

		public function catalog(): array {
			return array();
		}

		public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
			return array( 'isError' => true );
		}

		public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
			$entries       = $plan->entries();
			$this->calls[] = array(
				'plan'     => $plan,
				'index'    => $index,
				'user_id'  => $user_id,
				'grant_id' => $grant_id,
				'entry'    => $entries[ $index ] ?? null,
			);
			if ( $plan->is_pending() || ! isset( $entries[ $index ] ) ) {
				return array(
					'ok'     => false,
					'reason' => 'refused',
					'result' => null,
					'queued' => false,
				);
			}
			$ability = wp_has_ability( $entries[ $index ]['ability'] ) ? wp_get_ability( $entries[ $index ]['ability'] ) : null;
			$input   = json_decode( (string) wp_json_encode( $plan->args_object( $index ) ), true );
			$before  = get_current_user_id();
			// Like the real executor, the deferred context uses the passed grant for this call only.
			$deferred = null;
			if ( null !== $this->deferred_store ) {
				$grant    = $this->deferred_store->get_grant( $grant_id );
				$deferred = function () use ( $grant ) {
					return array(
						'grant_id'       => (int) $grant['id'],
						'policy_version' => (int) $grant['policy_version'],
					);
				};
				add_filter( Remote_MCP_Events::DEFERRED_CONTEXT, $deferred );
			}
			wp_set_current_user( $user_id );
			try {
				$result = $ability ? $ability->execute( $input ) : new \WP_Error( 'missing', 'missing' );
			} finally {
				wp_set_current_user( $before );
				if ( null !== $deferred ) {
					remove_filter( Remote_MCP_Events::DEFERRED_CONTEXT, $deferred );
				}
			}
			return array(
				'ok'     => ! is_wp_error( $result ),
				'reason' => is_wp_error( $result ) ? $result->get_error_code() : null,
				'result' => $result,
				'queued' => $this->queued,
			);
		}
	}

	/**
	 * Resolver double: a callable per ability, or the `site_ids` argument as is.
	 */
	class Gate_Test_Resolver_Double implements Target_Resolver {
		public $map   = array();
		public $calls = array();

		public function resolve( Remote_MCP_Context $context, string $ability, array $arguments ): ?array {
			$this->calls[] = array( $ability, $arguments );
			if ( isset( $this->map[ $ability ] ) ) {
				return call_user_func( $this->map[ $ability ], $arguments );
			}
			$ids = $arguments['site_ids'] ?? null;
			return array(
				'site_ids' => is_array( $ids ) ? array_values( $ids ) : null,
				'entity'   => null,
			);
		}
	}

	/**
	 * Adapter double: one entry per call, or a callable per ability; collect_preview returns a
	 * set preview and counts its calls.
	 */
	class Gate_Test_Adapter_Double implements Execution_Adapter {
		public $builds   = array();
		public $previews = array();
		public $preview  = null;
		public $map      = array();

		public function build( Remote_MCP_Context $context, string $ability, array $arguments, ?array $targets, ?array $preview ): ?Call_Plan {
			$this->builds[] = compact( 'ability', 'arguments', 'targets', 'preview' );
			if ( isset( $this->map[ $ability ] ) ) {
				return call_user_func( $this->map[ $ability ], $arguments, $targets, $preview );
			}
			return Call_Plan::from_entries(
				array(
					array(
						'ability'    => $ability,
						'args'       => empty( $arguments ) ? new \stdClass() : $arguments,
						'target_ids' => empty( $targets['site_ids'] ) ? null : $targets['site_ids'],
					),
				)
			);
		}

		public function collect_preview( Remote_MCP_Context $context, string $ability, array $arguments, ?array $target_ids ): ?array {
			$this->previews[] = compact( 'ability', 'arguments', 'target_ids' );
			return is_callable( $this->preview ) ? call_user_func( $this->preview, $arguments, $target_ids ) : $this->preview;
		}
	}

	/**
	 * Job runner double that consumes through the store's atomic statement, like MCP_Job_Runner.
	 */
	class Gate_Test_Job_Runner_Double implements Job_Runner {
		public $store;
		public $calls = array();

		public function enqueue( Remote_MCP_Context $context, Call_Plan $plan, ?int $approval_id ): ?int {
			$row           = null === $approval_id ? null : $this->store->get_approval( $approval_id );
			$this->calls[] = array(
				'plan'        => $plan,
				'approval_id' => $approval_id,
				'status'      => $row['status'] ?? null,
			);
			if ( null === $approval_id ) {
				$result = $this->store->enqueue_job( $context->grant_id, $context->policy_version, $plan );
			} else {
				$result = $this->store->consume_into_job( $approval_id, (string) ( $row['tier'] ?? '' ), $plan );
			}
			return OAuth_Store::OK === $result['outcome'] ? (int) $result['job_id'] : null;
		}

		public function tick( int $job_id ): bool {
			return false;
		}

		public function cancel( int $job_id, string $reason ): bool {
			return false;
		}

		public function status( int $job_id, int $user_id ): ?array {
			return null;
		}
	}

	/**
	 * Client registry double.
	 */
	class Gate_Test_Known_Clients_Double implements Known_Clients {
		public $clients = array();

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
	 * Registry whose store is the test store and whose slots a test can set after construction.
	 */
	class Gate_Test_Services extends Remote_MCP_Services {
		public $test_store;
		public $slots = array();

		public function store(): ?OAuth_Store {
			return $this->test_store;
		}

		public function get( string $slot ): object {
			return $this->slots[ $slot ] ?? parent::get( $slot );
		}
	}
}

/**
 * Connected grants, registered test abilities and the doubles above, wired into one registry.
 * The using class aliases Remote_MCP_Store_Fixtures::set_up() as store_set_up().
 */
trait Approval_Gate_Fixtures {

	protected $registry;
	protected $policy;
	protected $executor;
	protected $resolver;
	protected $adapter;
	protected $jobs;
	protected $clients;
	protected $services;
	protected $gate;
	protected $log;
	protected $audit     = array();
	protected $calls     = array();
	protected $abilities = array();

	public function set_up() {
		$this->store_set_up();
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$this->registry                            = new Gate_Test_Registry_Double();
		$this->policy                              = new Gate_Test_Scope_Policy_Double( $this->registry );
		$this->executor                            = new Gate_Test_Executor_Double();
		$this->resolver                            = new Gate_Test_Resolver_Double();
		$this->adapter                             = new Gate_Test_Adapter_Double();
		$this->jobs                                = new Gate_Test_Job_Runner_Double();
		$this->clients                             = new Gate_Test_Known_Clients_Double();
		$this->services                            = new Gate_Test_Services(
			array(
				'tier_registry'     => $this->registry,
				'scope_policy'      => $this->policy,
				'tool_executor'     => $this->executor,
				'target_resolver'   => $this->resolver,
				'execution_adapter' => $this->adapter,
				'job_runner'        => $this->jobs,
				'known_clients'     => $this->clients,
			)
		);
		$this->services->test_store                = $this->store;
		$this->jobs->store                         = $this->store;
		$this->log                                 = (object) array( 'on' => true );
		$this->gate                                = new \MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate(
			$this->services,
			function () {
				return $this->log->on;
			}
		);
		$this->services->slots['approval_gate']    = $this->gate;
		$this->clients->clients[ self::CLIENT_ID ] = array(
			'client_id'         => self::CLIENT_ID,
			'kind'              => 'bundled',
			'label'             => 'Claude',
			'approval_delivery' => 'result',
		);
		$this->audit                               = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) {
				$this->audit[] = array( $event, $fields );
			},
			10,
			2
		);
	}

	public function tear_down() {
		foreach ( $this->abilities as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$this->abilities = array();
		parent::tear_down();
	}

	/**
	 * A live grant and its context.
	 *
	 * @param array $o `policy`, `scope`, `user`, `client_id`, `kind`, `era`, `elicit`, `state`.
	 * @return Remote_MCP_Context
	 */
	protected function ctx( array $o = array() ): Remote_MCP_Context {
		$scope   = $o['scope'] ?? 'mainwp:read mainwp:write offline_access';
		$policy  = $o['policy'] ?? 'standard';
		$user    = $o['user'] ?? $this->user_id;
		$params  = $this->request_params( $user, array( 'scope' => $scope ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $user, true, $scope, $policy ), 'consent' );
		$grant = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$this->assertSame( OAuth_Store::OK, $grant['outcome'], 'grant' );
		$row = $this->store->get_grant( (int) $grant['grant_id'] );
		return new Remote_MCP_Context( (int) $row['id'], (int) $row['user_id'], $o['client_id'] ?? self::CLIENT_ID, $o['kind'] ?? 'bundled', OAuth_Store::scope_tokens( (string) $row['scope'] ), (string) $row['approval_policy'], (int) $row['policy_version'], 'access', $o['era'] ?? 'none', $o['elicit'] ?? false, $o['state'] ?? null );
	}

	/**
	 * The same grant with fresh row values and other request metadata.
	 *
	 * @param Remote_MCP_Context $ctx Context.
	 * @param array              $o   `era`, `elicit`, `state`, `client_id`, `kind`, `policy`.
	 * @return Remote_MCP_Context
	 */
	protected function again( Remote_MCP_Context $ctx, array $o = array() ): Remote_MCP_Context {
		$row = $this->store->get_grant( $ctx->grant_id );
		return new Remote_MCP_Context( $ctx->grant_id, $ctx->user_id, $o['client_id'] ?? $ctx->client_id, $o['kind'] ?? $ctx->client_kind, $ctx->scopes, $o['policy'] ?? (string) $row['approval_policy'], (int) $row['policy_version'], $ctx->token_type, $o['era'] ?? $ctx->protocol_era, $o['elicit'] ?? $ctx->client_supports_url_elicitation, array_key_exists( 'state', $o ) ? $o['state'] : $ctx->request_state );
	}

	/**
	 * Register a test ability whose calls are recorded in $this->calls.
	 *
	 * @param string $name  Ability name.
	 * @param array  $props Input schema properties.
	 * @param array  $o     `required`, `closed`, `annotations`, `result` (callable), `permission` (callable).
	 */
	protected function ability( string $name, array $props, array $o = array() ): void {
		if ( wp_has_ability( $name ) ) {
			wp_unregister_ability( $name );
		}
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $props ) ? new \stdClass() : $props,
		);
		if ( ! empty( $o['required'] ) ) {
			$schema['required'] = $o['required'];
		}
		if ( ! empty( $o['closed'] ) ) {
			$schema['additionalProperties'] = false;
		}
		$result     = $o['result'] ?? function ( $input ) {
			return array( 'done' => true );
		};
		$permission = $o['permission'] ?? function () {
			return current_user_can( 'manage_options' );
		};
		$registered = \WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Test ' . $name,
				'description'         => 'Test ability.',
				'category'            => 'mainwp-sites',
				'input_schema'        => $schema,
				'execute_callback'    => function ( $input = null ) use ( $name, $result ) {
					$this->calls[ $name ][] = array(
						'input' => $input,
						'user'  => get_current_user_id(),
					);
					return call_user_func( $result, $input );
				},
				'permission_callback' => $permission,
				'meta'                => array( 'annotations' => $o['annotations'] ?? array() ),
			)
		);
		$this->assertNotNull( $registered, 'test ability registered' );
		$this->abilities[] = $name;
	}

	/**
	 * A tier registry entry.
	 *
	 * @param string $ability Ability.
	 * @param string $tier    Tier.
	 * @param array  $o       Overrides.
	 */
	protected function entry( string $ability, string $tier, array $o = array() ): void {
		$this->registry->entries[ $ability ] = array_merge(
			array(
				'source'             => 'core',
				'tier'               => $tier,
				'conditional'        => null,
				'contract'           => null,
				'resolver'           => 'none',
				'adapter'            => 'direct',
				'sensitive'          => array(),
				'deferred'           => false,
				'credential_bearing' => false,
				'gate_supplied'      => array(),
				'compare_fields'     => array(),
				'min_version'        => null,
				'inert'              => false,
				'reason'             => '',
			),
			$o
		);
	}

	/**
	 * Calls recorded for one test ability.
	 *
	 * @param string $name Ability.
	 * @return array
	 */
	protected function ran( string $name ): array {
		return $this->calls[ $name ] ?? array();
	}

	/**
	 * check() then deliver(), as MCP_Tool_Executor::call_tool() does unless the gate refuses.
	 *
	 * @param Remote_MCP_Context $ctx     Context.
	 * @param string             $ability Ability.
	 * @param array              $args    Client arguments.
	 * @return array `decision` and `result` (null when refused).
	 */
	protected function call( Remote_MCP_Context $ctx, string $ability, array $args ): array {
		$decision = $this->gate->check( $ctx, $ability, $args );
		$result   = 'refused' === $decision['decision'] ? null : $this->gate->deliver( $ctx, $decision );
		return array(
			'decision' => $decision,
			'result'   => $result,
		);
	}

	/**
	 * Structured content of a CallToolResult.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	protected function structured( array $result ): array {
		return (array) json_decode( (string) wp_json_encode( $result['structuredContent'] ?? null ), true );
	}

	/**
	 * Audit events fired, by name.
	 *
	 * @param string $event Event.
	 * @return array Field sets.
	 */
	protected function audited( string $event ): array {
		$found = array();
		foreach ( $this->audit as list( $name, $fields ) ) {
			if ( $name === $event ) {
				$found[] = $fields;
			}
		}
		return $found;
	}

	/**
	 * Approval rows of a grant.
	 *
	 * @param int $grant_id Grant.
	 * @return array
	 */
	protected function approvals( int $grant_id ): array {
		return $this->rows( 'approvals', 'grant_id = ' . (int) $grant_id );
	}

	/**
	 * Approve a pending row the way the page does, as its user.
	 *
	 * @param int $approval_id Approval.
	 * @param int $user_id     User.
	 * @return array Outcome.
	 */
	protected function approve( int $approval_id, int $user_id = 0 ): array {
		return $this->gate->page_decide( $approval_id, $user_id ? $user_id : $this->user_id, 'approve' );
	}
}
