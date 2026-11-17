<?php
/**
 * Remote MCP target resolver and execution adapter tests.
 *
 * The doubles and fixtures at the top are shared with Test_MainWP_MCP_Job_Runner, which requires
 * this file.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Updates;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Control_Contract;
use MainWP\Dashboard\Remote_MCP\MCP_Execution_Adapter;
use MainWP\Dashboard\Remote_MCP\MCP_Job_Runner;
use MainWP\Dashboard\Remote_MCP\MCP_Target_Resolver;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Scope_Policy;
use MainWP\Dashboard\Remote_MCP\Target_Resolver;
use MainWP\Dashboard\Remote_MCP\Tier_Registry;
use MainWP\Dashboard\Remote_MCP\Tool_Executor;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( interface_exists( Tier_Registry::class ) && ! class_exists( Call_Plan_Test_Tier_Registry::class, false ) ) {

	/**
	 * Tier registry double: entries by ability.
	 */
	class Call_Plan_Test_Tier_Registry implements Tier_Registry {

		/**
		 * Entries by ability.
		 *
		 * @var array
		 */
		public $entries = array();

		/**
		 * Entry.
		 *
		 * @param string $ability Ability.
		 * @return array|null
		 */
		public function entry( string $ability ): ?array {
			return $this->entries[ $ability ] ?? null;
		}
	}

	/**
	 * Scope policy double: the registry tier, the way Standard maps it.
	 */
	class Call_Plan_Test_Scope_Policy implements Scope_Policy {

		/**
		 * Registry.
		 *
		 * @var Call_Plan_Test_Tier_Registry
		 */
		public $registry;

		/**
		 * Calls seen.
		 *
		 * @var array
		 */
		public $calls = array();

		/**
		 * Constructor.
		 *
		 * @param Call_Plan_Test_Tier_Registry $registry Registry.
		 */
		public function __construct( Call_Plan_Test_Tier_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Effective scopes.
		 *
		 * @param string $token_scope Token scope.
		 * @param string $grant_scope Grant scope.
		 * @return array
		 */
		public function effective_scopes( string $token_scope, string $grant_scope ): array {
			return OAuth_Store::scope_tokens( OAuth_Store::intersect_scopes( $token_scope, $grant_scope ) );
		}

		/**
		 * Effective tier.
		 *
		 * @param Remote_MCP_Context $context   Context.
		 * @param string             $ability   Ability.
		 * @param array              $arguments Arguments.
		 * @return string|null
		 */
		public function effective_tier( Remote_MCP_Context $context, string $ability, array $arguments ): ?string {
			$this->calls[] = array( $ability, $arguments );
			$entry         = $this->registry->entry( $ability );
			return $entry ? $entry['tier'] : null;
		}
	}

	/**
	 * Tool executor double that records every frozen entry it is asked to run.
	 */
	class Call_Plan_Test_Executor implements Tool_Executor {

		/**
		 * Calls: `entry`, `index`, `user_id`, `grant_id`, `plan`.
		 *
		 * @var array
		 */
		public $calls = array();

		/**
		 * Queued answers; the default answer is a success.
		 *
		 * @var array
		 */
		public $answers = array();

		/**
		 * Runs the entry for real when set: a callable taking the entry and returning its result.
		 *
		 * @var callable|null
		 */
		public $run;

		/**
		 * Tools.
		 *
		 * @param Remote_MCP_Context $context Context.
		 * @return array
		 */
		public function list_tools( Remote_MCP_Context $context ): array {
			return array();
		}

		/**
		 * Catalog.
		 *
		 * @return array
		 */
		public function catalog(): array {
			return array();
		}

		/**
		 * Never used by these tests.
		 *
		 * @param Remote_MCP_Context $context   Context.
		 * @param string             $name      Name.
		 * @param array              $arguments Arguments.
		 * @return array
		 */
		public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array {
			return array( 'isError' => true );
		}

		/**
		 * Record and answer.
		 *
		 * @param Call_Plan $plan     Plan.
		 * @param int       $index    Index.
		 * @param int       $user_id  User.
		 * @param int       $grant_id Grant.
		 * @return array
		 */
		public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
			$this->calls[] = array(
				'entry'    => $plan->entries()[ $index ] ?? null,
				'index'    => $index,
				'user_id'  => $user_id,
				'grant_id' => $grant_id,
				'plan'     => $plan,
			);
			if ( $this->answers ) {
				return array_shift( $this->answers );
			}
			if ( $this->run ) {
				$result = call_user_func( $this->run, $plan->entries()[ $index ] );
				return array(
					'ok'     => ! is_wp_error( $result ),
					'reason' => is_wp_error( $result ) ? $result->get_error_code() : null,
					'result' => $result,
					'queued' => false,
				);
			}
			return array(
				'ok'     => true,
				'reason' => null,
				'result' => array( 'done' => true ),
				'queued' => false,
			);
		}
	}

	/**
	 * Control contract double for the native token refresh.
	 */
	class Call_Plan_Test_Control_Contract implements Control_Contract {

		/**
		 * Refresh answer: a callable taking ( $plan, $index, $user_id ), or null.
		 *
		 * @var callable|null
		 */
		public $refresh;

		/**
		 * Refresh calls.
		 *
		 * @var int
		 */
		public $refresh_calls = 0;

		/**
		 * Contract.
		 *
		 * @param Remote_MCP_Context $context Context.
		 * @param string             $ability Ability.
		 * @return array|null
		 */
		public function contract_for( Remote_MCP_Context $context, string $ability ): ?array {
			return null;
		}

		/**
		 * Declared contract.
		 *
		 * @param string $ability Ability.
		 * @return array|null
		 */
		public function declared_contract( string $ability ): ?array {
			return null;
		}

		/**
		 * Refresh.
		 *
		 * @param Call_Plan $plan    Plan.
		 * @param int       $index   Index.
		 * @param int       $user_id User.
		 * @return array|null
		 */
		public function refresh_native_token( Call_Plan $plan, int $index, int $user_id ): ?array {
			++$this->refresh_calls;
			return $this->refresh ? call_user_func( $this->refresh, $plan, $index, $user_id ) : null;
		}
	}

	/**
	 * Target resolver spy around the real resolver.
	 */
	class Call_Plan_Test_Resolver_Spy implements Target_Resolver {

		/**
		 * Wrapped resolver.
		 *
		 * @var Target_Resolver
		 */
		public $inner;

		/**
		 * Calls.
		 *
		 * @var int
		 */
		public $calls = 0;

		/**
		 * Resolve.
		 *
		 * @param Remote_MCP_Context $context   Context.
		 * @param string             $ability   Ability.
		 * @param array              $arguments Arguments.
		 * @return array|null
		 */
		public function resolve( Remote_MCP_Context $context, string $ability, array $arguments ): ?array {
			++$this->calls;
			return $this->inner->resolve( $context, $ability, $arguments );
		}
	}

	/**
	 * Services whose store is the test's store.
	 */
	class Call_Plan_Test_Services extends Remote_MCP_Services {

		/**
		 * Store.
		 *
		 * @var OAuth_Store|null
		 */
		public $test_store;

		/**
		 * The test store.
		 *
		 * @return OAuth_Store|null
		 */
		public function store(): ?OAuth_Store {
			return $this->test_store;
		}

		/**
		 * Slots filled after construction, for doubles that need these services themselves.
		 *
		 * @var array
		 */
		public $late = array();

		/**
		 * A late slot, else the registry's.
		 *
		 * @param string $slot Slot.
		 * @return object
		 */
		public function get( string $slot ): object {
			return $this->late[ $slot ] ?? parent::get( $slot );
		}
	}
}

if ( class_exists( Remote_MCP_Test_Store::class, false ) && ! class_exists( Call_Plan_Test_Store::class, false ) ) {

	/**
	 * Test store with a seam between the job insert and the consume.
	 */
	class Call_Plan_Test_Store extends Remote_MCP_Test_Store {

		/**
		 * Callback run once after the next consume-and-enqueue insert.
		 *
		 * @var callable|null
		 */
		public $on_job_insert;

		/**
		 * Seam.
		 *
		 * @param int $job_id Job id.
		 */
		protected function after_job_insert( int $job_id ): void {
			if ( $this->on_job_insert ) {
				$callback            = $this->on_job_insert;
				$this->on_job_insert = null;
				$callback( $job_id );
			}
		}
	}
}

if ( class_exists( MCP_Execution_Adapter::class ) && ! class_exists( Call_Plan_Test_Adapter::class, false ) ) {

	/**
	 * Adapter whose core preview call is answered by the test.
	 */
	class Call_Plan_Test_Adapter extends MCP_Execution_Adapter {

		/**
		 * Arguments of every preview call, in order.
		 *
		 * @var array
		 */
		public $preview_calls = array();

		/**
		 * Users the preview ran as.
		 *
		 * @var array
		 */
		public $preview_users = array();

		/**
		 * Answer: callable taking the chunk arguments.
		 *
		 * @var callable|null
		 */
		public $answer;

		/**
		 * Answer one chunk.
		 *
		 * @param int   $user_id   User.
		 * @param array $arguments Chunk arguments.
		 * @return array|null
		 */
		protected function run_preview( int $user_id, array $arguments ): ?array {
			$this->preview_calls[] = $arguments;
			$this->preview_users[] = $user_id;
			return $this->answer ? call_user_func( $this->answer, $arguments ) : null;
		}
	}
}

if ( class_exists( MCP_Job_Runner::class ) && ! class_exists( Call_Plan_Test_Runner::class, false ) ) {

	/**
	 * Runner with the activity-log predicate under test control (the test bootstrap defines the
	 * logs module as off).
	 */
	class Call_Plan_Test_Runner extends MCP_Job_Runner {

		/**
		 * Whether the activity log reads as on.
		 *
		 * @var bool
		 */
		public $log_on = true;

		/**
		 * Called once at the activity-log check, the last check before the entry claim.
		 *
		 * @var callable|null
		 */
		public $on_log_check;

		/**
		 * Activity log.
		 *
		 * @return bool
		 */
		protected function activity_log_available(): bool {
			if ( $this->on_log_check ) {
				$callback           = $this->on_log_check;
				$this->on_log_check = null;
				$callback();
			}
			return $this->log_on;
		}
	}
}

/**
 * Shared fixtures: a write grant, real sites and clients, and the doubles.
 */
trait Call_Plan_Fixtures {

	use Remote_MCP_Store_Fixtures {
		set_up as store_set_up;
	}

	/**
	 * Registry double.
	 *
	 * @var Call_Plan_Test_Tier_Registry
	 */
	protected $registry;

	/**
	 * Policy double.
	 *
	 * @var Call_Plan_Test_Scope_Policy
	 */
	protected $policy;

	/**
	 * Executor double.
	 *
	 * @var Call_Plan_Test_Executor
	 */
	protected $executor;

	/**
	 * Contract double.
	 *
	 * @var Call_Plan_Test_Control_Contract
	 */
	protected $contracts;

	/**
	 * Resolver spy over the real resolver.
	 *
	 * @var Call_Plan_Test_Resolver_Spy
	 */
	protected $resolver;

	/**
	 * Adapter under test.
	 *
	 * @var Call_Plan_Test_Adapter
	 */
	protected $adapter;

	/**
	 * Runner under test.
	 *
	 * @var Call_Plan_Test_Runner
	 */
	protected $runner;

	/**
	 * Services.
	 *
	 * @var Call_Plan_Test_Services
	 */
	protected $services;

	/**
	 * Fixture setup.
	 */
	public function set_up() {
		$this->store_set_up();
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		wp_set_current_user( $this->user_id );

		$this->registry = new Call_Plan_Test_Tier_Registry();
		foreach ( array(
			'mainwp/run-updates-v1'         => array( 'confirm', 'sites', 'updates' ),
			'mainwp/update-all-v1'          => array( 'confirm', 'sites', 'updates' ),
			'mainwp/update-site-plugins-v1' => array( 'confirm', 'site', 'updates' ),
			'mainwp/sync-sites-v1'          => array( 'write', 'sites', 'chunked_sites' ),
			'mainwp/suspend-sites-v1'       => array( 'confirm', 'sites', 'chunked_sites' ),
			'mainwp/delete-client-v1'       => array( 'dashboard', 'client', 'atomic' ),
			'mainwp/delete-tag-v1'          => array( 'confirm', 'tag', 'atomic' ),
			'mainwp/get-site-v1'            => array( 'read', 'site', 'direct' ),
		) as $ability => $row ) {
			$this->registry->entries[ $ability ] = $this->registry_entry( $row[0], $row[1], $row[2] );
		}
		$this->policy               = new Call_Plan_Test_Scope_Policy( $this->registry );
		$this->executor             = new Call_Plan_Test_Executor();
		$this->contracts            = new Call_Plan_Test_Control_Contract();
		$this->resolver             = new Call_Plan_Test_Resolver_Spy();
		$this->services             = new Call_Plan_Test_Services(
			array(
				'tier_registry'    => $this->registry,
				'scope_policy'     => $this->policy,
				'tool_executor'    => $this->executor,
				'control_contract' => $this->contracts,
				'target_resolver'  => $this->resolver,
			)
		);
		$this->services->test_store = $this->store;
		$this->resolver->inner      = new MCP_Target_Resolver( $this->services );
		$this->adapter              = new Call_Plan_Test_Adapter( $this->services );
		// Reachable by the runner too, so a tick that re-read updates would show in preview_calls.
		$this->services->late['execution_adapter'] = $this->adapter;
		$this->runner                              = new Call_Plan_Test_Runner( $this->services );
	}

	/**
	 * Every store this test builds can race the consume-and-enqueue insert.
	 *
	 * @param string $resource Canonical resource.
	 * @return Remote_MCP_Test_Store
	 */
	protected function new_store( string $resource = self::RESOURCE ): Remote_MCP_Test_Store {
		return new Call_Plan_Test_Store( $this->hasher, $resource, $this->clock );
	}

	/**
	 * A tier registry entry.
	 *
	 * @param string      $tier        Tier.
	 * @param string      $resolver    Resolver type.
	 * @param string      $adapter     Adapter type.
	 * @param string|null $token_field Native token field, for a token-bound ability.
	 * @return array
	 */
	protected function registry_entry( string $tier, string $resolver, string $adapter, ?string $token_field = null ): array {
		return array(
			'source'             => 'core',
			'tier'               => $tier,
			'conditional'        => null,
			'contract'           => array(
				'source'             => 'audit',
				'preview'            => array(
					'kind'          => 'dry_run',
					'ability'       => null,
					'arg_map'       => null,
					'controls'      => array( 'dry_run' => true ),
					'capture_token' => $token_field,
				),
				'execute'            => array(
					'controls'           => array( 'confirm' => true ),
					'native_token_field' => $token_field,
				),
				'sensitive'          => array(),
				'deferred'           => false,
				'credential_bearing' => false,
				'gate_supplied'      => array(),
				'compare_fields'     => $token_field ? array( 'connection' ) : array(),
				'tier'               => $tier,
			),
			'resolver'           => $resolver,
			'adapter'            => $adapter,
			'sensitive'          => array(),
			'deferred'           => false,
			'credential_bearing' => false,
			'gate_supplied'      => array(),
			'compare_fields'     => array(),
			'min_version'        => null,
			'inert'              => false,
			'reason'             => 'test',
		);
	}

	/**
	 * A live grant with read and write scope.
	 *
	 * @param string $policy  Approval policy.
	 * @param int    $user_id Owner.
	 * @return array Grant row.
	 */
	protected function write_grant( string $policy = 'standard', int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : $this->user_id;
		$scope   = 'mainwp:read mainwp:write offline_access';
		$request = $this->store->create_request( $this->request_params( $user_id, array( 'scope' => $scope ) ) );
		$this->assertTrue( $this->store->claim_request( $request['id'], $user_id, true, $scope, $policy ), 'consent' );
		$result = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$this->assertSame( OAuth_Store::OK, $result['outcome'], 'connect' );
		return $this->grant( $result['grant_id'] );
	}

	/**
	 * Context of a grant row, the way a bearer check builds it.
	 *
	 * @param array $grant Grant row.
	 * @return Remote_MCP_Context
	 */
	protected function context( array $grant ): Remote_MCP_Context {
		return new Remote_MCP_Context( (int) $grant['id'], (int) $grant['user_id'], (string) $grant['client_id'], (string) $grant['client_kind'], OAuth_Store::scope_tokens( (string) $grant['scope'] ), (string) $grant['approval_policy'], (int) $grant['policy_version'], 'access' );
	}

	/**
	 * Real Dashboard sites.
	 *
	 * @param int $count     How many.
	 * @param int $client_id Client they belong to.
	 * @return int[] Site ids, ascending.
	 */
	protected function sites( int $count, int $client_id = 0 ): array {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_wp',
				array(
					'userid'               => $this->user_id,
					'url'                  => 'https://call-plan-' . wp_generate_uuid4() . '.example.com/',
					'name'                 => 'Call plan site',
					'adminname'            => 'admin',
					'pubkey'               => 'test-pubkey',
					'privkey'              => 'test-privkey',
					'offline_check_result' => 1,
					'client_id'            => $client_id,
					'is_staging'           => 0,
					'plugin_upgrades'      => '',
					'theme_upgrades'       => '',
					'translation_upgrades' => '',
					'premium_upgrades'     => '',
				)
			);
			$ids[] = (int) $wpdb->insert_id;
			// The site lookup joins the sync row.
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_wp_sync',
				array(
					'wpid'        => end( $ids ),
					'version'     => '5.0.0',
					'sync_errors' => '',
				)
			);
		}
		return $ids;
	}

	/**
	 * A real client.
	 *
	 * @return int Client id.
	 */
	protected function client(): int {
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Call plan client ' . wp_generate_uuid4(),
				'client_email' => 'call-plan-' . wp_generate_uuid4() . '@example.com',
				'created'      => time(),
			),
			true
		);
		return (int) $client->client_id;
	}

	/**
	 * One site's preview record.
	 *
	 * @param int   $site_id Site id.
	 * @param array $items   Items as [ type, slug, to ].
	 * @param array $skipped Skipped items as [ type, slug ].
	 * @return array
	 */
	protected function record( int $site_id, array $items, array $skipped = array() ): array {
		$record = array(
			'site_id'   => $site_id,
			'site_url'  => 'https://s' . $site_id . '.example.org/',
			'site_name' => 's' . $site_id . '.example.org',
			'items'     => array(),
			'skipped'   => array(),
		);
		foreach ( $items as $item ) {
			$record['items'][] = array(
				'type'      => $item[0],
				'slug'      => $item[1],
				'name'      => ucfirst( $item[1] ),
				'from'      => '1.0',
				'to'        => $item[2],
				'requested' => false,
				'flags'     => array(
					'core'  => 'core' === $item[0],
					'major' => false,
				),
			);
		}
		foreach ( $skipped as $item ) {
			$record['skipped'][] = array(
				'type'      => $item[0],
				'slug'      => $item[1],
				'name'      => ucfirst( $item[1] ),
				'from'      => '1.0',
				'to'        => '2.0',
				'requested' => false,
				'reason'    => 'ignored_site',
			);
		}
		return $record;
	}

	/**
	 * A preview envelope over records, with core's summary counts.
	 *
	 * @param array $records Records.
	 * @param array $errors  Errors.
	 * @return array
	 */
	protected function envelope( array $records, array $errors = array() ): array {
		$items   = 0;
		$skipped = 0;
		$sites   = 0;
		foreach ( $records as $record ) {
			$items   += count( $record['items'] );
			$skipped += count( $record['skipped'] );
			$sites   += $record['items'] ? 1 : 0;
		}
		return array(
			'dry_run'      => true,
			'would_affect' => array(
				'sites'   => $records,
				'by_item' => array(),
				'summary' => array(
					'site_count'          => $sites,
					'item_count'          => $items,
					'skipped_count'       => $skipped,
					'all_sites'           => false,
					'queued'              => false,
					'truncated'           => false,
					'has_core'            => false,
					'has_major'           => false,
					'has_unknown_version' => false,
					'requested_not_found' => array(),
				),
			),
			'count'        => $items,
			'warnings'     => array(),
			'errors'       => $errors,
		);
	}

	/**
	 * Targets for explicit site ids.
	 *
	 * @param int[] $site_ids Site ids.
	 * @return array
	 */
	protected function targets( array $site_ids ): array {
		sort( $site_ids );
		return array(
			'site_ids' => $site_ids,
			'entity'   => null,
		);
	}

	/**
	 * Run every tick of a job until none runs.
	 *
	 * @param int $job_id Job id.
	 * @return int Ticks that ran an entry.
	 */
	protected function drain( int $job_id ): int {
		$ran = 0;
		while ( $ran < 50 && $this->runner->tick( $job_id ) ) {
			++$ran;
		}
		return $ran;
	}

	/**
	 * A job enqueued from a `confirm` approval row.
	 *
	 * @param array     $grant   Grant row.
	 * @param Call_Plan $plan    Plan.
	 * @param string    $ability Ability the approval is for.
	 * @return int Job id.
	 */
	protected function approved_job( array $grant, Call_Plan $plan, string $ability ): int {
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => $ability,
				'tier'        => 'confirm',
				'args_digest' => str_repeat( 'a', 64 ),
				'call_plan'   => $plan,
			)
		);
		$this->assertSame( OAuth_Store::OK, $approval['outcome'], 'approval row' );
		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] );
		$this->assertIsInt( $job_id, 'job enqueued' );
		return $job_id;
	}
}

/**
 * Class Test_MainWP_MCP_Call_Plan
 */
class Test_MainWP_MCP_Call_Plan extends \WP_UnitTestCase {

	use Call_Plan_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_updates_preview_without_run_updates_ability_returns_null_without_notice() {
		// Another plugin can unregister the core ability; WordPress only allows registering during
		// wp_abilities_api_init, so the test puts the same object back through the registry property.
		$removed = wp_has_ability( 'mainwp/run-updates-v1' ) ? wp_unregister_ability( 'mainwp/run-updates-v1' ) : null;
		$adapter = new MCP_Execution_Adapter( $this->services );
		$preview = new \ReflectionMethod( $adapter, 'run_preview' );
		$preview->setAccessible( true );
		$notices = array();
		$capture = function ( $function, $message ) use ( &$notices ) { $notices[] = array( $function, $message ); };
		add_action( 'doing_it_wrong_run', $capture, 10, 2 );
		try {
			$this->assertFalse( wp_has_ability( 'mainwp/run-updates-v1' ) );
			$this->assertNull( $preview->invoke( $adapter, $this->user_id, array( 'dry_run' => true ) ) );
			$this->assertSame( array(), $notices );
		} finally {
			remove_action( 'doing_it_wrong_run', $capture, 10 );
			if ( $removed ) {
				$registry  = \WP_Abilities_Registry::get_instance();
				$abilities = new \ReflectionProperty( $registry, 'registered_abilities' );
				$abilities->setAccessible( true );
				$abilities->setValue( $registry, array_merge( $abilities->getValue( $registry ), array( 'mainwp/run-updates-v1' => $removed ) ) );
			}
		}
	}

	public function test_update_all_executes_through_grouped_run_updates_entries() {
		$grant             = $this->write_grant();
		$context           = $this->context( $grant );
		list( $a, $b, $c ) = $this->sites( 3 );
		$preview           = $this->envelope(
			array(
				$this->record( $a, array( array( 'plugin', 'akismet', '5.3' ) ) ),
				$this->record( $b, array( array( 'plugin', 'akismet', '5.3' ), array( 'theme', 'astra', '4.1' ) ) ),
				$this->record( $c, array( array( 'core', 'wordpress', '6.9' ), array( 'plugin', 'akismet', '5.3' ) ) ),
			)
		);
		$args              = array( 'site_ids_or_domains' => array( $a, $b, $c ) );
		$plan              = $this->adapter->build( $context, 'mainwp/update-all-v1', $args, $this->targets( array( $a, $b, $c ) ), $preview );

		$this->assertInstanceOf( Call_Plan::class, $plan );
		$expected = array(
			array( array( 'core' ), array( 'wordpress' ), array( $c ) ),
			array( array( 'plugins' ), array( 'akismet' ), array( $a, $b, $c ) ),
			array( array( 'themes' ), array( 'astra' ), array( $b ) ),
		);
		$seen     = array();
		foreach ( $plan->entries() as $entry ) {
			$this->assertSame( 'mainwp/run-updates-v1', $entry['ability'], 'update-all is never called directly' );
			$seen[] = array( $entry['args']['types'], $entry['args']['specific_items'], $entry['args']['site_ids_or_domains'] );
			$this->assertSame( $entry['args']['site_ids_or_domains'], $entry['target_ids'] );
		}
		$this->assertSame( $expected, $seen, 'one entry per type and slug group, in core order' );

		// Run as a job: every executed entry is a grouped run-updates entry from the plan.
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/update-all-v1' );
		$this->assertSame( 3, $this->drain( $job_id ) );
		$this->assertCount( 3, $this->executor->calls );
		foreach ( $this->executor->calls as $i => $call ) {
			$this->assertSame( 'mainwp/run-updates-v1', $call['entry']['ability'] );
			$this->assertSame( $plan->entries()[ $i ], $call['entry'] );
		}
		$this->assertSame( 'done', $this->store->get_job( $job_id )['status'] );
	}

	public function test_update_entries_are_per_type_with_nonempty_selectors_and_schema_valid() {
		$context               = $this->context( $this->write_grant() );
		list( $a, $b, $c, $d ) = array( 11, 12, 13, 14 );
		$preview               = $this->envelope(
			array(
				$this->record( $a, array( array( 'plugin', 'akismet', '5.3' ), array( 'theme', 'astra', '4.1' ), array( 'translation', 'akismet', '5.3' ) ) ),
				$this->record( $b, array( array( 'core', 'wordpress', '6.9' ) ) ),
				// Everything this site has is held back, so it gets no entry of any type.
				$this->record( $c, array(), array( array( 'plugin', 'jetpack' ) ) ),
				$this->record( $d, array( array( 'plugin', 'akismet', '5.3' ) ), array( array( 'theme', 'astra' ) ) ),
			)
		);
		$plan = $this->adapter->build( $context, 'mainwp/run-updates-v1', array( 'site_ids_or_domains' => array() ), $this->targets( array( $a, $b, $c, $d ) ), $preview );
		$this->assertInstanceOf( Call_Plan::class, $plan );

		$schema = MainWP_Abilities_Updates::get_run_updates_input_schema();
		$types  = array();
		foreach ( $plan->entries() as $entry ) {
			$args = $entry['args'];
			$keys = array_keys( $args );
			sort( $keys );
			$this->assertSame( array( 'confirm', 'site_ids_or_domains', 'specific_items', 'types' ), $keys, 'exactly the four selector and control keys' );
			$this->assertTrue( $args['confirm'] );
			$this->assertCount( 1, $args['types'], 'exactly one type per entry' );
			$this->assertNotEmpty( $args['site_ids_or_domains'], 'explicit, non-empty site selector' );
			$this->assertNotEmpty( $args['specific_items'], 'non-empty item selector' );
			$this->assertNotContains( $c, $args['site_ids_or_domains'], 'a site with only held updates gets nothing' );
			$this->assertTrue( rest_validate_value_from_schema( $args, $schema, 'args' ), 'schema-valid arguments' );
			$types[] = $args['types'][0];
		}
		$this->assertSame( array( 'core', 'plugins', 'themes', 'translations' ), $types );

		// No site gets an entry it has no approved items for: an all-skipped preview builds nothing.
		$held = $this->envelope( array( $this->record( $c, array(), array( array( 'plugin', 'jetpack' ) ) ) ) );
		$this->assertNull( $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( array( $c ) ), $held ) );
	}

	public function test_same_slug_different_type_not_executed() {
		$context = $this->context( $this->write_grant() );
		$preview = $this->envelope(
			array(
				$this->record( 21, array( array( 'plugin', 'shared-slug', '2.0' ) ) ),
				$this->record( 22, array( array( 'theme', 'shared-slug', '3.0' ) ) ),
			)
		);
		$plan    = $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( array( 21, 22 ) ), $preview );
		$this->assertInstanceOf( Call_Plan::class, $plan );

		$by_type = array();
		foreach ( $plan->entries() as $entry ) {
			$by_type[ $entry['args']['types'][0] ][] = $entry['args']['site_ids_or_domains'];
		}
		$this->assertSame(
			array(
				'plugins' => array( array( 21 ) ),
				'themes'  => array( array( 22 ) ),
			),
			$by_type,
			'the theme sharing a plugin slug never rides along with the plugin entry'
		);
	}

	public function test_merged_preview_warnings_drop_the_backup_gate_override_hint() {
		$context               = $this->context( $this->write_grant() );
		$this->adapter->answer = function ( array $args ) {
			$envelope             = $this->envelope( array( $this->record( $args['site_ids_or_domains'][0], array( array( 'plugin', 'akismet', '5.3' ) ) ) ), array() );
			$envelope['warnings'] = array(
				'1 site has no backup that meets the pre-update backup setting and will be skipped. Pass ignore_backup_gate: true to update it anyway.',
				'The plan includes a major version change.',
			);
			return $envelope;
		};
		$merged                = $this->adapter->collect_preview(
			$context,
			'mainwp/run-updates-v1',
			array(
				'dry_run'        => true,
				'types'          => array( 'plugins' ),
				'specific_items' => array(),
			),
			array( 1 )
		);
		$this->assertSame(
			array( '1 site has no backup that meets the pre-update backup setting and will be skipped.', 'The plan includes a major version change.' ),
			$merged['warnings']
		);
	}

	public function test_preview_over_threshold_collected_in_chunks_never_from_aggregates() {
		add_filter(
			'mainwp_abilities_batch_threshold',
			function () {
				return 12;
			}
		);
		$this->assertSame( 10, Remote_MCP_Settings::chunk_size() );
		$context               = $this->context( $this->write_grant() );
		$targets               = range( 1, 25 );
		$this->adapter->answer = function ( array $args ) {
			$records = array();
			$errors  = array();
			foreach ( $args['site_ids_or_domains'] as $id ) {
				if ( 7 === $id ) {
					continue; // Nothing pending: core writes no record.
				}
				if ( 8 === $id ) {
					$errors[] = array(
						'site_id'   => 8,
						'site_url'  => 'https://s8.example.org/',
						'site_name' => 's8.example.org',
						'type'      => 'site',
						'slug'      => '',
						'code'      => 'mainwp_site_offline',
						'message'   => 'Site is known to be offline.',
					);
					continue;
				}
				$items = array( array( 'plugin', 'akismet', '5.3' ) );
				if ( 3 === $id ) {
					$items[] = array( 'theme', 'astra', '4.1' );
				}
				$records[] = $this->record( $id, $items );
			}
			$envelope             = $this->envelope( $records, $errors );
			$envelope['warnings'] = array( 'The plan includes a major version change.' );
			return $envelope;
		};
		$args                  = array(
			'dry_run'        => true,
			'types'          => array( 'plugins', 'themes' ),
			'specific_items' => array(),
		);

		$merged = $this->adapter->collect_preview( $context, 'mainwp/run-updates-v1', $args, $targets );

		$this->assertCount( 3, $this->adapter->preview_calls, 'three chunks of at most 10 sites' );
		$chunks = array();
		foreach ( $this->adapter->preview_calls as $call ) {
			$chunks[] = $call['site_ids_or_domains'];
			unset( $call['site_ids_or_domains'] );
			$this->assertSame( $args, $call, 'only the chunk site selector is added' );
		}
		$this->assertSame( array( range( 1, 10 ), range( 11, 20 ), range( 21, 25 ) ), $chunks );
		$this->assertSame( array( $this->user_id, $this->user_id, $this->user_id ), $this->adapter->preview_users, 'runs as the context user' );

		$this->assertIsArray( $merged );
		$this->assertTrue( $merged['dry_run'] );
		$site_ids = array_column( $merged['would_affect']['sites'], 'site_id' );
		$this->assertSame( array_values( array_diff( $targets, array( 7, 8 ) ) ), $site_ids, 'every per-site record, in chunk order, never cut' );
		$summary = $merged['would_affect']['summary'];
		$this->assertSame( 23, $summary['site_count'] );
		$this->assertSame( 24, $summary['item_count'] );
		$this->assertSame( 24, $merged['count'] );
		$this->assertFalse( $summary['truncated'] );
		$this->assertFalse( $summary['queued'] );
		$this->assertFalse( $summary['all_sites'] );
		$this->assertSame(
			array(
				array( 'plugin', 'akismet', 23 ),
				array( 'theme', 'astra', 1 ),
			),
			array_map(
				function ( $row ) {
					return array( $row['type'], $row['slug'], $row['site_count'] );
				},
				$merged['would_affect']['by_item']
			),
			'by_item recomputed from the merged records'
		);
		$this->assertSame( array( 'The plan includes a major version change.' ), $merged['warnings'], 'each warning once' );
		$this->assertSame( array( 8 ), array_column( $merged['errors'], 'site_id' ) );

		// A chunk whose per-site records are cut while its aggregates cover every site is refused.
		$this->adapter->answer = function ( array $args ) {
			$records = array();
			foreach ( $args['site_ids_or_domains'] as $id ) {
				$records[] = $this->record( $id, array( array( 'plugin', 'akismet', '5.3' ) ) );
			}
			$envelope = $this->envelope( $records );
			array_pop( $envelope['would_affect']['sites'] );
			$envelope['would_affect']['summary']['truncated'] = true;
			return $envelope;
		};
		$this->assertNull( $this->adapter->collect_preview( $context, 'mainwp/run-updates-v1', $args, $targets ) );
		$this->assertNull( $this->adapter->collect_preview( $context, 'mainwp/run-updates-v1', $args, null ), 'no targets, no preview' );

		// build() never fetches: a null preview, or one whose records miss a target's updates, is null.
		$calls = count( $this->adapter->preview_calls );
		$this->assertNull( $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( $targets ), null ) );
		$short = $this->envelope( array( $this->record( 1, array( array( 'plugin', 'akismet', '5.3' ) ) ), $this->record( 2, array( array( 'plugin', 'akismet', '5.3' ) ) ) ) );
		$short['would_affect']['summary']['item_count'] = 25;
		$short['would_affect']['summary']['site_count'] = 25;
		$this->assertNull( $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( $targets ), $short ), 'aggregates never stand in for missing per-site records' );
		$this->assertSame( $calls, count( $this->adapter->preview_calls ), 'build() ran no preview' );
		$this->assertSame( array(), $this->executor->calls, 'no ability ran' );
	}

	public function test_site_never_receives_slug_not_approved_for_it() {
		$context = $this->context( $this->write_grant() );
		$preview = $this->envelope(
			array(
				$this->record( 31, array( array( 'plugin', 'alpha', '2.0' ), array( 'plugin', 'beta', '2.0' ) ), array( array( 'plugin', 'gamma' ) ) ),
				$this->record( 32, array( array( 'plugin', 'alpha', '2.0' ) ) ),
				$this->record( 33, array( array( 'plugin', 'beta', '2.0' ), array( 'plugin', 'alpha', '2.0' ) ) ),
			)
		);
		$plan    = $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( array( 31, 32, 33 ) ), $preview );
		$this->assertInstanceOf( Call_Plan::class, $plan );

		$approved = array(
			31 => array( 'alpha', 'beta' ),
			32 => array( 'alpha' ),
			33 => array( 'alpha', 'beta' ),
		);
		$received = array();
		foreach ( $plan->entries() as $entry ) {
			foreach ( $entry['args']['site_ids_or_domains'] as $site_id ) {
				foreach ( $entry['args']['specific_items'] as $slug ) {
					$this->assertContains( $slug, $approved[ $site_id ], "site $site_id never receives $slug" );
					$received[ $site_id ][] = $slug;
				}
			}
		}
		ksort( $received );
		$this->assertSame( $approved, $received, 'each site receives exactly its approved slugs; the held gamma never' );

		// A record for a site outside the resolved targets builds nothing.
		$wider = $this->envelope( array( $this->record( 31, array( array( 'plugin', 'alpha', '2.0' ) ) ), $this->record( 99, array( array( 'plugin', 'alpha', '2.0' ) ) ) ) );
		$this->assertNull( $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( array( 31 ) ), $wider ) );
		// An item outside the call's own selection builds nothing.
		$other = $this->envelope( array( $this->record( 31, array( array( 'theme', 'astra', '2.0' ) ) ) ) );
		$this->assertNull( $this->adapter->build( $context, 'mainwp/update-site-plugins-v1', array( 'site_id_or_domain' => 31 ), $this->targets( array( 31 ) ), $other ) );
	}

	public function test_atomic_entity_action_is_single_entry_never_chunked() {
		$context   = $this->context( $this->write_grant() );
		$client_id = $this->client();
		$site_ids  = $this->sites( 25, $client_id );
		$args      = array( 'client_id_or_email' => $client_id );

		$targets = $this->resolver->resolve( $context, 'mainwp/delete-client-v1', $args );
		$this->assertSame(
			array(
				'site_ids' => $site_ids,
				'entity'   => array(
					'type' => 'client',
					'id'   => $client_id,
				),
			),
			$targets,
			'the entity and every site it affects'
		);
		$plan = $this->adapter->build( $context, 'mainwp/delete-client-v1', $args, $targets, null );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$this->assertSame( 1, $plan->count(), 'one entry, never chunked' );
		$entry = $plan->entries()[0];
		$this->assertSame( 'mainwp/delete-client-v1', $entry['ability'] );
		$this->assertSame( $site_ids, $entry['target_ids'] );
		$this->assertSame( $args, $entry['args'] );

		// The same 25 sites as a chunkable site action do split.
		$chunked = $this->adapter->build( $context, 'mainwp/suspend-sites-v1', array( 'site_ids_or_domains' => $site_ids ), $this->targets( $site_ids ), null );
		$this->assertSame( 3, $chunked->count() );
	}

	public function test_new_update_after_approval_is_not_run() {
		$grant         = $this->write_grant();
		$context       = $this->context( $grant );
		list( $a, $b ) = $this->sites( 2 );
		$approved      = $this->envelope(
			array(
				$this->record( $a, array( array( 'plugin', 'akismet', '5.3' ) ) ),
				$this->record( $b, array( array( 'plugin', 'akismet', '5.3' ) ) ),
			)
		);
		$plan          = $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( array( $a, $b ) ), $approved );
		$job_id        = $this->approved_job( $grant, $plan, 'mainwp/run-updates-v1' );

		// A new plugin and a new theme update show up after the approval.
		$this->adapter->answer = function () use ( $a, $b ) {
			return $this->envelope(
				array(
					$this->record( $a, array( array( 'plugin', 'akismet', '5.3' ), array( 'plugin', 'jetpack', '14.0' ) ) ),
					$this->record( $b, array( array( 'plugin', 'akismet', '5.3' ), array( 'theme', 'astra', '4.1' ) ) ),
				)
			);
		};
		$this->drain( $job_id );

		$this->assertCount( 1, $this->executor->calls );
		$ran = $this->executor->calls[0]['entry']['args'];
		$this->assertSame( array( 'plugins' ), $ran['types'] );
		$this->assertSame( array( 'akismet' ), $ran['specific_items'], 'only the approved slug runs' );
		$this->assertSame( array( $a, $b ), $ran['site_ids_or_domains'] );
		$this->assertSame( array(), $this->adapter->preview_calls, 'the job never re-reads updates' );
	}

	public static function entity_scope_cases() {
		return array( 'client' => array( 'client' ), 'tag' => array( 'tag' ) );
	}

	/** @dataProvider entity_scope_cases */
	public function test_entity_targets_refuse_any_site_outside_user_scope( $type ) {
		global $wpdb;
		$context = $this->context( $this->write_grant() );
		$client = $this->client();
		$ids = $this->sites( 2, $client );
		$wpdb->insert( $wpdb->prefix . 'mainwp_group', array( 'name' => 'Scoped tag' ) );
		$tag = (int) $wpdb->insert_id;
		foreach ( $ids as $id ) {
			$wpdb->insert( $wpdb->prefix . 'mainwp_wp_group', array( 'wpid' => $id, 'groupid' => $tag ) );
		}
		$scope = (object) array( 'ids' => array( $ids[0] ) );
		add_filter( 'mainwp_currentuserallowedaccesssites', function () use ( $scope ) { return $scope->ids; } );
		add_filter( 'mainwp_currentuserallowedaccessgroups', function () use ( $tag ) { return array( $tag ); } );
		$ability = 'mainwp/delete-' . $type . '-v1';
		$args = 'client' === $type ? array( 'client_id_or_email' => $client ) : array( 'tag_id' => $tag );
		$this->assertNull( $this->resolver->resolve( $context, $ability, $args ), $ability . ' must refuse partial coverage' );
		$scope->ids = $ids;
		$resolved = $this->resolver->resolve( $context, $ability, $args );
		$this->assertIsArray( $resolved, $ability );
		$this->assertSame( $ids, $resolved['site_ids'], $ability . ' keeps the entire roster' );
		remove_all_filters( 'mainwp_currentuserallowedaccesssites' );
		remove_all_filters( 'mainwp_currentuserallowedaccessgroups' );
		$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'client_id' => $client ) );
		$this->assertSame( array(), $this->resolver->resolve( $context, $ability, $args )['site_ids'], 'authorized empty entities remain allowed' );
	}

	public function test_sites_resolver_never_expands_an_empty_list_the_ability_refuses() {
		$this->registry->entries['mainwp/disconnect-sites-v1'] = $this->registry_entry( 'dashboard', 'sites', 'chunked_sites' );
		$context = $this->context( $this->write_grant() );
		$ids     = $this->sites( 2 );
		foreach ( array( 'mainwp/suspend-sites-v1', 'mainwp/disconnect-sites-v1' ) as $ability ) {
			$this->assertNull( $this->resolver->resolve( $context, $ability, array( 'site_ids_or_domains' => array() ) ), $ability . ': an empty list is not the fleet' );
			$this->assertNull( $this->resolver->resolve( $context, $ability, array() ), $ability . ': an omitted list is not the fleet' );
			$this->assertSame( array( $ids[0] ), $this->resolver->resolve( $context, $ability, array( 'site_ids_or_domains' => array( $ids[0] ) ) )['site_ids'], $ability . ': listed sites resolve' );
		}

		// An ability whose schema still allows an empty list keeps "empty means every site".
		$fleet = $this->resolver->resolve( $context, 'mainwp/sync-sites-v1', array( 'site_ids_or_domains' => array() ) );
		$this->assertIsArray( $fleet );
		$this->assertEmpty( array_diff( $ids, $fleet['site_ids'] ), 'sync-sites expands an empty list to every reachable site' );
	}

	public function test_entity_action_runs_on_approved_client_after_email_moves() {
		$this->registry->entries['mainwp/delete-client-v1']['tier'] = 'dashboard';
		$grant    = $this->write_grant();
		$context  = $this->context( $grant );
		$db       = MainWP_DB_Client::instance();
		$approved = $this->client();
		$other    = $this->client();
		$email    = $db->get_wp_client_by( 'client_id', $approved )->client_email;
		$args     = array( 'client_id_or_email' => $email );

		$plan = $this->adapter->build( $context, 'mainwp/delete-client-v1', $args, $this->resolver->resolve( $context, 'mainwp/delete-client-v1', $args ), null );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => 'mainwp/delete-client-v1',
				'tier'        => 'dashboard',
				'args_digest' => str_repeat( '9', 64 ),
				'call_plan'   => $plan,
			)
		);
		$this->assertTrue( $this->store->decide_approval( (int) $approval['approval_id'], $this->user_id, true ), 'approved on the page' );
		$job_id = $this->runner->enqueue( $context, $plan, (int) $approval['approval_id'] );
		$this->assertIsInt( $job_id );

		// Both clients hold no sites, so their site sets match; only the client id tells them apart.
		$db->update_client(
			array(
				'client_id'    => $approved,
				'client_email' => 'moved-' . wp_generate_uuid4() . '@example.com',
			)
		);
		$db->update_client(
			array(
				'client_id'    => $other,
				'client_email' => $email,
			)
		);
		$this->executor->run = function ( array $entry ) {
			return \MainWP\Dashboard\MainWP_Abilities_Clients::execute_delete_client( $entry['args'] + array( 'confirm' => true ) );
		};
		$this->drain( $job_id );

		$this->assertNotEmpty( $db->get_wp_client_by( 'client_id', $other ), 'the client now holding the email is never touched' );
		$this->assertEmpty( $db->get_wp_client_by( 'client_id', $approved ), 'the approved client is the one deleted' );
		$this->assertCount( 1, $this->executor->calls );
		$this->assertSame( $approved, $this->executor->calls[0]['entry']['args']['client_id_or_email'], 'the entry names the client by its resolved id' );
	}
}
