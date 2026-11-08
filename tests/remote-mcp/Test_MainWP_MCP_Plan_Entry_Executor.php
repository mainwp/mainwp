<?php
/**
 * Remote MCP trusted plan-entry executor tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Module\Log\Log_Author;
use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Plan_Entry_Executor
 */
class Test_MainWP_MCP_Plan_Entry_Executor extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * What the probe ability saw on each run.
	 *
	 * @var array
	 */
	public static $seen = array();

	/**
	 * Register the probe ability: it records its input, the current user and the deferred context.
	 *
	 * @param callable|null $permission Permission callback.
	 * @param mixed         $returns    Value to return, the record when null.
	 */
	private function probe( ?callable $permission = null, $returns = null ): void {
		self::$seen = array();
		$this->ability(
			'mainwp/remote-test-probe-v1',
			array(
				'readonly'    => false,
				'destructive' => true,
			),
			array(
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'site_ids_or_domains' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'confirm'             => array( 'type' => 'boolean' ),
						'dry_run'             => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'permission_callback' => $permission ?? function () {
					return current_user_can( 'manage_options' );
				},
				'execute_callback'    => function ( $input ) use ( $returns ) {
					$record       = array(
						'input'    => $input,
						'user'     => get_current_user_id(),
						'deferred' => apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null ),
						'agent'    => ( new Log_Author( get_current_user_id() ) )->get_current_agent(),
					);
					self::$seen[] = $record;
					return null === $returns ? $record : $returns;
				},
			)
		);
	}

	/**
	 * One-entry plan for the probe.
	 *
	 * @param array $args Frozen arguments.
	 * @return Call_Plan
	 */
	private function plan( array $args = array(
		'site_ids_or_domains' => array( 3, 4 ),
		'dry_run'             => false,
		'confirm'             => true,
	) ): Call_Plan {
		$plan = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/remote-test-probe-v1',
					'args'       => $args,
					'target_ids' => array( 3, 4 ),
				),
			)
		);
		$this->assertInstanceOf( Call_Plan::class, $plan );
		return $plan;
	}

	public function test_plan_entry_runs_as_grant_user_with_frozen_args_and_permission_callback() {
		$this->probe();
		$executor   = $this->services()->get( 'tool_executor' );
		$connection = $this->connection();
		$other      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$run = $executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $connection['grant_id'] );
		$this->assertTrue( $run['ok'] );
		$this->assertFalse( $run['queued'] );
		$this->assertNull( $run['reason'] );
		$this->assertSame( $this->user_id, self::$seen[0]['user'], 'runs as the grant user' );
		$this->assertSame(
			array(
				'confirm'             => true,
				'dry_run'             => false,
				'site_ids_or_domains' => array( 3, 4 ),
			),
			self::$seen[0]['input'],
			'frozen arguments, server controls included'
		);
		$this->assertSame( $other, get_current_user_id(), 'the caller keeps its user' );

		// The ability's own permission callback still runs.
		\WP_Abilities_Registry::get_instance()->unregister( 'mainwp/remote-test-probe-v1' );
		$this->probe( '__return_false' );
		$denied = $executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $connection['grant_id'] );
		$this->assertFalse( $denied['ok'] );
		$this->assertSame( array(), self::$seen );
		$this->assertInstanceOf( \WP_Error::class, $denied['result'] );

		\WP_Abilities_Registry::get_instance()->unregister( 'mainwp/remote-test-probe-v1' );
		$this->probe( null, array( 'queued' => true ) );
		$queued = $executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $connection['grant_id'] );
		$this->assertTrue( $queued['queued'], 'a core batch job answer is reported' );

		// A pending plan, an entry that doesn't exist and a grant that can't be read run nothing.
		self::$seen = array();
		$pending    = Call_Plan::pending(
			array(
				'ability'    => 'mainwp/remote-test-probe-v1',
				'args'       => array( 'site_ids_or_domains' => array( 3 ) ),
				'target_ids' => array( 3 ),
			)
		);
		foreach ( array(
			'pending' => array( $pending, 0, $connection['grant_id'] ),
			'index'   => array( $this->plan(), 1, $connection['grant_id'] ),
			'grant'   => array( $this->plan(), 0, 987654 ),
		) as $case => list( $plan, $index, $grant ) ) {
			$result = $executor->execute_plan_entry( $plan, $index, $this->user_id, $grant );
			$this->assertFalse( $result['ok'], $case );
			$this->assertIsString( $result['reason'], $case );
		}
		$this->assertSame( array(), self::$seen );
	}

	public function test_activity_written_during_execution_is_marked_as_mainwp_mcp() {
		$this->probe();
		$connection = $this->connection();
		$author     = new Log_Author( $this->user_id );
		$this->assertSame( '', $author->get_current_agent(), 'no agent before' );

		$result = $this->services()->get( 'tool_executor' )->execute_plan_entry( $this->plan(), 0, $this->user_id, $connection['grant_id'] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'mainwp_mcp', self::$seen[0]['agent'] );
		$this->assertSame( '', $author->get_current_agent(), 'no agent after' );
	}

	public function test_deferred_context_filter_carries_grant_and_policy_version_only_during_execution() {
		$this->probe();
		$executor = $this->services()->get( 'tool_executor' );
		$first    = $this->connection();
		$second   = $this->connection();
		$this->assertTrue( $this->store->set_approval_policy( $second['grant_id'], 'strict' ) );
		$this->assertTrue( $this->store->set_approval_policy( $second['grant_id'], 'standard' ) );
		$version = (int) $this->grant( $second['grant_id'] )['policy_version'];
		$this->assertSame( 3, $version );

		$this->assertNull( apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null ), 'null before' );
		$executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $first['grant_id'] );
		$executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $second['grant_id'] );
		$this->assertSame(
			array(
				'grant_id'       => $first['grant_id'],
				'policy_version' => 1,
			),
			self::$seen[0]['deferred']
		);
		$this->assertSame(
			array(
				'grant_id'       => $second['grant_id'],
				'policy_version' => 3,
			),
			self::$seen[1]['deferred'],
			'the grant passed in, not one derived from the user'
		);
		$this->assertNull( apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null ), 'null after' );

		// From inside a cron callback, where nobody is logged in and there is no bearer.
		add_action(
			'remote_mcp_test_cron',
			function () use ( $executor, $second ) {
				$executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $second['grant_id'] );
			}
		);
		wp_set_current_user( 0 );
		do_action( 'remote_mcp_test_cron' );
		$this->assertSame( $second['grant_id'], self::$seen[2]['deferred']['grant_id'] );
		$this->assertSame( 0, get_current_user_id() );

		// Removed even when the ability run throws.
		$throw = function () {
			throw new \RuntimeException( 'boom' );
		};
		add_action( 'wp_before_execute_ability', $throw );
		$thrown = $executor->execute_plan_entry( $this->plan(), 0, $this->user_id, $first['grant_id'] );
		remove_action( 'wp_before_execute_ability', $throw );
		$this->assertFalse( $thrown['ok'] );
		$this->assertNull( apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null ), 'null after a throw' );
		$this->assertSame( 0, get_current_user_id() );

		// A grant of another user is refused.
		$stranger = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$count    = count( self::$seen );
		$mismatch = $executor->execute_plan_entry( $this->plan(), 0, $stranger, $first['grant_id'] );
		$this->assertFalse( $mismatch['ok'] );
		$this->assertCount( $count, self::$seen, 'nothing ran' );
	}
}
