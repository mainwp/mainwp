<?php
/**
 * Remote MCP module job tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\MCP_Job_Cron;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/Test_MainWP_MCP_Call_Plan.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Job_Runner
 */
class Test_MainWP_MCP_Job_Runner extends \WP_UnitTestCase {

	use Call_Plan_Fixtures {
		set_up as fixtures_set_up;
	}

	public function set_up() {
		$this->fixtures_set_up();
		\WP_Abilities_Registry::get_instance()->register(
			'acme/replace-connection-v1',
			array(
				'label' => 'Connection',
				'description' => 'Replace a connection.',
				'category' => 'mainwp-sites',
				'execute_callback' => '__return_true',
				'permission_callback' => '__return_true',
			)
		);
	}

	public function tear_down() {
		wp_unregister_ability( 'acme/replace-connection-v1' );
		parent::tear_down();
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * A chunkable site action over real sites, split by the adapter.
	 *
	 * @param array  $grant   Grant row.
	 * @param int    $count   Sites.
	 * @param string $ability Ability: write-tier sync, or confirm-tier suspend for approved jobs.
	 * @return Call_Plan
	 */
	private function sync_plan( array $grant, int $count, string $ability = 'mainwp/sync-sites-v1' ): Call_Plan {
		$site_ids = $this->sites( $count );
		$plan     = $this->adapter->build( $this->context( $grant ), $ability, array( 'site_ids_or_domains' => $site_ids ), $this->targets( $site_ids ), null );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		return $plan;
	}

	/**
	 * Job row.
	 *
	 * @param int $job_id Job id.
	 * @return array
	 */
	private function job( int $job_id ): array {
		$row = $this->store->get_job( $job_id );
		$this->assertIsArray( $row );
		return $row;
	}

	public function test_consume_and_enqueue_job_owns_plan_and_loser_job_deleted() {
		$grant    = $this->write_grant();
		$plan     = $this->sync_plan( $grant, 15, 'mainwp/suspend-sites-v1' );
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => 'mainwp/suspend-sites-v1',
				'tier'        => 'confirm',
				'args_digest' => str_repeat( 'b', 64 ),
				'call_plan'   => $plan,
			)
		);

		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] );
		$this->assertIsInt( $job_id );
		$job = $this->job( $job_id );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( (string) $approval['approval_id'], $job['approval_id'] );
		$this->assertTrue( $this->store->job_call_plan( $job )->equals( $plan ), 'the job holds its own copy of the plan' );
		$row = $this->store->get_approval( (int) $approval['approval_id'] );
		$this->assertSame( 'consumed', $row['status'] );
		$this->assertNull( $row['call_plan'], 'the approval copy is wiped once the job owns the plan' );
		$this->assertSame( array( array( 'mainwp/suspend-sites-v1', $plan->entries()[0]['args'] ) ), $this->policy->calls, 'the consume uses the tier of the approved ability now' );

		// A second enqueue of the same approval loses and leaves no job behind.
		$this->assertNull( $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] ) );
		$this->assertCount( 1, $this->rows( 'jobs', 'grant_id = ' . (int) $grant['id'] ) );

		// A consume that loses the race after the job insert deletes the starting job.
		$other                      = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => 'mainwp/suspend-sites-v1',
				'tier'        => 'confirm',
				'args_digest' => str_repeat( 'c', 64 ),
				'call_plan'   => $plan,
			)
		);
		$this->store->on_job_insert = function () use ( $other ) {
			$this->assertTrue( $this->store->consume_approval( (int) $other['approval_id'], 'confirm' ), 'the competing consume wins' );
		};
		$this->assertNull( $this->runner->enqueue( $this->context( $grant ), $plan, (int) $other['approval_id'] ) );
		$this->assertSame( array(), $this->rows( 'jobs', 'approval_id = ' . (int) $other['approval_id'] ), 'the loser job is deleted' );

		// An approval of another connection is never consumed through this one.
		$foreign  = $this->write_grant();
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => (int) $foreign['id'],
				'ability'     => 'mainwp/suspend-sites-v1',
				'tier'        => 'confirm',
				'args_digest' => str_repeat( 'd', 64 ),
				'call_plan'   => $plan,
			)
		);
		$this->assertNull( $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] ) );
		$this->assertSame( 'approved', $this->store->get_approval( (int) $approval['approval_id'] )['status'] );
	}

	public function test_starting_job_never_ticks() {
		$grant                      = $this->write_grant();
		$plan                       = $this->sync_plan( $grant, 12, 'mainwp/suspend-sites-v1' );
		$approval                   = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => 'mainwp/suspend-sites-v1',
				'tier'        => 'confirm',
				'args_digest' => str_repeat( 'e', 64 ),
				'call_plan'   => $plan,
			)
		);
		$ticked                     = null;
		$checks                     = null;
		$this->store->on_job_insert = function ( int $job_id ) use ( &$ticked, &$checks ) {
			$this->assertSame( 'starting', $this->store->get_job( $job_id )['status'] );
			$before = count( $this->policy->calls );
			$ticked = $this->runner->tick( $job_id );
			$checks = count( $this->policy->calls ) - $before;
		};

		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] );

		$this->assertFalse( $ticked, 'a starting job never ticks' );
		$this->assertSame( 0, $checks, 'it is not even checked' );
		$this->assertSame( array(), $this->executor->calls );
		$job = $this->job( $job_id );
		$this->assertSame( 'running', $job['status'], 'the tick changed nothing' );
		$this->assertSame( '0', $job['next_entry'] );
		$this->assertTrue( $this->runner->tick( $job_id ), 'once running it ticks' );
	}

	public function test_direct_write_tier_sync_over_chunk_size_becomes_module_job() {
		$grant    = $this->write_grant();
		$context  = $this->context( $grant );
		$site_ids = $this->sites( 25 );
		$args     = array( 'site_ids_or_domains' => $site_ids );
		$targets  = $this->resolver->resolve( $context, 'mainwp/sync-sites-v1', $args );
		$this->assertSame( $this->targets( $site_ids ), $targets );
		$plan = $this->adapter->build( $context, 'mainwp/sync-sites-v1', $args, $targets, null );

		$this->assertSame( 3, $plan->count(), 'over the chunk size it splits' );
		foreach ( $plan->entries() as $entry ) {
			$this->assertSame( 'mainwp/sync-sites-v1', $entry['ability'] );
			$this->assertLessThanOrEqual( Remote_MCP_Settings::chunk_size(), count( $entry['target_ids'] ) );
			$this->assertSame( $entry['target_ids'], $entry['args']['site_ids_or_domains'], 'each entry carries its explicit ids' );
		}
		$this->assertSame( $site_ids, $plan->target_ids() );

		$job_id = $this->runner->enqueue( $context, $plan, null );
		$this->assertIsInt( $job_id, 'a direct write plan becomes a job without an approval' );
		$job = $this->job( $job_id );
		$this->assertNull( $job['approval_id'] );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( (string) $grant['id'], $job['grant_id'] );
		$this->assertTrue( $this->store->job_call_plan( $job )->equals( $plan ) );
		$this->assertNotFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job_id ) ), 'its tick is scheduled' );

		// Without an approval only read and write work can be queued.
		$confirm = $this->adapter->build( $context, 'mainwp/suspend-sites-v1', $args, $targets, null );
		$this->assertNull( $this->runner->enqueue( $context, $confirm, null ), 'a confirm-tier plan never skips its approval' );
		$read_only = new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Context( (int) $grant['id'], (int) $grant['user_id'], (string) $grant['client_id'], 'bundled', array( 'mainwp:read' ), 'standard', (int) $grant['policy_version'], 'access' );
		$this->assertNull( $this->runner->enqueue( $read_only, $plan, null ), 'write work needs write scope' );
	}

	public function test_chunk_size_capped_below_core_batch_threshold() {
		add_filter(
			'mainwp_abilities_batch_threshold',
			function () {
				return 5;
			}
		);
		add_filter(
			'mainwp_remote_mcp_chunk_size',
			function () {
				return 50;
			}
		);
		$this->assertSame( 4, Remote_MCP_Settings::chunk_size() );
		$grant   = $this->write_grant();
		$context = $this->context( $grant );
		$ids     = range( 1, 9 );

		$plan = $this->adapter->build( $context, 'mainwp/sync-sites-v1', array( 'site_ids_or_domains' => $ids ), $this->targets( $ids ), null );
		$this->assertSame( array( 4, 4, 1 ), array_map( 'count', array_column( $plan->entries(), 'target_ids' ) ) );

		$this->adapter->answer = function ( array $args ) {
			$records = array();
			foreach ( $args['site_ids_or_domains'] as $id ) {
				$records[] = $this->record( $id, array( array( 'plugin', 'akismet', '5.3' ) ) );
			}
			return $this->envelope( $records );
		};
		$preview               = $this->adapter->collect_preview( $context, 'mainwp/run-updates-v1', array( 'dry_run' => true ), $ids );
		$this->assertSame( array( 4, 4, 1 ), array_map( 'count', array_column( $this->adapter->preview_calls, 'site_ids_or_domains' ) ), 'every preview chunk stays under the core threshold' );
		$updates = $this->adapter->build( $context, 'mainwp/run-updates-v1', array(), $this->targets( $ids ), $preview );
		$this->assertSame( array( 4, 4, 1 ), array_map( 'count', array_column( $updates->entries(), 'target_ids' ) ), 'and so does every update entry' );
	}

	public function test_core_queued_response_fails_the_job() {
		$grant                     = $this->write_grant();
		$plan                      = $this->sync_plan( $grant, 15 );
		$job_id                    = $this->runner->enqueue( $this->context( $grant ), $plan, null );
		$this->executor->answers[] = array(
			'ok'     => true,
			'reason' => null,
			'result' => array( 'queued' => true ),
			'queued' => true,
		);

		$this->assertTrue( $this->runner->tick( $job_id ) );
		$job = $this->job( $job_id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'core_queued', $job['last_error'] );
		$this->assertFalse( $this->runner->tick( $job_id ), 'a failed job runs nothing more' );
		$this->assertCount( 1, $this->executor->calls );
	}

	public function test_job_executes_only_approved_items() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 25, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );

		$this->assertSame( 3, $this->drain( $job_id ) );
		$this->assertSame(
			$plan->entries(),
			array_column( $this->executor->calls, 'entry' ),
			'exactly the frozen entries, in order, each once'
		);
		$this->assertSame( array( 0, 1, 2 ), array_column( $this->executor->calls, 'index' ) );
		$this->assertSame( 'done', $this->job( $job_id )['status'] );
		$this->assertFalse( $this->runner->tick( $job_id ) );
		$this->assertCount( 3, $this->executor->calls );

		// A site that disappeared after approval cancels the job instead of running a smaller set.
		$plan  = $this->sync_plan( $grant, 12, 'mainwp/suspend-sites-v1' );
		$other = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $plan->entries()[1]['target_ids'][0] ) );
		$this->assertTrue( $this->runner->tick( $other ) );
		$this->assertFalse( $this->runner->tick( $other ) );
		$job = $this->job( $other );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 'targets_changed', $job['last_error'] );
		$this->assertCount( 4, $this->executor->calls );
	}

	public function test_job_cancelled_on_revoke_narrowing_policy_change_and_kill_switch() {
		$jobs = array();
		foreach ( array( 'revoke', 'narrow', 'policy', 'kill' ) as $cause ) {
			$grant          = $this->write_grant();
			$jobs[ $cause ] = array( $grant, $this->runner->enqueue( $this->context( $grant ), $this->sync_plan( $grant, 15 ), null ) );
			$this->assertIsInt( $jobs[ $cause ][1] );
		}
		$this->store->revoke_grant( (int) $jobs['revoke'][0]['id'], 'user_revoked' );
		$this->assertTrue( $this->store->narrow_grant_scope( (int) $jobs['narrow'][0]['id'], 'mainwp:read offline_access' ) );
		$this->assertTrue( $this->store->set_approval_policy( (int) $jobs['policy'][0]['id'], 'strict' ) );

		foreach ( array( 'revoke', 'narrow', 'policy' ) as $cause ) {
			$this->assertFalse( $this->runner->tick( $jobs[ $cause ][1] ), $cause );
			$this->assertSame( 'cancelled', $this->job( $jobs[ $cause ][1] )['status'], $cause );
		}
		$this->assertTrue( $this->runner->tick( $jobs['kill'][1] ), 'the last job still runs before the switch' );

		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertFalse( $this->runner->tick( $jobs['kill'][1] ) );
		$job = $this->job( $jobs['kill'][1] );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 'kill_switch', $job['last_error'] );
		$this->assertFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $jobs['kill'][1] ) ), 'its tick is unscheduled' );
		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertFalse( $this->runner->tick( $jobs['kill'][1] ), 'lifting the switch resumes nothing' );
		$this->assertCount( 1, $this->executor->calls );
	}

	public function test_job_status_tool_shows_own_jobs_only() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 15 );
		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, null );
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertNull( $this->runner->status( $job_id, $other ), "another user's job reads as not found" );
		$this->assertNull( $this->runner->status( $job_id + 1000, $this->user_id ), 'an unknown job reads as not found' );
		$this->assertSame(
			array(
				'job_id'           => $job_id,
				'status'           => 'running',
				'entries_done'     => 0,
				'entries_total'    => 2,
				'current_site_ids' => $plan->entries()[0]['target_ids'],
				'cancel_reason'    => null,
			),
			$this->runner->status( $job_id, $this->user_id )
		);

		$this->runner->tick( $job_id );
		$this->assertSame( 1, $this->runner->status( $job_id, $this->user_id )['entries_done'] );
		$this->assertSame( $plan->entries()[1]['target_ids'], $this->runner->status( $job_id, $this->user_id )['current_site_ids'] );

		$this->assertTrue( $this->runner->cancel( $job_id, 'user_cancelled' ) );
		$status = $this->runner->status( $job_id, $this->user_id );
		$this->assertSame( 'cancelled', $status['status'] );
		$this->assertSame( 'user_cancelled', $status['cancel_reason'] );
		$this->assertSame( array(), $status['current_site_ids'] );
	}

	public function test_job_tick_executes_frozen_entry_without_new_approval() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 15, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		$before = count( $this->rows( 'approvals' ) );

		$this->assertTrue( $this->runner->tick( $job_id ) );

		$this->assertCount( 1, $this->executor->calls );
		$call = $this->executor->calls[0];
		$this->assertTrue( $call['plan']->equals( $plan ), 'the frozen plan itself, not a rebuilt one' );
		$this->assertSame( 0, $call['index'] );
		$this->assertSame( $plan->entries()[0], $call['entry'], 'frozen arguments, controls included' );
		$this->assertSame( (int) $grant['user_id'], $call['user_id'] );
		$this->assertSame( (int) $grant['id'], $call['grant_id'], "the job's grant" );
		$this->assertSame( $before, count( $this->rows( 'approvals' ) ), 'no new approval' );
		$this->assertSame( 'consumed', $this->rows( 'approvals' )[0]['status'], 'nothing is consumed again' );
		$this->assertSame( 0, $this->resolver->calls, 'no target expansion for explicit site ids' );
		$this->assertSame( '1', $this->job( $job_id )['next_entry'] );
		$this->assertNotFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job_id ) ), 'the next entry is scheduled' );
	}

	public function test_job_chunk_and_cancel_fire_audit_events() {
		$events = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$events ) {
				$events[] = array( $event, $fields );
			},
			10,
			2
		);
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 15 );
		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, null );

		$this->runner->tick( $job_id );
		$this->assertSame(
			array(
				array(
					'job_chunk',
					array(
						'user_id'  => (int) $grant['user_id'],
						'grant_id' => (int) $grant['id'],
						'ability'  => 'mainwp/sync-sites-v1',
						'outcome'  => 'ok',
						'job_id'   => $job_id,
						'site_ids' => $plan->entries()[0]['target_ids'],
					),
				),
			),
			$events
		);

		$events = array();
		$this->runner->cancel( $job_id, 'user_cancelled' );
		$this->assertSame(
			array(
				array(
					'job_cancelled',
					array(
						'user_id'  => (int) $grant['user_id'],
						'grant_id' => (int) $grant['id'],
						'job_id'   => $job_id,
						'reason'   => 'user_cancelled',
					),
				),
			),
			$events
		);
	}

	public function test_job_tick_cancels_when_activity_log_off() {
		$grant  = $this->write_grant();
		$job_id = $this->runner->enqueue( $this->context( $grant ), $this->sync_plan( $grant, 15 ), null );

		$this->runner->log_on = false;
		$this->assertFalse( $this->runner->tick( $job_id ) );
		$job = $this->job( $job_id );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 'audit_log_off', $job['last_error'] );
		$this->assertSame( array(), $this->executor->calls );

		// The read-only status still shows why.
		$this->assertSame( 'audit_log_off', $this->runner->status( $job_id, $this->user_id )['cancel_reason'] );
	}

	public function test_job_tick_refreshes_native_token_before_executing_token_bound_entry() {
		$this->registry->entries['acme/replace-connection-v1'] = $this->registry_entry( 'confirm', 'none', 'atomic', 'preview_token' );
		$grant    = $this->write_grant();
		$baseline = str_repeat( 'f', 64 );
		$plan     = Call_Plan::from_entries(
			array(
				array(
					'ability'          => 'acme/replace-connection-v1',
					'args'             => array(
						'connection'    => 'main',
						'preview_token' => 'expired-token',
						'confirm'       => true,
					),
					'target_ids'       => null,
					'preview_baseline' => $baseline,
				),
			)
		);
		$fresh    = function ( string $token, array $change = array() ) use ( $plan ) {
			$entry         = $plan->entries()[0];
			$entry['args'] = array_merge( $entry['args'], array( 'preview_token' => $token ), $change );
			return Call_Plan::from_entries( array( $entry ) );
		};

		// A renewal: the compare_fields digest still equals the baseline, so the entry runs with the fresh token.
		$renewed                  = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		$this->contracts->refresh = function ( Call_Plan $given, int $index, int $user_id ) use ( $plan, $fresh ) {
			$this->assertTrue( $given->equals( $plan ) );
			$this->assertSame( 0, $index );
			$this->assertSame( $this->user_id, $user_id );
			return array(
				'plan'  => $fresh( 'fresh-token' ),
				'stale' => false,
			);
		};
		$this->assertTrue( $this->runner->tick( $renewed ) );
		$this->assertCount( 1, $this->executor->calls );
		$this->assertSame( 'fresh-token', $this->executor->calls[0]['entry']['args']['preview_token'], 'the fresh token runs, never the stored one' );
		$this->assertSame( $baseline, $this->executor->calls[0]['entry']['preview_baseline'] );
		$this->assertSame( 'done', $this->job( $renewed )['status'] );

		// An effect change, a failed refresh, or a refresh that changes anything but the token cancels.
		foreach ( array(
			'stale'   => function () use ( $fresh ) {
				return array(
					'plan'  => $fresh( 'fresh-token' ),
					'stale' => true,
				);
			},
			'failed'  => function () {
				return null;
			},
			'widened' => function () use ( $fresh ) {
				return array(
					'plan'  => $fresh( 'fresh-token', array( 'connection' => 'other' ) ),
					'stale' => false,
				);
			},
		) as $label => $answer ) {
			$job_id                   = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
			$this->contracts->refresh = $answer;
			$this->assertFalse( $this->runner->tick( $job_id ), $label );
			$job = $this->job( $job_id );
			$this->assertSame( 'cancelled', $job['status'], $label );
			$this->assertSame( 'preview_changed', $job['last_error'], $label );
			$this->runner->cancel( $job_id, 'cleanup' );
		}
		$this->assertCount( 1, $this->executor->calls, 'nothing else ran' );
		$this->assertSame( 4, $this->contracts->refresh_calls );
	}

	public function test_job_tick_cancels_entity_action_when_affected_sites_change() {
		global $wpdb;
		$this->registry->entries['mainwp/delete-client-v1']['tier'] = 'confirm';
		$grant   = $this->write_grant();
		$context = $this->context( $grant );

		foreach ( array( 'joins', 'leaves' ) as $change ) {
			$client_id = $this->client();
			$site_ids  = $this->sites( 2, $client_id );
			$args      = array( 'client_id_or_email' => $client_id );
			$plan      = $this->adapter->build( $context, 'mainwp/delete-client-v1', $args, $this->resolver->resolve( $context, 'mainwp/delete-client-v1', $args ), null );
			$this->assertSame( $site_ids, $plan->entries()[0]['target_ids'] );
			$job_id = $this->approved_job( $grant, $plan, 'mainwp/delete-client-v1' );

			if ( 'joins' === $change ) {
				$this->sites( 1, $client_id );
			} else {
				$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'client_id' => 0 ), array( 'id' => $site_ids[1] ) );
			}
			$calls = $this->resolver->calls;
			$this->assertFalse( $this->runner->tick( $job_id ), $change );
			$this->assertSame( $calls + 1, $this->resolver->calls, 'the entity is re-resolved before the entry runs' );
			$job = $this->job( $job_id );
			$this->assertSame( 'cancelled', $job['status'], $change );
			$this->assertSame( 'targets_changed', $job['last_error'], $change );
		}
		$this->assertSame( array(), $this->executor->calls );

		// Unchanged, the entity entry runs.
		$client_id = $this->client();
		$this->sites( 2, $client_id );
		$args   = array( 'client_id_or_email' => $client_id );
		$plan   = $this->adapter->build( $context, 'mainwp/delete-client-v1', $args, $this->resolver->resolve( $context, 'mainwp/delete-client-v1', $args ), null );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/delete-client-v1' );
		$this->assertTrue( $this->runner->tick( $job_id ) );
		$this->assertCount( 1, $this->executor->calls );
	}

	public function test_schedule_failure_at_enqueue_cancels_the_job() {
		$grant    = $this->write_grant();
		$plan     = $this->sync_plan( $grant, 15, 'mainwp/suspend-sites-v1' );
		$approval = $this->store->create_approval(
			array(
				'grant_id'    => (int) $grant['id'],
				'ability'     => 'mainwp/suspend-sites-v1',
				'tier'        => 'confirm',
				'args_digest' => str_repeat( '1', 64 ),
				'call_plan'   => $plan,
			)
		);
		add_filter( 'pre_schedule_event', '__return_false' );
		$job_id = $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] );
		remove_filter( 'pre_schedule_event', '__return_false' );

		$this->assertNull( $job_id, 'no job id for a job nothing will tick' );
		$rows = $this->rows( 'jobs', 'approval_id = ' . (int) $approval['approval_id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'cancelled', $rows[0]['status'], 'never left running without a tick' );
		$this->assertSame( 'schedule_failed', $rows[0]['last_error'] );
		$this->assertSame( 'schedule_failed', $this->runner->status( (int) $rows[0]['id'], $this->user_id )['cancel_reason'] );
		$this->assertFalse( $this->runner->tick( (int) $rows[0]['id'] ) );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_schedule_failure_after_tick_fails_the_job() {
		$grant  = $this->write_grant();
		$job_id = $this->runner->enqueue( $this->context( $grant ), $this->sync_plan( $grant, 25 ), null );
		$this->assertIsInt( $job_id );
		// WP-Cron removes the event before it runs the hook, so the tick has to schedule the next one.
		MCP_Job_Cron::unschedule( $job_id );

		add_filter( 'pre_schedule_event', '__return_false' );
		$this->assertTrue( $this->runner->tick( $job_id ), 'the claimed entry ran' );
		remove_filter( 'pre_schedule_event', '__return_false' );

		$job = $this->job( $job_id );
		$this->assertSame( 'failed', $job['status'], 'never left running without a tick' );
		$this->assertSame( 'schedule_failed', $job['last_error'] );
		$this->assertSame( 'schedule_failed', $this->runner->status( $job_id, $this->user_id )['cancel_reason'] );
		$this->assertFalse( $this->runner->tick( $job_id ) );
		$this->assertCount( 1, $this->executor->calls );
	}

	/**
	 * A token-bound single-entry plan and its fresh-token copy builder.
	 *
	 * @param array $args Arguments besides the token.
	 * @return array [ plan, callable( token, change ) returning a plan ].
	 */
	private function token_plan( array $args ): array {
		$this->registry->entries['acme/replace-connection-v1'] = $this->registry_entry( 'confirm', 'none', 'atomic', 'preview_token' );
		$plan  = Call_Plan::from_entries(
			array(
				array(
					'ability'          => 'acme/replace-connection-v1',
					'args'             => $args + array(
						'preview_token' => 'expired-token',
						'confirm'       => true,
					),
					'target_ids'       => null,
					'preview_baseline' => str_repeat( 'f', 64 ),
				),
			)
		);
		$fresh = function ( string $token, array $change = array() ) use ( $plan ) {
			$entry         = $plan->entries()[0];
			$entry['args'] = array_merge( $entry['args'], array( 'preview_token' => $token ), $change );
			return Call_Plan::from_entries( array( $entry ) );
		};
		return array( $plan, $fresh );
	}

	public function test_failed_entry_claim_makes_no_native_token_refresh() {
		$grant                    = $this->write_grant();
		list( $plan, $fresh )     = $this->token_plan( array( 'connection' => 'main' ) );
		$job_id                   = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		$this->contracts->refresh = function () use ( $fresh ) {
			return array(
				'plan'  => $fresh( 'fresh-token' ),
				'stale' => false,
			);
		};
		// Another tick wins the entry after this one's checks pass.
		$this->runner->on_log_check = function () use ( $job_id ) {
			$this->assertTrue( $this->store->claim_job_entry( $job_id, 0 ), 'the other tick claims first' );
		};

		$this->assertFalse( $this->runner->tick( $job_id ) );

		$this->assertSame( 0, $this->contracts->refresh_calls, 'the losing tick never calls the native preview' );
		$this->assertSame( array(), $this->executor->calls );
		$job = $this->job( $job_id );
		$this->assertSame( 'running', $job['status'], 'the winner keeps the job' );
		$this->assertSame( '1', $job['next_entry'] );
	}

	public function test_refresh_turning_object_into_list_cancels_the_job() {
		$grant                    = $this->write_grant();
		list( $plan, $fresh )     = $this->token_plan( array( 'selector' => new \stdClass() ) );
		$job_id                   = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		$this->contracts->refresh = function () use ( $fresh ) {
			return array(
				'plan'  => $fresh( 'fresh-token', array( 'selector' => array() ) ),
				'stale' => false,
			);
		};

		$this->assertFalse( $this->runner->tick( $job_id ) );

		$this->assertSame( array(), $this->executor->calls, '{} and [] are different arguments' );
		$job = $this->job( $job_id );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 'preview_changed', $job['last_error'] );
		$this->assertSame( 1, $this->contracts->refresh_calls );
	}

	public function test_tick_that_finds_the_lock_held_keeps_the_job_scheduled() {
		$grant        = $this->write_grant();
		list( $plan ) = $this->token_plan( array( 'connection' => 'main' ) );
		$job_id       = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		update_option( \MainWP\Dashboard\Remote_MCP\MCP_Job_Runner::TICK_LOCK_PREFIX . $job_id, time() . ':held-by-another-tick', false );
		wp_clear_scheduled_hook( \MainWP\Dashboard\Remote_MCP\MCP_Job_Cron::HOOK, array( $job_id ) );

		$this->assertFalse( $this->runner->tick( $job_id ) );

		$this->assertSame( 'running', $this->job( $job_id )['status'] );
		$this->assertSame( array(), $this->executor->calls, 'the held lock still stops this tick' );
		$this->assertNotFalse( wp_next_scheduled( \MainWP\Dashboard\Remote_MCP\MCP_Job_Cron::HOOK, array( $job_id ) ), 'WP-Cron removed the event this tick came from, and nothing else recovers the job' );
	}

	// mainwp.php skips MainWP_System on a faked SecuPress scan request, wp-cron.php included, and the activity log never loads.
	public function test_cron_tick_waits_while_the_dashboard_did_not_boot() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 25, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		$before = $this->job( $job_id );
		wp_clear_scheduled_hook( MCP_Job_Cron::HOOK, array( $job_id ) );
		$cron   = new MCP_Job_Cron( new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Services( array( 'job_runner' => $this->runner ) ) );
		$system = new \ReflectionProperty( \MainWP\Dashboard\MainWP_System::class, 'instance' );
		$system->setAccessible( true );
		$saved = $system->getValue();
		$system->setValue( null, null );
		try {
			$cron->run( $job_id );
		} finally {
			$system->setValue( null, $saved );
		}

		$this->assertSame( array(), $this->executor->calls, 'nothing runs unlogged' );
		$this->assertSame( $before, $this->job( $job_id ) );
		$this->assertNotFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job_id ) ), 'WP-Cron removed the event this tick came from' );

		$cron->run( $job_id );
		$this->assertNotSame( array(), $this->executor->calls, 'the next normal tick runs it' );
	}

	public function test_tick_that_takes_over_a_dead_ticks_lock_fails_the_job() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 25, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		$this->assertTrue( $this->store->claim_job_entry( $job_id, 0 ), 'the dead tick claimed the first entry' );
		$name = \MainWP\Dashboard\Remote_MCP\MCP_Job_Runner::TICK_LOCK_PREFIX . $job_id;
		update_option( $name, ( time() - \MainWP\Dashboard\Remote_MCP\MCP_Job_Runner::TICK_LOCK_TTL - 1 ) . ':dead-tick', false );

		$this->assertFalse( $this->runner->tick( $job_id ) );

		$this->assertSame( array(), $this->executor->calls, 'the next entry doesn\'t run over an unfinished one' );
		$job = $this->job( $job_id );
		$this->assertSame( 'cancelled', $job['status'], 'the job must not report done with an entry unfinished' );
		$this->assertSame( 'tick_interrupted', $job['last_error'] );
		$this->assertFalse( wp_next_scheduled( \MainWP\Dashboard\Remote_MCP\MCP_Job_Cron::HOOK, array( $job_id ) ), 'no tick stays scheduled' );
	}

	public function test_overlapping_tick_never_finishes_job_while_final_entry_runs() {
		$grant                    = $this->write_grant();
		list( $plan )             = $this->token_plan( array( 'connection' => 'main' ) );
		$job_id                   = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		$overlap                  = null;
		$this->contracts->refresh = function () use ( $job_id, &$overlap ) {
			// Tick B runs while tick A, which claimed the final entry, waits on the native preview.
			// B finds A's lock expired, so the exhausted-index guard is what stops it here.
			delete_option( \MainWP\Dashboard\Remote_MCP\MCP_Job_Runner::TICK_LOCK_PREFIX . $job_id );
			$overlap = $this->runner->tick( $job_id );
			$this->assertSame( 'running', $this->job( $job_id )['status'], 'an exhausted index is not completion' );
			return null;
		};

		$this->assertFalse( $this->runner->tick( $job_id ) );

		$this->assertFalse( $overlap, 'the overlapping tick runs nothing' );
		$this->assertSame( array(), $this->executor->calls );
		$job = $this->job( $job_id );
		$this->assertSame( 'cancelled', $job['status'], 'the tick that ran the final entry decides the outcome' );
		$this->assertSame( 'preview_changed', $job['last_error'] );
		$this->assertSame( 1, $this->contracts->refresh_calls );

		// A final entry that throws fails the job instead of leaving it running behind an exhausted index.
		list( $plan, $fresh )     = $this->token_plan( array( 'connection' => 'main' ) );
		$thrown                   = $this->approved_job( $grant, $plan, 'acme/replace-connection-v1' );
		$this->contracts->refresh = function () use ( $fresh ) {
			return array(
				'plan'  => $fresh( 'fresh-token' ),
				'stale' => false,
			);
		};
		$this->executor->run      = function () {
			throw new \RuntimeException( 'ability failed' );
		};
		try {
			$this->runner->tick( $thrown );
			$this->fail( 'the exception reaches the cron runner' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'ability failed', $e->getMessage() );
		}
		$job = $this->job( $thrown );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'entry_failed', $job['last_error'] );
	}

	public function test_overlapping_tick_never_runs_next_entry_while_one_runs() {
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 12, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		$this->assertSame( 2, $plan->count() );
		$overlap             = null;
		$this->executor->run = function () use ( $job_id, &$overlap ) {
			if ( null === $overlap ) {
				// The same cron event fires twice: tick B starts while tick A runs entry 0.
				$overlap = $this->runner->tick( $job_id );
			}
			return new \WP_Error( 'entry_failed' );
		};

		$this->assertTrue( $this->runner->tick( $job_id ) );

		$this->assertFalse( $overlap, 'the overlapping tick runs nothing while an entry runs' );
		$this->assertSame( array( 0 ), array_column( $this->executor->calls, 'index' ), 'entry 1 never ran' );
		$job = $this->job( $job_id );
		$this->assertSame( 'failed', $job['status'], 'a later entry cannot settle the job over a running one' );
		$this->assertSame( 'entry_failed', $job['last_error'] );
	}

	public function test_tick_lock_is_not_taken_over_by_a_racing_upsert() {
		global $wpdb;
		$grant  = $this->write_grant();
		$plan   = $this->sync_plan( $grant, 12, 'mainwp/suspend-sites-v1' );
		$job_id = $this->approved_job( $grant, $plan, 'mainwp/suspend-sites-v1' );
		$name   = \MainWP\Dashboard\Remote_MCP\MCP_Job_Runner::TICK_LOCK_PREFIX . $job_id;
		$held   = time() . ':held-by-another-tick';
		// Another tick holds the lock, but this request's options cache has not seen the row:
		// the state in which add_option() upserts over a live lock instead of failing.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $held,
				'autoload'     => 'off',
			)
		);
		wp_cache_delete( $name, 'options' );
		wp_cache_set( 'notoptions', array( $name => true ), 'options' );

		$this->assertFalse( $this->runner->tick( $job_id ), 'a held lock stops the tick' );
		$this->assertSame( array(), $this->executor->calls );
		$this->assertSame( $held, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ), 'the holder keeps its lock' );
	}

	public function test_job_cancels_when_approved_tier_rises() {
		$grant = $this->write_grant();
		$ability = 'mainwp/suspend-sites-v1';
		$plan = $this->sync_plan( $grant, 15, $ability );
		$approval = $this->store->create_approval( array( 'grant_id' => (int) $grant['id'], 'ability' => $ability, 'tier' => 'confirm', 'args_digest' => str_repeat( 'a', 64 ), 'call_plan' => $plan ) );
		$job = $this->runner->enqueue( $this->context( $grant ), $plan, (int) $approval['approval_id'] );
		$this->assertIsInt( $job );
		$this->assertTrue( $this->runner->tick( $job ) );
		$this->registry->entries[ $ability ]['tier'] = 'dashboard';
		$this->assertFalse( $this->runner->tick( $job ) );
		$this->assertSame( 'cancelled', $this->job( $job )['status'] );
		$this->assertSame( 'approval_required', $this->job( $job )['last_error'] );
		$this->assertCount( 1, $this->executor->calls );
	}

	public function test_purge_keeps_approvals_until_referencing_jobs_finish() {
		global $wpdb;
		$grant = $this->write_grant();
		$ability = 'mainwp/suspend-sites-v1';
		$plan = $this->sync_plan( $grant, 15, $ability );
		$job = $this->approved_job( $grant, $plan, $ability );
		$approval = (int) $this->job( $job )['approval_id'];
		$this->assertTrue( $this->runner->tick( $job ) );
		$starting = $this->approved_job( $grant, $plan, $ability );
		$starting_approval = (int) $this->job( $starting )['approval_id'];
		$wpdb->update( Remote_MCP_Install::table( 'jobs' ), array( 'status' => 'starting' ), array( 'id' => $starting ) );
		$unused = $this->store->create_approval( array( 'grant_id' => (int) $grant['id'], 'ability' => $ability, 'tier' => 'confirm', 'args_digest' => str_repeat( 'b', 64 ) ) );
		$this->assertIsInt( $this->runner->enqueue( $this->context( $grant ), $this->sync_plan( $grant, 1 ), null ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %d', Remote_MCP_Install::table( 'approvals' ), $this->clock->now - DAY_IN_SECONDS - 1 ) );

		$this->store->purge();
		$this->assertNull( $this->store->get_approval( (int) $unused['approval_id'] ), 'an expired approval without a live job is purged' );
		$this->assertTrue( $this->runner->tick( $job ), 'the next entry retains its approval authority after purge' );
		$this->assertSame( 'done', $this->job( $job )['status'] );
		$this->assertCount( 2, $this->executor->calls );
		$this->assertNotNull( $this->store->get_approval( $approval ) );
		$this->assertNotNull( $this->store->get_approval( $starting_approval ), 'a starting job also keeps its approval' );

		$this->runner->cancel( $starting, 'test' );
		$this->store->purge();
		$this->assertNull( $this->store->get_approval( $approval ), 'finished jobs no longer retain approvals' );
		$this->assertNull( $this->store->get_approval( $starting_approval ) );
	}

	public function test_job_refuses_unregistered_originating_ability_without_notice() {
		$grant = $this->write_grant();
		$ids = $this->sites( 1 );
		$preview = $this->envelope( array( $this->record( $ids[0], array( array( 'plugin', 'akismet', '5.3' ) ) ) ) );
		$plan = $this->adapter->build( $this->context( $grant ), 'mainwp/update-all-v1', array(), $this->targets( $ids ), $preview );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$job = $this->approved_job( $grant, $plan, 'mainwp/update-all-v1' );
		$registry = \WP_Abilities_Registry::get_instance();
		$property = new \ReflectionProperty( $registry, 'registered_abilities' );
		$property->setAccessible( true );
		$abilities = $property->getValue( $registry );
		$ability = wp_get_ability( 'mainwp/update-all-v1' );
		wp_unregister_ability( $ability->get_name() );
		$notices = array();
		$capture = function ( $function, $message ) use ( &$notices ) { $notices[] = array( $function, $message ); };
		add_action( 'doing_it_wrong_run', $capture, 10, 2 );
		try {
			$this->assertFalse( $this->runner->tick( $job ) );
			$this->assertSame( 'cancelled', $this->job( $job )['status'] );
			$this->assertSame( 'not_available', $this->job( $job )['last_error'] );
			$this->assertSame( array(), $this->executor->calls );
			$this->assertSame( array(), $notices );
		} finally {
			remove_action( 'doing_it_wrong_run', $capture, 10 );
			$property->setValue( $registry, $abilities );
		}
	}

	public function test_job_rechecks_tool_and_category_switches() {
		foreach ( array( 'tool', 'category' ) as $switch ) {
			$grant = $this->write_grant();
			$plan = $this->sync_plan( $grant, 15 );
			$job = $this->runner->enqueue( $this->context( $grant ), $plan, null );
			$this->assertIsInt( $job );
			$this->assertTrue( $this->runner->tick( $job ) );
			if ( 'tool' === $switch ) {
				Remote_MCP_Settings::set_tool_enabled( 'sync_sites_v1', false );
			} else {
				Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', false );
			}
			$before = count( $this->executor->calls );
			$this->assertFalse( $this->runner->tick( $job ), $switch );
			$this->assertSame( 'cancelled', $this->job( $job )['status'] );
			$this->assertCount( $before, $this->executor->calls );
			Remote_MCP_Settings::set_tool_enabled( 'sync_sites_v1', true );
			Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', true );
		}
	}


	public function test_job_rechecks_originating_tool_after_adapter_changes_ability() {
		$grant = $this->write_grant();
		$ids = $this->sites( 1 );
		$preview = $this->envelope( array( $this->record( $ids[0], array( array( 'plugin', 'akismet', '5.3' ) ) ) ) );
		$plan = $this->adapter->build( $this->context( $grant ), 'mainwp/update-all-v1', array(), $this->targets( $ids ), $preview );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$this->assertSame( 'mainwp/run-updates-v1', $plan->entries()[0]['ability'] );
		$job = $this->approved_job( $grant, $plan, 'mainwp/update-all-v1' );
		Remote_MCP_Settings::set_tool_enabled( 'update_all_v1', false );
		$this->assertFalse( $this->runner->tick( $job ) );
		$this->assertSame( 'cancelled', $this->job( $job )['status'] );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_job_without_approval_cancels_when_tier_rises() {
		$grant = $this->write_grant();
		$plan = $this->sync_plan( $grant, 15 );
		$job = $this->runner->enqueue( $this->context( $grant ), $plan, null );
		$this->assertIsInt( $job );
		$this->registry->entries['mainwp/sync-sites-v1']['tier'] = 'confirm';
		$this->assertFalse( $this->runner->tick( $job ) );
		$this->assertSame( 'approval_required', $this->job( $job )['last_error'] );
		$this->assertSame( array(), $this->executor->calls );
	}

}
