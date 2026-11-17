<?php
/**
 * Remote MCP tools/call hand-off tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\MCP_Tool_Executor;
use MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Tool_Executor
 */
class Test_MainWP_MCP_Tool_Executor extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_call_tool_delegates_run_to_gate_deliver_and_never_executes_itself() {
		$this->ability(
			'mainwp/remote-test-write-v1',
			array(
				'readonly'    => false,
				'destructive' => false,
			)
		);
		$executor   = new class( $this->services() ) extends MCP_Tool_Executor {
			public $runs = 0;
			public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array {
				++$this->runs;
				return parent::execute_plan_entry( $plan, $index, $user_id, $grant_id );
			}
		};
		$entry      = array(
			'ability'    => 'mainwp/remote-test-write-v1',
			'args'       => array( 'site_id' => 3 ),
			'target_ids' => array( 3 ),
		);
		$inline     = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'inline result',
				),
			),
			'isError' => false,
		);
		$job        = array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => 'Poll get_remote_job_status_v1.',
				),
			),
			'structuredContent' => array(
				'status'        => 'JOB_STARTED',
				'job_id'        => 5,
				'entries_total' => 2,
				'status_tool'   => 'get_remote_job_status_v1',
			),
			'isError'           => false,
		);
		$branches   = array(
			'inline' => array( Call_Plan::from_entries( array( $entry ) ), $inline ),
			'job'    => array( Call_Plan::from_entries( array( $entry, array_merge( $entry, array( 'target_ids' => array( 4 ) ) ) ) ), $job ),
		);
		$connection = $this->connection();
		$context    = $this->context( array( 'grant_id' => $connection['grant_id'] ) );
		foreach ( $branches as $branch => list( $plan, $delivered ) ) {
			$this->gate->decision       = array(
				'decision'    => 'run',
				'tier'        => 'write',
				'reason'      => null,
				'approval_id' => null,
				'plan'        => $plan,
			);
			$this->gate->deliver_result = $delivered;
			$result                     = $executor->call_tool( $context, 'remote_test_write_v1', array( 'site_id' => 3 ) );
			$this->assertSame( $delivered, $result, $branch . ': the gate result, unchanged' );
		}
		$this->assertCount( 2, $this->gate->checks );
		$this->assertCount( 2, $this->gate->delivers );
		$this->assertSame( 0, $executor->runs, 'the executor never runs a plan itself' );
		$this->assertSame( 0, $this->gate->consumes, 'and never consumes' );
		$this->assertSame( array(), $this->jobs->calls, 'or enqueues' );
		$this->assertSame( array(), self::$executions );

		// Every other decision goes to deliver() too; only `refused` is answered by the executor.
		foreach ( array( 'confirmation_required', 'approval_required', 'denied' ) as $decision ) {
			$this->gate->decision       = array(
				'decision'    => $decision,
				'tier'        => 'confirm',
				'reason'      => 'denied' === $decision ? 'user_denied' : null,
				'approval_id' => 9,
				'plan'        => null,
			);
			$this->gate->deliver_result = array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $decision,
					),
				),
				'isError' => false,
			);
			$this->assertSame( $decision, $executor->call_tool( $context, 'remote_test_write_v1', array() )['content'][0]['text'] );
		}
		$this->assertSame( 'user_denied', $this->grant( $connection['grant_id'] )['last_failure'] );
		$delivers                   = count( $this->gate->delivers );
		$this->gate->decision       = array(
			'decision'    => 'refused',
			'tier'        => null,
			'reason'      => 'activity_log_off',
			'approval_id' => null,
			'plan'        => null,
		);
		$this->gate->deliver_result = null;
		$refused                    = $executor->call_tool( $context, 'remote_test_write_v1', array() );
		$this->assertTrue( $refused['isError'] );
		$this->assertSame( MCP_Approval_Gate::refusal_text( 'activity_log_off' ), $refused['content'][0]['text'] );
		$this->assertCount( $delivers, $this->gate->delivers, 'refused never reaches deliver()' );
		$this->assertSame( 'activity_log_off', $this->grant( $connection['grant_id'] )['last_failure'] );
		$this->assertSame( 0, $executor->runs );

		// A retry after the page already ran the row is neither a failure nor a user's denial.
		$outcomes = array();
		add_action(
			\MainWP\Dashboard\Remote_MCP\Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$outcomes ) {
				if ( 'mcp_call' === $event ) {
					$outcomes[] = $fields['outcome'] ?? null;
				}
			},
			10,
			2
		);
		$this->gate->decision       = array(
			'decision'    => 'denied',
			'tier'        => 'dashboard',
			'reason'      => 'already_run',
			'approval_id' => 9,
			'plan'        => null,
		);
		$this->gate->deliver_result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'already ran',
				),
			),
			'isError' => false,
		);
		$executor->call_tool( $context, 'remote_test_write_v1', array() );
		$this->assertSame( 'activity_log_off', $this->grant( $connection['grant_id'] )['last_failure'], 'no failure recorded for work that ran' );
		$this->assertSame( array( 'already_run' ), $outcomes, 'audited as already run, not denied' );

		// A failed preview's own text reaches the client; the audit keeps the code.
		$this->gate->decision       = array(
			'decision'    => 'refused',
			'tier'        => 'confirm',
			'reason'      => 'preview_failed',
			'approval_id' => null,
			'plan'        => null,
			'gate'        => array( 'reason_text' => 'The preview for this action failed, so nothing was run. Update MainWP Child first.' ),
		);
		$refused                    = $executor->call_tool( $context, 'remote_test_write_v1', array() );
		$this->assertTrue( $refused['isError'] );
		$this->assertSame( 'The preview for this action failed, so nothing was run. Update MainWP Child first.', $refused['content'][0]['text'] );
	}
}
