<?php
/**
 * Remote MCP approval gate tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Approval_Gate
 */
class Test_MainWP_MCP_Approval_Gate extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const DASH    = 'mainwp-test/delete-thing-v1';
	const CONFIRM = 'mainwp-test/suspend-thing-v1';
	const WRITE   = 'mainwp-test/sync-thing-v1';

	/**
	 * Props shared by the test abilities.
	 *
	 * @return array
	 */
	private function props(): array {
		return array(
			'site_ids' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'note'     => array( 'type' => 'string' ),
			'confirm'  => array( 'type' => 'boolean' ),
			'dry_run'  => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * Register an ability and its registry entry.
	 *
	 * @param string $name  Ability.
	 * @param string $tier  Tier.
	 * @param array  $entry Entry overrides.
	 * @param array  $o     Ability options.
	 * @return string
	 */
	private function thing( string $name, string $tier, array $entry = array(), array $o = array() ): string {
		$this->ability( $name, $this->props() + ( $o['props'] ?? array() ), $o );
		$this->entry(
			$name,
			$tier,
			$entry + array(
				'resolver' => 'sites',
				'adapter'  => 'atomic',
			)
		);
		return $name;
	}

	/**
	 * Executions of an ability that weren't previews.
	 *
	 * @param string $name Ability.
	 * @return array
	 */
	private function executions( string $name ): array {
		return array_values(
			array_filter(
				$this->ran( $name ),
				function ( $call ) {
					return empty( $call['input']['dry_run'] );
				}
			)
		);
	}

	public function test_confirm_tier_requires_matching_token_and_args_digest() {
		$ctx = $this->ctx();
		$this->thing( self::CONFIRM, 'confirm' );
		$args = array(
			'site_ids' => array( 3 ),
			'note'     => 'a',
		);

		$first = $this->call( $ctx, self::CONFIRM, $args );
		$this->assertSame( 'confirmation_required', $first['decision']['decision'] );
		$body = $this->structured( $first['result'] );
		$this->assertSame( 'CONFIRMATION_REQUIRED', $body['status'] );
		$this->assertMatchesRegularExpression( '/^mwpap_[A-Za-z0-9_-]{43}$/', $body['confirmation_token'] );
		$this->assertCount( 1, $this->ran( self::CONFIRM ), 'the preview ran once' );
		$this->assertTrue( $this->ran( self::CONFIRM )[0]['input']['dry_run'] );
		$token = $body['confirmation_token'];

		// Other arguments under the same token: a new preview, never an execution.
		$other = $this->call(
			$ctx,
			self::CONFIRM,
			array( 'note' => 'b' ) + $args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame( 'confirmation_required', $other['decision']['decision'] );
		$this->assertCount( 0, $this->executions( self::CONFIRM ) );

		// A forged handle is logged and runs nothing.
		$forged = $this->call(
			$ctx,
			self::CONFIRM,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => 'mwpap_' . str_repeat( 'A', 43 ),
			)
		);
		$this->assertNotSame( 'run', $forged['decision']['decision'] );
		$this->assertNotEmpty( $this->audited( 'approval_forgery' ) );
		$this->assertCount( 0, $this->executions( self::CONFIRM ) );

		// Another grant can't use the handle.
		$stranger = $this->ctx();
		$theirs   = $this->call(
			$stranger,
			self::CONFIRM,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertNotSame( 'run', $theirs['decision']['decision'] );
		$this->assertCount( 0, $this->executions( self::CONFIRM ) );

		// Without user_confirmed the handle doesn't execute.
		$unconfirmed = $this->call( $ctx, self::CONFIRM, $args + array( 'confirmation_token' => $token ) );
		$this->assertNotSame( 'run', $unconfirmed['decision']['decision'] );
		$this->assertCount( 0, $this->executions( self::CONFIRM ) );

		$run = $this->call(
			$ctx,
			self::CONFIRM,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertFalse( $run['result']['isError'] );
		$this->assertCount( 1, $this->executions( self::CONFIRM ) );
		$this->assertTrue( $this->executions( self::CONFIRM )[0]['input']['confirm'] );
		$this->assertSame( array( 3 ), $this->executions( self::CONFIRM )[0]['input']['site_ids'] );
		$this->assertNotEmpty( $this->audited( 'confirmed_chat' ) );

		// A confirm retry whose plan becomes a job reaches the job runner still approved.
		add_filter(
			'mainwp_remote_mcp_chunk_size',
			function () {
				return 2;
			}
		);
		$big   = array_merge( $args, array( 'site_ids' => array( 1, 2, 3 ) ) );
		$token = $this->structured( $this->call( $ctx, self::CONFIRM, $big )['result'] )['confirmation_token'];
		$job   = $this->call(
			$ctx,
			self::CONFIRM,
			$big + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame( 'run', $job['decision']['decision'] );
		$this->assertCount( 1, $this->jobs->calls );
		$this->assertSame( 'approved', $this->jobs->calls[0]['status'], 'the row reaches the runner unconsumed' );
		$this->assertSame( 'JOB_STARTED', $this->structured( $job['result'] )['status'] );
		$this->assertSame( 'consumed', $this->store->get_approval( $this->jobs->calls[0]['approval_id'] )['status'] );
		$this->assertCount( 1, $this->executions( self::CONFIRM ), 'the job runs nothing inline' );
	}

	public function test_backup_gate_override_never_reaches_the_ability_or_the_digest() {
		$ctx = $this->ctx();
		$this->thing( self::CONFIRM, 'confirm', array(), array( 'props' => array( 'ignore_backup_gate' => array( 'type' => 'boolean' ) ) ) );
		$args = array(
			'site_ids' => array( 3 ),
			'note'     => 'a',
		);

		$plain    = $this->call( $ctx, self::CONFIRM, $args );
		$override = $this->call( $ctx, self::CONFIRM, $args + array( 'ignore_backup_gate' => true ) );

		$this->assertSame( 'confirmation_required', $override['decision']['decision'] );
		foreach ( $this->ran( self::CONFIRM ) as $preview ) {
			$this->assertArrayNotHasKey( 'ignore_backup_gate', $preview['input'] );
		}
		$rows = $this->approvals( $ctx->grant_id );
		$this->assertCount( 2, $rows );
		$this->assertSame( $rows[0]['args_digest'], $rows[1]['args_digest'], 'the override is not part of what was approved' );
		$this->assertStringNotContainsString( 'ignore_backup_gate', (string) wp_json_encode( $this->structured( $override['result'] ) ) );
	}

	public function test_failed_preview_gives_a_known_code_its_decoded_redacted_message() {
		$ctx = $this->ctx();
		$this->thing(
			self::CONFIRM,
			'confirm',
			array(),
			array(
				'result' => function () {
					return new \WP_Error( 'mainwp_backup_destination_credentials_required', \MainWP\Dashboard\MainWP_Backup_Error_Codes::message( 'mainwp_backup_destination_credentials_required' ) );
				},
			)
		);
		$decision = $this->gate->check( $ctx, self::CONFIRM, array( 'site_ids' => array( 3 ) ) );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'preview_failed', $decision['reason'], 'the audit reason stays the code' );
		$this->assertSame(
			'The preview for this action failed, so nothing was run. This change needs the destination\'s credentials, which can only be entered on the Backup Storage page. Edit the destination there, then try again.',
			$this->gate->deliver( $ctx, $decision )['content'][0]['text']
		);
		$this->assertSame( array(), $this->executions( self::CONFIRM ), 'nothing ran' );

		$secret = 'preview-secret-value';
		$this->thing(
			self::CONFIRM,
			'confirm',
			array( 'sensitive' => array( 'api_key' ) ),
			array(
				'props'  => array( 'api_key' => array( 'type' => 'string' ) ),
				'result' => function ( $input ) {
					return new \WP_Error( 'mainwp_invalid_input', 'Rejected ' . $input['api_key'] . ' from https://user:hunter2@example.org/x.' );
				},
			)
		);
		$text = $this->gate->deliver(
			$ctx,
			$this->gate->check(
				$ctx,
				self::CONFIRM,
				array(
					'site_ids' => array( 3 ),
					'api_key'  => $secret,
				)
			)
		)['content'][0]['text'];
		$this->assertStringStartsWith( 'The preview for this action failed, so nothing was run. Rejected ', $text );
		$this->assertStringNotContainsString( $secret, $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
	}

	public function test_failed_preview_keeps_the_generic_text_for_anything_else() {
		$ctx     = $this->ctx();
		$answers = array(
			'unknown code'  => new \WP_Error( 'provider_said_no', 'Bucket /home/site/private refused the key.' ),
			'unsafe prefix' => new \WP_Error( 'mainwp_not_a_registered_code', 'Internal detail.' ),
			'empty message' => new \WP_Error( 'mainwp_invalid_input', '' ),
			'not an error'  => 'a scalar',
			'thrown'        => null,
		);
		foreach ( $answers as $label => $answer ) {
			$this->thing(
				self::CONFIRM,
				'confirm',
				array(),
				array(
					'result' => function () use ( $answer ) {
						if ( null === $answer ) {
							throw new \RuntimeException( 'Internal detail.' );
						}
						return $answer;
					},
				)
			);
			$decision = $this->gate->check( $ctx, self::CONFIRM, array( 'site_ids' => array( 3 ) ) );
			$this->assertSame( 'preview_failed', $decision['reason'], $label );
			$this->assertSame( 'The preview for this action failed, so nothing was run.', $this->gate->deliver( $ctx, $decision )['content'][0]['text'], $label );
		}
	}

	public function test_confirmation_token_single_use_under_concurrency() {
		$ctx = $this->ctx();
		$this->thing( self::CONFIRM, 'confirm' );
		$args  = array( 'site_ids' => array( 3 ) );
		$token = $this->structured( $this->call( $ctx, self::CONFIRM, $args )['result'] )['confirmation_token'];
		$retry = $args + array(
			'user_confirmed'     => true,
			'confirmation_token' => $token,
		);

		// Two requests read the approved row before either consumes it.
		$a = $this->gate->check( $ctx, self::CONFIRM, $retry );
		$b = $this->gate->check( $ctx, self::CONFIRM, $retry );
		$this->assertSame( 'run', $a['decision'] );
		$this->assertSame( 'run', $b['decision'] );

		$first  = $this->gate->deliver( $ctx, $a );
		$second = $this->gate->deliver( $ctx, $b );
		$this->assertFalse( $first['isError'] );
		$this->assertTrue( $second['isError'], 'the loser runs nothing' );
		$this->assertCount( 1, $this->executions( self::CONFIRM ) );

		$third = $this->call( $ctx, self::CONFIRM, $retry );
		$this->assertNotSame( 'run', $third['decision']['decision'] );
		$this->assertCount( 1, $this->executions( self::CONFIRM ) );
	}

	public function test_chat_row_never_satisfies_dashboard_retry() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args   = array( 'site_ids' => array( 3 ) );
		$digest = $this->gate->args_digest( self::DASH, $args );
		$this->assertIsString( $digest );

		// A chat confirmation for exactly this action, as a Hands-off call would leave it.
		$chat = $this->store->create_approval(
			array(
				'grant_id'    => $ctx->grant_id,
				'ability'     => self::DASH,
				'tier'        => 'confirm',
				'args_digest' => $digest,
				'target_ids'  => array( 3 ),
				'call_plan'   => Call_Plan::from_entries(
					array(
						array(
							'ability'    => self::DASH,
							'args'       => $args,
							'target_ids' => array( 3 ),
						),
					)
				),
			)
		);
		$this->assertSame( OAuth_Store::OK, $chat['outcome'] );

		$retry = $this->call(
			$ctx,
			self::DASH,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $chat['handle'],
			)
		);
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertSame( array(), $this->ran( self::DASH ) );
		$this->assertSame( 'approved', $this->store->get_approval( $chat['approval_id'] )['status'], 'the chat row is never consumed for a dashboard call' );
		$this->assertFalse( $this->store->consume_approval( $chat['approval_id'], 'dashboard' ) );
	}

	public function test_policy_change_stales_open_approvals() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$this->thing( self::CONFIRM, 'confirm' );
		$args = array( 'site_ids' => array( 3 ) );

		$pending = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $pending )['outcome'] );
		$token = $this->structured( $this->call( $ctx, self::CONFIRM, $args )['result'] )['confirmation_token'];

		$this->assertTrue( $this->store->set_approval_policy( $ctx->grant_id, 'strict' ) );
		$ctx = $this->again( $ctx );

		$this->assertSame( 'stale', $this->store->get_approval( $pending )['status'] );
		$retry = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertNotEquals( $pending, $retry['decision']['approval_id'], 'a new row at the new policy version' );

		$confirm = $this->call(
			$ctx,
			self::CONFIRM,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertNotSame( 'run', $confirm['decision']['decision'] );
		$this->assertSame( array(), $this->executions( self::DASH ) );
		$this->assertSame( array(), $this->executions( self::CONFIRM ) );

		// A request authenticated just before a policy change still carries the old version; an
		// approved row from that version never runs, even before anything marks it stale.
		global $wpdb;
		$old   = $this->ctx();
		$token = $this->structured( $this->call( $old, self::CONFIRM, $args )['result'] )['confirmation_token'];
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET policy_version = policy_version + 1 WHERE id = %d', \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::table( 'grants' ), $old->grant_id ) );
		$late = $this->gate->check(
			$old,
			self::CONFIRM,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertNotSame( 'run', $late['decision'] );
		$this->assertSame( array(), $this->executions( self::CONFIRM ) );
	}

	public function test_stale_dashboard_plan_creates_new_pending_dashboard_row() {
		$ctx    = $this->ctx();
		$effect = (object) array( 'count' => 1 );
		$this->thing(
			self::DASH,
			'dashboard',
			array( 'compare_fields' => array( 'count' ) ),
			array(
				'result' => function ( $input ) use ( $effect ) {
					return array(
						'count'       => $effect->count,
						'fingerprint' => wp_generate_uuid4(),
					);
				},
			)
		);
		$args  = array( 'site_ids' => array( 3 ) );
		$first = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $first )['outcome'] );
		$this->assertCount( 1, $this->ran( self::DASH ), 'the click ran the preview once' );

		$effect->count = 2;
		$retry         = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $first )['status'] );
		$new = $this->store->get_approval( (int) $retry['decision']['approval_id'] );
		$this->assertSame( 'dashboard', $new['tier'], 'never a chat confirmation' );
		$this->assertSame( 'pending', $new['status'] );
		$this->assertSame( array(), $this->executions( self::DASH ) );
		$this->assertNotEmpty( $this->audited( 'stale_plan' ) );
	}

	public function test_changed_preview_stales_run_from_page() {
		$ctx    = $this->ctx();
		$effect = (object) array( 'count' => 1 );
		$this->thing(
			self::DASH,
			'dashboard',
			array( 'compare_fields' => array( 'count' ) ),
			array(
				'result' => function ( $input ) use ( $effect ) {
					return array(
						'count'       => $effect->count,
						'fingerprint' => wp_generate_uuid4(),
					);
				},
			)
		);
		$id = (int) $this->call( $ctx, self::DASH, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$effect->count = 2;
		$this->assertSame( 'stale', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( 'stale', $this->store->get_approval( $id )['status'] );
		$this->assertSame( array(), $this->executions( self::DASH ) );
	}

	public function test_unchanged_preview_runs_from_page() {
		$ctx = $this->ctx();
		$this->thing(
			self::DASH,
			'dashboard',
			array( 'compare_fields' => array( 'count' ) ),
			array(
				'result' => function ( $input ) {
					return array(
						'count'       => 1,
						'fingerprint' => wp_generate_uuid4(),
					);
				},
			)
		);
		$id = (int) $this->call( $ctx, self::DASH, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertSame( 'ran', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
		$this->assertCount( 1, $this->executions( self::DASH ) );
	}

	public function test_arguments_too_deep_to_digest_are_refused_before_any_row() {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/deep-args-v1';
		$this->ability(
			$name,
			array(
				'site_ids' => array( 'type' => 'array' ),
				'blob'     => array( 'type' => 'object' ),
				'api_key'  => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
			)
		);
		// A credential makes the row plan-less, so no pending plan's own depth check stops it.
		$this->entry( $name, 'dashboard', array( 'resolver' => 'sites' ) );
		$deep = array( 'leaf' => 'x' );
		for ( $i = 0; $i < 40; $i++ ) {
			$deep = array( 'a' => $deep );
		}
		$decision = $this->call(
			$ctx,
			$name,
			array(
				'site_ids' => array( 3 ),
				'blob'     => $deep,
				'api_key'  => 'synthetic-key',
			)
		)['decision'];
		$this->assertSame( 'refused', $decision['decision'], 'no shared digest for every over-deep call' );
		$this->assertSame( 'plan_failed', $decision['reason'] );
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ) );
	}

	public function test_args_digest_returns_null_for_arguments_too_deep_to_canonicalize() {
		$deep = array( 'leaf' => 'x' );
		for ( $i = 0; $i < 40; $i++ ) {
			$deep = array( 'a' => $deep );
		}
		$this->assertNull( $this->gate->args_digest( self::DASH, array( 'blob' => $deep ) ) );
	}

	public function test_empty_target_resolves_to_explicit_ids_and_executes_only_those() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$this->resolver->map[ self::DASH ] = function ( $args ) {
			return array(
				'site_ids' => empty( $args['site_ids'] ) ? array( 4, 5 ) : $args['site_ids'],
				'entity'   => null,
			);
		};
		$this->adapter->map[ self::DASH ]  = function ( $args, $targets ) {
			$args['site_ids'] = $targets['site_ids'];
			return Call_Plan::from_entries(
				array(
					array(
						'ability'    => self::DASH,
						'args'       => $args,
						'target_ids' => $targets['site_ids'],
					),
				)
			);
		};
		$args                              = array( 'site_ids' => array() );

		$first = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->assertSame( '[4,5]', $this->store->get_approval( $first )['target_ids'] );
		$this->approve( $first );
		$run = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertFalse( $run['result']['isError'] );
		$this->assertCount( 1, $this->executor->calls );
		$this->assertSame( array( 4, 5 ), $this->executor->calls[0]['entry']['target_ids'] );
		$this->assertSame( array( 4, 5 ), $this->executions( self::DASH )[0]['input']['site_ids'], 'explicit ids, never "empty means all"' );
	}

	public function test_new_site_after_approval_marks_stale() {
		$ctx   = $this->ctx();
		$sites = (object) array( 'all' => array( 4, 5 ) );
		$this->thing( self::DASH, 'dashboard' );
		$this->resolver->map[ self::DASH ] = function ( $args ) use ( $sites ) {
			return array(
				'site_ids' => $sites->all,
				'entity'   => null,
			);
		};
		$args                              = array( 'site_ids' => array() );
		$first                             = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->approve( $first );
		$sites->all = array( 4, 5, 6 );
		$retry      = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $first )['status'] );

		// An entity action: a site joining the client after approval stales the row, on the retry and from the page.
		$client = 'mainwp-test/delete-client-v1';
		$this->ability( $client, array( 'client_id' => array( 'type' => 'integer' ) ) );
		$this->entry(
			$client,
			'dashboard',
			array(
				'resolver' => 'client',
				'adapter'  => 'atomic',
			)
		);
		$members                        = (object) array( 'ids' => array( 7, 8 ) );
		$this->resolver->map[ $client ] = function ( $args ) use ( $members ) {
			return array(
				'site_ids' => $members->ids,
				'entity'   => array(
					'type' => 'client',
					'id'   => 9,
				),
			);
		};
		$entity                         = array( 'client_id' => 9 );
		$row                            = $this->call( $ctx, $client, $entity )['decision']['approval_id'];
		$this->approve( $row );
		$members->ids = array( 7, 8, 10 );
		$this->assertSame( 'approval_required', $this->call( $ctx, $client, $entity )['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $row )['status'] );

		$members->ids = array( 7, 8 );
		$page_row     = $this->call( $ctx, $client, $entity )['decision']['approval_id'];
		$this->approve( $page_row );
		$members->ids = array( 7, 8, 11 );
		$this->assertSame( 'stale', $this->gate->page_decide( (int) $page_row, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( 'stale', $this->store->get_approval( (int) $page_row )['status'] );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_lost_access_marks_stale_not_shrink() {
		$ctx    = $this->ctx();
		$access = (object) array( 'ids' => array( 4, 5 ) );
		$this->thing( self::DASH, 'dashboard' );
		$this->resolver->map[ self::DASH ] = function ( $args ) use ( $access ) {
			if ( null === $access->ids ) {
				return null;
			}
			return array(
				'site_ids' => array_values( array_intersect( $args['site_ids'], $access->ids ) ),
				'entity'   => null,
			);
		};
		$args                              = array( 'site_ids' => array( 4, 5 ) );
		$first                             = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->approve( $first );

		$access->ids = array( 4 );
		$retry       = $this->call( $ctx, self::DASH, $args );
		$this->assertNotSame( 'run', $retry['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $first )['status'] );
		$this->assertSame( array(), $this->executor->calls, 'never run on the sites still reachable' );

		$access->ids = null;
		$this->assertSame( 'refused', $this->gate->check( $ctx, self::DASH, $args )['decision'] );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_dashboard_tier_returns_approval_required_without_running() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$out = $this->call( $ctx, self::DASH, array( 'site_ids' => array( 3 ) ) );

		$this->assertSame( 'approval_required', $out['decision']['decision'] );
		$this->assertSame( 'dashboard', $out['decision']['tier'] );
		$this->assertFalse( $out['result']['isError'] );
		$body = $this->structured( $out['result'] );
		$id   = (int) $out['decision']['approval_id'];
		$this->assertSame( 'APPROVAL_REQUIRED', $body['status'] );
		$this->assertSame( 'https://dash.example.org/mainwp-oauth/approve?approval=' . $id, $body['approval_url'] );
		$this->assertArrayHasKey( 'expires_at', $body );
		$this->assertArrayHasKey( 'summary', $body );
		$this->assertStringContainsString( 'call this tool again with the same arguments', $out['result']['content'][0]['text'] );
		$this->assertSame( array(), $this->ran( self::DASH ) );
		$this->assertSame( array(), $this->executor->calls );

		$row = $this->store->get_approval( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'dashboard', $row['tier'] );
		$this->assertTrue( $this->store->approval_call_plan( $row )->is_pending() );
		$this->assertNotEmpty( $this->audited( 'approval_required' ) );
	}

	public function test_expired_pending_starts_over_without_preview_or_outbound() {
		$this->expired_dashboard_retry( false );
	}

	public function test_expired_approved_starts_over_without_preview_or_outbound() {
		$this->expired_dashboard_retry( true );
	}

	/**
	 * Expired credential approvals must never authorize a preview.
	 *
	 * @param bool $approved Whether to approve before expiry.
	 */
	private function expired_dashboard_retry( bool $approved ): void {
		global $wpdb;
		$ctx      = $this->ctx();
		$name     = 'mainwp-test/expired-credentials-v1';
		$outbound = (object) array( 'count' => 0 );
		add_filter(
			'pre_http_request',
			function () use ( $outbound ) {
				++$outbound->count;
				return new \WP_Error( 'offline', 'Synthetic request' );
			}
		);
		$this->ability(
			$name,
			array(
				'blob'    => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
				'dry_run' => array( 'type' => 'boolean' ),
			),
			array(
				'result' => function () {
					wp_remote_get( 'https://expired.example/' );
					return array( 'done' => true );
				},
			)
		);
		$this->entry( $name, 'dashboard' );
		$args = array( 'blob' => 'synthetic-credential' );
		$id   = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		if ( $approved ) {
			$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		}
		$wpdb->update( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::table( 'approvals' ), array( 'expires_at' => time() - 1 ), array( 'id' => $id ) );
		$retry = $this->call( $ctx, $name, $args );
		$this->assertCount( 0, $this->ran( $name ), 'expired retry must not preview or execute' );
		$this->assertSame( 0, $outbound->count, 'expired retry must not send outbound requests' );
		$this->assertSame( array(), $this->executor->calls );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertNotEquals( $id, $retry['decision']['approval_id'], 'expired pending links must be replaced' );
		$this->assertSame( 'pending', $this->store->get_approval( (int) $retry['decision']['approval_id'] )['status'] );
	}

	public function test_retry_after_approval_executes_once() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args = array( 'site_ids' => array( 3 ) );

		$id = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->approve( $id );
		$run = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertFalse( $run['result']['isError'] );
		$this->assertCount( 1, $this->executions( self::DASH ) );
		$this->assertSame( $ctx->grant_id, $this->executor->calls[0]['grant_id'] );
		$this->assertSame( 'consumed', $this->store->get_approval( $id )['status'] );

		$again = $this->call( $ctx, self::DASH, $args );
		$this->assertNotSame( 'run', $again['decision']['decision'] );
		$this->assertCount( 1, $this->executions( self::DASH ) );

		// A multi-entry plan goes to the job runner still approved and is consumed once there.
		$multi                        = 'mainwp-test/delete-many-v1';
		$this->adapter->map[ $multi ] = function ( $a, $targets ) use ( $multi ) {
			$entries = array();
			foreach ( $targets['site_ids'] as $site ) {
				$entries[] = array(
					'ability'    => $multi,
					'args'       => array( 'site_ids' => array( $site ) ),
					'target_ids' => array( $site ),
				);
			}
			return Call_Plan::from_entries( $entries );
		};
		$this->thing( $multi, 'dashboard', array( 'adapter' => 'chunked_sites' ) );
		$many = array( 'site_ids' => array( 3, 4 ) );
		$id   = $this->call( $ctx, $multi, $many )['decision']['approval_id'];
		$this->approve( $id );
		$job = $this->call( $ctx, $multi, $many );
		$this->assertSame( 'run', $job['decision']['decision'] );
		$this->assertSame( 'JOB_STARTED', $this->structured( $job['result'] )['status'] ?? null );
		$this->assertSame( 2, $this->structured( $job['result'] )['entries_total'] ?? null );
		$this->assertCount( 1, $this->jobs->calls );
		$this->assertSame( 'approved', $this->jobs->calls[0]['status'] );
		$this->assertSame( 'consumed', $this->store->get_approval( $id )['status'] );
		$this->assertSame( array(), $this->executions( $multi ) );
		$this->call( $ctx, $multi, $many );
		$this->assertCount( 1, $this->jobs->calls, 'consumed once' );
	}

	public function test_retry_while_pending_creates_no_new_row() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args  = array( 'site_ids' => array( 3 ) );
		$first = $this->call( $ctx, self::DASH, $args );
		$retry = $this->call( $ctx, self::DASH, $args );
		$third = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertSame( $first['decision']['approval_id'], $retry['decision']['approval_id'] );
		$this->assertSame( $first['decision']['approval_id'], $third['decision']['approval_id'] );
		$this->assertCount( 1, $this->approvals( $ctx->grant_id ) );
		$this->assertSame( $this->structured( $first['result'] )['approval_url'], $this->structured( $retry['result'] )['approval_url'] );
	}

	public function test_denied_approval_returns_error() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args = array( 'site_ids' => array( 3 ) );
		$id   = $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->assertSame( 'denied', $this->gate->page_decide( (int) $id, $this->user_id, 'deny' )['outcome'] );

		$retry = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'denied', $retry['decision']['decision'] );
		$this->assertTrue( $retry['result']['isError'] );
		$this->assertSame( 'The user denied this action.', $retry['result']['content'][0]['text'] );
		$this->assertSame( array(), $this->ran( self::DASH ) );
	}

	public function test_request_state_tamper_rejected() {
		$this->clients->clients['claude-code'] = array(
			'client_id'         => 'claude-code',
			'kind'              => 'bundled',
			'label'             => 'Claude Code',
			'approval_delivery' => 'elicitation',
		);
		$ctx                                   = $this->ctx(
			array(
				'client_id' => 'claude-code',
				'era'       => 'modern',
				'elicit'    => true,
			)
		);
		$this->thing( self::DASH, 'dashboard' );
		$args  = array( 'site_ids' => array( 3 ) );
		$first = $this->call( $ctx, self::DASH, $args );
		$state = $first['result']['requestState'];
		$this->assertIsString( $state );

		$tampered = substr( $state, 0, -1 ) . ( 'A' === substr( $state, -1 ) ? 'B' : 'A' );
		$bad      = $this->gate->check( $this->again( $ctx, array( 'state' => $tampered ) ), self::DASH, $args );
		$this->assertSame( 'refused', $bad['decision'] );
		$this->assertSame( 'request_state_invalid', $bad['reason'] );
		$this->assertNotEmpty( $this->audited( 'approval_forgery' ) );

		// A genuine state for another approval doesn't match this row.
		$other = $this->call( $ctx, self::DASH, array( 'site_ids' => array( 4 ) ) )['result']['requestState'];
		$this->assertSame( 'refused', $this->gate->check( $this->again( $ctx, array( 'state' => $other ) ), self::DASH, $args )['decision'] );

		$good = $this->gate->check( $this->again( $ctx, array( 'state' => $state ) ), self::DASH, $args );
		$this->assertSame( 'approval_required', $good['decision'] );
		$this->assertCount( 2, $this->approvals( $ctx->grant_id ) );
		$this->assertSame( array(), $this->ran( self::DASH ) );
	}

	public function test_elicitation_accept_without_approved_row_does_not_execute() {
		$this->clients->clients['claude-code'] = array(
			'client_id'         => 'claude-code',
			'kind'              => 'bundled',
			'label'             => 'Claude Code',
			'approval_delivery' => 'elicitation',
		);
		$ctx                                   = $this->ctx(
			array(
				'client_id' => 'claude-code',
				'era'       => 'modern',
				'elicit'    => true,
			)
		);
		$this->thing( self::DASH, 'dashboard' );
		$args  = array( 'site_ids' => array( 3 ) );
		$first = $this->call( $ctx, self::DASH, $args );

		// The client says the user accepted the elicitation and retries with its state; the row is still pending.
		$retry = $this->call( $this->again( $ctx, array( 'state' => $first['result']['requestState'] ) ), self::DASH, $args );
		$this->assertSame( 'approval_required', $retry['decision']['decision'] );
		$this->assertSame( 'input_required', $retry['result']['resultType'] );
		$this->assertSame( $first['decision']['approval_id'], $retry['decision']['approval_id'] );
		$this->assertSame( array(), $this->ran( self::DASH ) );
		$this->assertSame( array(), $this->executor->calls );
	}

	public function test_legacy_elicitation_client_gets_32042() {
		$this->clients->clients['vscode'] = array(
			'client_id'         => 'vscode',
			'kind'              => 'bundled',
			'label'             => 'VS Code',
			'approval_delivery' => 'elicitation',
		);
		$ctx                              = $this->ctx(
			array(
				'client_id' => 'vscode',
				'era'       => 'legacy',
			)
		);
		$this->thing( self::DASH, 'dashboard' );
		$out = $this->call( $ctx, self::DASH, array( 'site_ids' => array( 3 ) ) );
		$id  = (int) $out['decision']['approval_id'];

		$this->assertArrayHasKey( 'jsonrpc_error', $out['result'] );
		$fixture = str_replace(
			array( '{{approval_url}}', '{{approval_id}}' ),
			array( 'https://dash.example.org/mainwp-oauth/approve?approval=' . $id, (string) $id ),
			(string) file_get_contents( __DIR__ . '/fixtures/mcp/url-elicitation-required.json' )
		);
		$this->assertSame( json_decode( $fixture, true ), json_decode( (string) wp_json_encode( $out['result']['jsonrpc_error'] ), true ) );
		$this->assertSame( -32042, $out['result']['jsonrpc_error']['code'] );

		// The same era with result delivery gets the tool result.
		$plain = $this->call( $this->again( $ctx, array( 'client_id' => self::CLIENT_ID ) ), self::DASH, array( 'site_ids' => array( 4 ) ) );
		$this->assertSame( 'APPROVAL_REQUIRED', $this->structured( $plain['result'] )['status'] );
	}

	public function test_desktop_client_id_never_gets_elicitation() {
		// Even if the registry said elicitation, claude.ai's id (shared by Claude Desktop) gets the result form.
		$this->clients->clients[ self::CLIENT_ID ]['approval_delivery'] = 'elicitation';
		$this->clients->clients['mwplc_desktopapp']                     = array(
			'client_id'         => 'mwplc_desktopapp',
			'kind'              => 'registered',
			'label'             => 'Desktop app',
			'approval_delivery' => 'elicitation',
		);
		$this->thing( self::DASH, 'dashboard' );
		$cases = array(
			array( self::CLIENT_ID, 'bundled' ),
			array( 'mwplc_desktopapp', 'registered' ),
			array( OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, 'token' ),
		);
		$site  = 3;
		foreach ( $cases as list( $client_id, $kind ) ) {
			foreach ( array( 'modern', 'legacy' ) as $era ) {
				$ctx = $this->ctx(
					array(
						'client_id' => $client_id,
						'kind'      => $kind,
						'era'       => $era,
						'elicit'    => true,
					)
				);
				$out = $this->call( $ctx, self::DASH, array( 'site_ids' => array( $site++ ) ) );
				$this->assertArrayNotHasKey( 'jsonrpc_error', $out['result'], $client_id . ' ' . $era );
				$this->assertArrayNotHasKey( 'resultType', $out['result'], $client_id . ' ' . $era );
				$this->assertSame( 'APPROVAL_REQUIRED', $this->structured( $out['result'] )['status'] );
			}
		}
	}

	public function test_generation_bump_revokes_open_approvals() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$this->thing( self::CONFIRM, 'confirm' );
		$args     = array( 'site_ids' => array( 3 ) );
		$pending  = (int) $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$approved = (int) $this->call( $ctx, self::DASH, array( 'site_ids' => array( 4 ) ) )['decision']['approval_id'];
		$this->approve( $approved );
		$token = $this->structured( $this->call( $ctx, self::CONFIRM, $args )['result'] )['confirmation_token'];

		$this->assertNotNull( $this->store->bump_user_generation( $this->user_id, 'password_changed' ) );
		$this->calls = array();

		$this->assertNotSame( 'approved', $this->approve( $pending )['outcome'] );
		$this->assertSame( array(), $this->ran( self::DASH ), 'Approve checks the grant before running any preview' );
		$this->assertNotSame( 'run', $this->gate->check( $ctx, self::DASH, array( 'site_ids' => array( 4 ) ) )['decision'] );
		$this->assertNotSame(
			'run',
			$this->gate->check(
				$ctx,
				self::CONFIRM,
				$args + array(
					'user_confirmed'     => true,
					'confirmation_token' => $token,
				)
			)['decision']
		);
		$this->assertNotSame( 'ran', $this->gate->page_decide( $approved, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( array(), $this->executor->calls );
		$this->assertSame( array(), $this->executions( self::CONFIRM ) );

		// A row still pending on a grant that is no longer usable: Approve runs no preview.
		global $wpdb;
		$fresh = $this->ctx();
		$open  = (int) $this->call( $fresh, self::DASH, $args )['decision']['approval_id'];
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET session_expires_at = 1 WHERE id = %d', \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::table( 'grants' ), $fresh->grant_id ) );
		$this->calls = array();
		$this->assertSame( 'pending', $this->store->get_approval( $open )['status'] );
		$this->assertNotSame( 'approved', $this->approve( $open )['outcome'] );
		$this->assertSame( array(), $this->ran( self::DASH ), 'no preview for an unusable grant' );
	}

	public function test_strict_policy_moves_write_tools_to_dashboard_tier() {
		$this->thing( self::WRITE, 'write' );
		$this->thing( self::CONFIRM, 'confirm' );
		$args   = array( 'site_ids' => array( 3 ) );
		$strict = $this->ctx( array( 'policy' => 'strict' ) );

		foreach ( array( self::WRITE, self::CONFIRM ) as $ability ) {
			$out = $this->call( $strict, $ability, $args );
			$this->assertSame( 'approval_required', $out['decision']['decision'], $ability );
			$this->assertSame( 'dashboard', $this->store->get_approval( (int) $out['decision']['approval_id'] )['tier'] );
		}
		$this->assertSame( array(), $this->ran( self::WRITE ) );
		$this->assertSame( array(), $this->ran( self::CONFIRM ), 'no preview before the click either' );

		$standard = $this->ctx();
		$this->assertSame( 'run', $this->call( $standard, self::WRITE, $args )['decision']['decision'] );
		$this->assertCount( 1, $this->ran( self::WRITE ) );
	}

	public function test_hands_off_logged_on_every_would_be_dashboard_call() {
		$ctx = $this->ctx( array( 'policy' => 'hands_off' ) );
		$this->thing( self::DASH, 'dashboard' );
		$this->thing( self::CONFIRM, 'confirm' );
		$args = array( 'site_ids' => array( 3 ) );

		$first = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'confirmation_required', $first['decision']['decision'] );
		$events = $this->audited( 'hands_off' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'hands_off', $events[0]['approval_policy'] );
		$this->assertSame( self::DASH, $events[0]['ability'] );
		$this->assertSame( 'confirm', $events[0]['tier'] );
		$this->assertContains( 'standard', array_column( $this->policy->calls, 'policy' ), 'the would-be tier comes from a Standard copy of the context' );

		$token = $this->structured( $first['result'] )['confirmation_token'];
		$this->call(
			$ctx,
			self::DASH,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertCount( 2, $this->audited( 'hands_off' ), 'the confirmed call is logged too' );
		$this->assertCount( 1, $this->executions( self::DASH ) );

		$this->call( $ctx, self::CONFIRM, $args );
		$this->assertCount( 2, $this->audited( 'hands_off' ), 'a confirm-tier tool is not a would-be dashboard call' );
	}

	/**
	 * The update-site field rule, as a conditional tier over the projected arguments.
	 */
	private function update_site(): string {
		$name = 'mainwp-test/update-site-v1';
		$this->ability(
			$name,
			array(
				'site_id'            => array( 'type' => 'integer' ),
				'name'               => array( 'type' => 'string' ),
				'url'                => array( 'type' => 'string' ),
				'http_user'          => array( 'type' => 'string' ),
				'http_pass'          => array( 'type' => 'string' ),
				'verify_certificate' => array( 'type' => 'boolean' ),
				'ssl_version'        => array( 'type' => 'string' ),
				'suspended'          => array( 'type' => 'boolean' ),
			),
			array( 'closed' => true )
		);
		$this->entry(
			$name,
			'write',
			array(
				'conditional' => 'update_site_fields',
				'resolver'    => 'site',
				'adapter'     => 'atomic',
			)
		);
		$this->resolver->map[ $name ]                     = function ( $args ) {
			return array(
				'site_ids' => array( $args['site_id'] ),
				'entity'   => null,
			);
		};
		$this->policy->conditionals['update_site_fields'] = function ( $args ) {
			if ( array_intersect( array( 'url', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass' ), array_keys( $args ) ) ) {
				return 'dashboard';
			}
			return array_key_exists( 'suspended', $args ) ? 'confirm' : 'write';
		};
		return $name;
	}

	public function test_update_site_credential_fields_force_dashboard_tier() {
		$ctx  = $this->ctx();
		$name = $this->update_site();

		$this->assertSame(
			'run',
			$this->call(
				$ctx,
				$name,
				array(
					'site_id' => 4,
					'name'    => 'Renamed',
				)
			)['decision']['decision']
		);
		foreach ( array( 'http_pass', 'http_user' ) as $field ) {
			$out = $this->call(
				$ctx,
				$name,
				array(
					'site_id' => 4,
					$field    => 's3cret-' . $field,
				)
			);
			$this->assertSame( 'approval_required', $out['decision']['decision'], $field );
			$row = $this->store->get_approval( (int) $out['decision']['approval_id'] );
			$this->assertNull( $row['call_plan'], 'credential calls are plan-less' );
			$this->assertStringNotContainsString( 's3cret', (string) wp_json_encode( $row ) );
			$this->assertStringNotContainsString( 's3cret', (string) wp_json_encode( $out['result'] ) );
		}
		$url = $this->call(
			$ctx,
			$name,
			array(
				'site_id' => 4,
				'url'     => 'https://moved.example',
			)
		);
		$this->assertSame( 'approval_required', $url['decision']['decision'] );
		$this->assertTrue( $this->store->approval_call_plan( $this->store->get_approval( (int) $url['decision']['approval_id'] ) )->is_pending() );
		$this->assertContains( 'http_pass', array_merge( ...array_map( 'array_keys', array_column( $this->policy->calls, 'arguments' ) ) ), 'the deciding field reaches the tier policy' );
		$this->assertCount( 1, $this->ran( $name ) );
	}

	public function test_args_digest_is_keyed() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args      = array(
			'site_ids' => array( 3 ),
			'note'     => 'x',
		);
		$canonical = Call_Plan::canonical_json(
			array(
				'ability' => self::DASH,
				'args'    => $args,
			)
		);
		$digest    = $this->gate->args_digest( self::DASH, $args );
		$this->assertIsString( $digest );
		$this->assertSame( ( new OAuth_Token_Hasher() )->digest( 'args', $canonical ), $digest );
		$this->assertNotSame( hash( 'sha256', $canonical ), $digest, 'never a plain hash' );
		$this->assertSame(
			$digest,
			$this->gate->args_digest(
				self::DASH,
				array(
					'note'     => 'x',
					'site_ids' => array( 3 ),
				)
			),
			'canonical: key order does not matter'
		);

		$row = $this->store->get_approval(
			(int) $this->call(
				$ctx,
				self::DASH,
				$args + array(
					'confirm' => true,
					'dry_run' => false,
				)
			)['decision']['approval_id']
		);
		$this->assertSame( $digest, $row['args_digest'], 'controls never enter the digest' );

		( new OAuth_Token_Hasher() )->rotate_secret();
		$this->assertNotSame( $digest, $this->gate->args_digest( self::DASH, $args ), 'keyed by the module secret' );
	}

	public function test_pending_row_returned_on_retry_but_never_executes() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args  = array( 'site_ids' => array( 3 ) );
		$first = $this->call( $ctx, self::DASH, $args );
		$id    = (int) $first['decision']['approval_id'];
		$this->assertSame( 'approval_required', $this->call( $ctx, self::DASH, $args )['decision']['decision'] );
		$this->assertFalse( $this->store->consume_approval( $id, 'dashboard' ) );

		// A forged run decision for the pending row consumes nothing and runs nothing.
		$plan   = Call_Plan::from_entries(
			array(
				array(
					'ability'    => self::DASH,
					'args'       => $args,
					'target_ids' => array( 3 ),
				),
			)
		);
		$result = $this->gate->deliver(
			$ctx,
			array(
				'decision'    => 'run',
				'tier'        => 'dashboard',
				'reason'      => null,
				'approval_id' => $id,
				'plan'        => $plan,
			)
		);
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'pending', $this->store->get_approval( $id )['status'] );
		$this->assertSame( array(), $this->executor->calls );
		$this->assertSame( array(), $this->ran( self::DASH ) );
	}

	public function test_write_only_schema_field_never_stored_in_call_plan() {
		$ctx  = $this->ctx();
		$blob = array(
			'blob' => array(
				'type'      => 'string',
				'writeOnly' => true,
			),
		);
		$this->thing( self::DASH, 'dashboard', array(), array( 'props' => $blob ) );
		$this->thing( self::CONFIRM, 'confirm', array(), array( 'props' => $blob ) );
		$args = array(
			'site_ids' => array( 3 ),
			'blob'     => 'TOPSECRET-BLOB',
		);
		foreach ( array( self::DASH, self::CONFIRM ) as $ability ) {
			$out = $this->call( $ctx, $ability, $args );
			$row = $this->store->get_approval( (int) $out['decision']['approval_id'] );
			$this->assertNull( $row['call_plan'], $ability . ' stores no plan' );
			$this->assertStringNotContainsString( 'TOPSECRET', (string) wp_json_encode( $row ) );
			$this->assertStringNotContainsString( 'TOPSECRET', (string) wp_json_encode( $out['result'] ) );
		}
	}

	public function test_credential_bearing_extension_ability_is_retry_only() {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/replace-ext-credential-v1';
		$this->ability(
			$name,
			array(
				'secret_value' => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
			)
		);
		$this->entry(
			$name,
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
				'sensitive'          => array( 'secret_value' ),
			)
		);
		$args = array(
			'secret_value' => 'CRED-ONE',
			'label'        => 'x',
		);
		$id   = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$row  = $this->store->get_approval( $id );
		$this->assertNull( $row['call_plan'] );
		$this->assertStringNotContainsString( 'CRED-ONE', (string) wp_json_encode( $row ) );

		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertSame( array(), $this->ran( $name ), 'Approve runs nothing for a plan-less row' );
		$this->assertFalse( $this->gate->page_view( $id, $this->user_id )['can_run'] );
		$this->assertNotSame( 'ran', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( array(), $this->ran( $name ) );

		// Other credential bytes are another action.
		$other = $this->call( $ctx, $name, array( 'secret_value' => 'CRED-TWO' ) + $args );
		$this->assertSame( 'approval_required', $other['decision']['decision'] );
		$this->assertSame( array(), $this->ran( $name ) );

		$run = $this->call( $ctx, $name, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertCount( 1, $this->ran( $name ) );
		$this->assertSame( 'CRED-ONE', $this->ran( $name )[0]['input']['secret_value'] );
		$this->assertSame( array(), $this->jobs->calls );
	}

	public function test_hands_off_never_downgrades_update_site_url_and_tls_fields_or_add_site() {
		$ctx  = $this->ctx( array( 'policy' => 'hands_off' ) );
		$name = $this->update_site();
		$add  = 'mainwp-test/add-site-v1';
		$this->ability(
			$add,
			array(
				'url'           => array( 'type' => 'string' ),
				'adminpassword' => array( 'type' => 'string' ),
			)
		);
		$this->entry( $add, 'dashboard' );

		$fields = array(
			'url'                => 'https://moved.example',
			'verify_certificate' => false,
			'ssl_version'        => 'tlsv1_2',
		);
		foreach ( $fields as $field => $value ) {
			$out = $this->call(
				$ctx,
				$name,
				array(
					'site_id' => 4,
					$field    => $value,
				)
			);
			$this->assertSame( 'approval_required', $out['decision']['decision'], $field );
		}
		foreach ( array( 'http_pass', 'http_user' ) as $field ) {
			$out = $this->call(
				$ctx,
				$name,
				array(
					'site_id' => 4,
					$field    => 'value',
				)
			);
			$this->assertSame( 'confirmation_required', $out['decision']['decision'], $field . ' follows Hands-off' );
		}
		$this->assertSame(
			'approval_required',
			$this->call(
				$ctx,
				$add,
				array(
					'url'           => 'https://new.example',
					'adminpassword' => 'pw',
				)
			)['decision']['decision']
		);
		$this->assertSame( array(), $this->ran( $name ) );
		$this->assertSame( array(), $this->ran( $add ) );
	}

	public function test_custom_dashboard_snippet_replace_is_dashboard_and_never_downgraded() {
		$name = 'mainwp/replace-custom-dashboard-snippet-v1';
		$this->ability(
			$name,
			array(
				'type'              => array( 'type' => 'string' ),
				'code'              => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
				'expected_revision' => array( 'type' => 'string' ),
				'dry_run'           => array( 'type' => 'boolean' ),
				'confirm'           => array( 'type' => 'boolean' ),
			),
			array( 'required' => array( 'type', 'code', 'dry_run' ) )
		);
		$this->entry( $name, 'dashboard', array( 'source' => 'audit' ) );
		$code = '<?php echo "SNIPPET-BODY";';
		$args = array(
			'type'              => 'php',
			'code'              => $code,
			'expected_revision' => 'r1',
		);
		foreach ( array( 'standard', 'strict', 'hands_off' ) as $policy ) {
			$ctx = $this->ctx( array( 'policy' => $policy ) );
			$out = $this->call( $ctx, $name, $args );
			$this->assertSame( 'approval_required', $out['decision']['decision'], $policy );
			$id   = (int) $out['decision']['approval_id'];
			$view = $this->gate->page_view( $id, $this->user_id );
			$text = (string) wp_json_encode( $view['summary'] );
			$this->assertStringContainsString( 'php', $text );
			$this->assertStringContainsString( (string) strlen( $code ), $text );
			$this->assertStringContainsString( hash( 'sha256', $code ), $text );
			$this->assertStringNotContainsString( 'SNIPPET-BODY', $text );
			$this->assertStringNotContainsString( 'SNIPPET-BODY', (string) wp_json_encode( $this->store->get_approval( $id ) ) );
			$this->assertStringNotContainsString( 'SNIPPET-BODY', (string) wp_json_encode( $out['result'] ) );
		}
		$this->assertSame( array(), $this->ran( $name ) );
	}

	public function test_modern_delivery_uses_url_elicitation_only_when_declared() {
		$this->clients->clients['claude-code'] = array(
			'client_id'         => 'claude-code',
			'kind'              => 'bundled',
			'label'             => 'Claude Code',
			'approval_delivery' => 'elicitation',
		);
		$this->clients->clients['cursor']      = array(
			'client_id'         => 'cursor',
			'kind'              => 'bundled',
			'label'             => 'Cursor',
			'approval_delivery' => 'result',
		);
		$this->thing( self::DASH, 'dashboard' );

		$ctx = $this->ctx(
			array(
				'client_id' => 'claude-code',
				'era'       => 'modern',
				'elicit'    => true,
			)
		);
		$out = $this->call( $ctx, self::DASH, array( 'site_ids' => array( 3 ) ) );
		$id  = (int) $out['decision']['approval_id'];
		$this->assertSame( 'input_required', $out['result']['resultType'] );
		$fixture = json_decode(
			str_replace(
				array( '{{approval_url}}', '{{approval_id}}', '{{request_state}}' ),
				array( 'https://dash.example.org/mainwp-oauth/approve?approval=' . $id, (string) $id, $out['result']['requestState'] ),
				(string) file_get_contents( __DIR__ . '/fixtures/mcp/input-required-approval.json' )
			),
			true
		);
		$this->assertSame( $fixture, json_decode( (string) wp_json_encode( $out['result'] ), true ) );

		$no_url = $this->call( $this->again( $ctx, array( 'elicit' => false ) ), self::DASH, array( 'site_ids' => array( 4 ) ) );
		$this->assertArrayNotHasKey( 'resultType', $no_url['result'] );
		$this->assertSame( 'APPROVAL_REQUIRED', $this->structured( $no_url['result'] )['status'] );

		$cursor = $this->call( $this->again( $ctx, array( 'client_id' => 'cursor' ) ), self::DASH, array( 'site_ids' => array( 5 ) ) );
		$this->assertSame( 'APPROVAL_REQUIRED', $this->structured( $cursor['result'] )['status'] );
	}

	public function test_denial_reported_once_then_identical_call_creates_fresh_pending_row() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args = array( 'site_ids' => array( 3 ) );
		$id   = (int) $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->gate->page_decide( $id, $this->user_id, 'deny' );

		$this->assertSame( 'denied', $this->call( $ctx, self::DASH, $args )['decision']['decision'] );
		$this->assertSame( 'closed', $this->store->get_approval( $id )['status'] );
		$next = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $next['decision']['decision'] );
		$this->assertNotEquals( $id, $next['decision']['approval_id'] );
		$this->assertSame( 'pending', $this->store->get_approval( (int) $next['decision']['approval_id'] )['status'] );
		$this->assertSame( array(), $this->ran( self::DASH ) );
	}

	public function test_consumed_row_without_summary_treated_as_no_prior_row() {
		$ctx = $this->ctx();
		$this->thing( self::DASH, 'dashboard' );
		$args = array( 'site_ids' => array( 3 ) );
		$id   = (int) $this->call( $ctx, self::DASH, $args )['decision']['approval_id'];
		$this->approve( $id );
		$this->call( $ctx, self::DASH, $args );
		$row = $this->store->get_approval( $id );
		$this->assertSame( 'consumed', $row['status'] );
		$this->assertNull( $row['result_summary'] );

		$next = $this->call( $ctx, self::DASH, $args );
		$this->assertSame( 'approval_required', $next['decision']['decision'] );
		$this->assertNotEquals( $id, $next['decision']['approval_id'] );
		$this->assertSame( 'APPROVAL_REQUIRED', $this->structured( $next['result'] )['status'] );
		$this->assertCount( 1, $this->executions( self::DASH ) );

		// A row that ran from the page answers with its summary instead.
		$page = (int) $next['decision']['approval_id'];
		$this->approve( $page );
		$this->assertSame( 'ran', $this->gate->page_decide( $page, $this->user_id, 'run' )['outcome'] );
		$after = $this->call( $ctx, self::DASH, $args );
		$this->assertFalse( $after['result']['isError'] );
		$this->assertStringContainsString( 'Already run from the Dashboard', $after['result']['content'][0]['text'] );
		$this->assertCount( 2, $this->approvals( $ctx->grant_id ) );
		$this->assertCount( 2, $this->executions( self::DASH ) );
	}

	public function test_dashboard_tier_runs_no_preview_or_outbound_request_before_click() {
		$ctx  = $this->ctx();
		$http = (object) array( 'count' => 0 );
		add_filter(
			'pre_http_request',
			function ( $pre ) use ( $http ) {
				++$http->count;
				return $pre;
			}
		);
		$fresh = (object) array( 'n' => 0 );
		$token = function () use ( $fresh ) {
			++$fresh->n;
			return array(
				'effect'        => 'remove',
				'preview_token' => 'tok-' . $fresh->n,
			);
		};

		// A stored-plan row whose preview is a separate ability with a native token.
		$this->ability( 'mainwp-test/preview-thing-v1', array( 'site_id' => array( 'type' => 'integer' ) ), array( 'result' => $token ) );
		$this->ability(
			'mainwp-test/remove-thing-v1',
			array(
				'site_id'       => array( 'type' => 'integer' ),
				'preview_token' => array( 'type' => 'string' ),
			)
		);
		$this->entry(
			'mainwp-test/remove-thing-v1',
			'dashboard',
			array(
				'source'         => 'audit',
				'compare_fields' => array( 'effect' ),
				'contract'       => array(
					'source'             => 'audit',
					'preview'            => array(
						'kind'          => 'ability',
						'ability'       => 'mainwp-test/preview-thing-v1',
						'arg_map'       => array( 'site_id' => 'site_id' ),
						'controls'      => array(),
						'capture_token' => 'preview_token',
					),
					'execute'            => array(
						'controls'           => array(),
						'native_token_field' => 'preview_token',
					),
					'sensitive'          => array(),
					'deferred'           => false,
					'credential_bearing' => false,
					'gate_supplied'      => array(),
					'compare_fields'     => array( 'effect' ),
					'tier'               => 'dashboard',
				),
			)
		);
		// A credential-bearing row with a dry-run preview.
		$this->ability(
			'mainwp-test/replace-key-v1',
			array(
				'api_key' => array( 'type' => 'string' ),
				'dry_run' => array( 'type' => 'boolean' ),
				'confirm' => array( 'type' => 'boolean' ),
			)
		);
		$this->entry(
			'mainwp-test/replace-key-v1',
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
			)
		);
		// An uncontracted write.
		$this->ability(
			'mainwp-test/unreviewed-write-v1',
			array(
				'thing'   => array( 'type' => 'string' ),
				'dry_run' => array( 'type' => 'boolean' ),
				'confirm' => array( 'type' => 'boolean' ),
			)
		);
		$this->entry( 'mainwp-test/unreviewed-write-v1', 'dashboard', array( 'source' => 'uncontracted' ) );

		$calls = array(
			'mainwp-test/remove-thing-v1'     => array( 'site_id' => 4 ),
			'mainwp-test/replace-key-v1'      => array( 'api_key' => 'K-1' ),
			'mainwp-test/unreviewed-write-v1' => array( 'thing' => 'x' ),
		);
		$ids   = array();
		foreach ( $calls as $ability => $args ) {
			$ids[ $ability ] = (int) $this->call( $ctx, $ability, $args )['decision']['approval_id'];
			$this->call( $ctx, $ability, $args );
		}
		$this->assertSame( array(), $this->calls, 'no ability, preview ability or dry run before the click' );
		$this->assertSame( 0, $http->count, 'no outbound request before the click' );

		foreach ( $ids as $ability => $id ) {
			$this->assertSame( 'approved', $this->approve( $id )['outcome'], $ability );
		}
		$this->assertCount( 1, $this->ran( 'mainwp-test/preview-thing-v1' ), 'Approve runs the stored-plan row\'s preview once' );
		$this->assertSame( array(), $this->ran( 'mainwp-test/remove-thing-v1' ) );
		$this->assertSame( array(), $this->ran( 'mainwp-test/replace-key-v1' ) );
		$this->assertSame( array(), $this->ran( 'mainwp-test/unreviewed-write-v1' ) );
		$stored = $this->store->get_approval( $ids['mainwp-test/remove-thing-v1'] );
		$this->assertSame( 'tok-1', $this->store->approval_secret( $stored, 'native_preview_token' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', json_decode( (string) $stored['gate_values'], true )['preview_baseline'] );
		$this->assertNull( $this->store->get_approval( $ids['mainwp-test/replace-key-v1'] )['gate_values'] );

		// The credential-bearing preview first runs on its retry, right before execution.
		$this->call( $ctx, 'mainwp-test/replace-key-v1', $calls['mainwp-test/replace-key-v1'] );
		$key = $this->ran( 'mainwp-test/replace-key-v1' );
		$this->assertCount( 2, $key );
		$this->assertTrue( $key[0]['input']['dry_run'] );
		$this->assertFalse( $key[1]['input']['dry_run'] );

		// The uncontracted write's dry run is never invoked.
		$this->call( $ctx, 'mainwp-test/unreviewed-write-v1', $calls['mainwp-test/unreviewed-write-v1'] );
		$unreviewed = $this->ran( 'mainwp-test/unreviewed-write-v1' );
		$this->assertCount( 1, $unreviewed );
		$this->assertFalse( $unreviewed[0]['input']['dry_run'] );
		$this->assertTrue( $unreviewed[0]['input']['confirm'] );
	}

	public function test_strict_update_all_previews_at_click_stores_executable_plan_and_retry_runs_or_stales() {
		$ctx  = $this->ctx( array( 'policy' => 'strict' ) );
		$name = 'mainwp-test/update-all-v1';
		$this->ability(
			$name,
			array(
				'site_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'dry_run'  => array( 'type' => 'boolean' ),
				'confirm'  => array( 'type' => 'boolean' ),
			),
			array( 'closed' => true )
		);
		$this->entry(
			$name,
			'confirm',
			array(
				'resolver'       => 'sites',
				'adapter'        => 'updates',
				'compare_fields' => array( 'would_affect' ),
			)
		);
		$preview                     = (object) array(
			'data' => array(
				'dry_run'      => true,
				'would_affect' => array(
					'sites'   => array(
						array(
							'site_id'   => 3,
							'site_url'  => 'https://three.example',
							'site_name' => 'Three',
							'items'     => array(
								array(
									'type'      => 'plugin',
									'slug'      => 'akismet',
									'name'      => 'Akismet',
									'from'      => '1.0',
									'to'        => '1.1',
									'requested' => false,
									'flags'     => array(
										'core'  => false,
										'major' => false,
									),
								),
							),
							'skipped'   => array(),
						),
						array(
							'site_id'   => 4,
							'site_url'  => 'https://four.example',
							'site_name' => 'Four',
							'items'     => array(
								array(
									'type'      => 'theme',
									'slug'      => 'twentyten',
									'name'      => 'Twenty Ten',
									'from'      => '1.0',
									'to'        => '2.0',
									'requested' => false,
									'flags'     => array(
										'core'  => false,
										'major' => true,
									),
								),
							),
							'skipped'   => array(),
						),
					),
					'by_item' => array(
						array(
							'type'       => 'plugin',
							'slug'       => 'akismet',
							'name'       => 'Akismet',
							'to'         => array( '1.1' ),
							'site_count' => 1,
							'flags'      => array(
								'core'  => false,
								'major' => false,
							),
						),
						array(
							'type'       => 'theme',
							'slug'       => 'twentyten',
							'name'       => 'Twenty Ten',
							'to'         => array( '2.0' ),
							'site_count' => 1,
							'flags'      => array(
								'core'  => false,
								'major' => true,
							),
						),
					),
					'summary' => array(
						'site_count'          => 2,
						'item_count'          => 2,
						'skipped_count'       => 0,
						'all_sites'           => false,
						'queued'              => false,
						'truncated'           => false,
						'has_core'            => false,
						'has_major'           => true,
						'has_unknown_version' => false,
						'requested_not_found' => array(),
					),
				),
				'count'        => 2,
				'warnings'     => array(),
				'errors'       => array(),
			),
		);
		$this->adapter->preview      = function () use ( $preview ) {
			return $preview->data;
		};
		$this->adapter->map[ $name ] = function ( $args, $targets, $data ) {
			if ( null === $data ) {
				return null;
			}
			$entries = array();
			foreach ( $data['would_affect']['sites'] as $site ) {
				$entries[] = array(
					'ability'    => 'mainwp/run-updates-v1',
					'args'       => array(
						'site_ids_or_domains' => array( $site['site_id'] ),
						'types'               => array( $site['items'][0]['type'] ),
						'specific_items'      => array( $site['items'][0]['slug'] ),
						'confirm'             => true,
					),
					'target_ids' => array( $site['site_id'] ),
				);
			}
			return Call_Plan::from_entries( $entries );
		};
		$args                        = array( 'site_ids' => array( 3, 4 ) );

		$id = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$this->assertSame( array(), $this->adapter->previews, 'the first call collects no preview' );
		$this->assertSame( array(), $this->adapter->builds, 'and builds no plan' );
		$this->assertTrue( $this->store->approval_call_plan( $this->store->get_approval( $id ) )->is_pending() );

		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertCount( 1, $this->adapter->previews );
		$this->assertTrue( $this->adapter->previews[0]['arguments']['dry_run'], 'the gate passes the preview controls' );
		$this->assertSame( array( 3, 4 ), $this->adapter->previews[0]['target_ids'] );
		$this->assertCount( 1, $this->adapter->builds );
		$row    = $this->store->get_approval( $id );
		$stored = $this->store->approval_call_plan( $row );
		$this->assertFalse( $stored->is_pending() );
		$this->assertSame( 2, $stored->count() );
		$this->assertSame( 'mainwp/run-updates-v1', $stored->entries()[0]['ability'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', json_decode( (string) $row['gate_values'], true )['preview_baseline'] );

		$run = $this->call( $ctx, $name, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertCount( 2, $this->adapter->previews, 'the retry re-ran the preview' );
		$this->assertCount( 1, $this->adapter->builds, 'nothing is rebuilt' );
		$this->assertCount( 1, $this->jobs->calls );
		$this->assertTrue( $this->jobs->calls[0]['plan']->equals( $stored ), 'the stored plan runs as stored' );

		// A changed preview stales the approved plan and opens a new pending row.
		$id = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$this->approve( $id );
		$preview->data['would_affect']['sites'][1]['items'][0]['to'] = '3.0';
		$preview->data['would_affect']['by_item'][1]['to']           = array( '3.0' );
		$stale = $this->call( $ctx, $name, $args );
		$this->assertSame( 'approval_required', $stale['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $id )['status'] );
		$this->assertSame( 'pending', $this->store->get_approval( (int) $stale['decision']['approval_id'] )['status'] );
		$this->assertCount( 1, $this->jobs->calls );

		// The page can't map a multi-entry updates plan back to its preview: no Run, and the
		// approval stays for the retry.
		$preview->data['would_affect']['sites'][1]['items'][0]['to'] = '2.0';
		$preview->data['would_affect']['by_item'][1]['to']           = array( '2.0' );
		$page = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $page )['outcome'] );
		$this->assertFalse( $this->gate->page_view( $page, $this->user_id )['can_run'], 'no Run it here instead' );
		$this->assertSame( 'refused', $this->gate->page_decide( $page, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( 'approved', $this->store->get_approval( $page )['status'], 'the row is not staled' );
		$this->assertCount( 1, $this->jobs->calls );
	}

	public function test_cleanup_snapshot_selection_requires_fresh_approval() {
		$ability = 'mainwp/cleanup-site-backups-v1';
		$effect = (object) array( 'ids' => array( 1, 2 ) );
		$entry = \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::core_entry( $ability );
		$this->assertIsArray( $entry );
		$this->thing( $ability, 'dashboard', $entry, array( 'result' => function ( $input ) use ( $effect ) { return array( 'would_affect' => array( 'orphaned_task_snapshot_ids' => $effect->ids ) ); } ) );
		foreach ( array( 'page', 'retry' ) as $path ) {
			$ctx = $this->ctx();
			$args = array( 'site_ids' => array( 3 ) );
			$id = (int) $this->call( $ctx, $ability, $args )['decision']['approval_id'];
			$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
			$effect->ids[] = 3 + count( $effect->ids );
			if ( 'page' === $path ) {
				$this->assertSame( 'stale', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
			} else {
				$this->assertSame( 'approval_required', $this->call( $ctx, $ability, $args )['decision']['decision'] );
			}
			$this->assertSame( 'stale', $this->store->get_approval( $id )['status'] );
			$this->assertSame( array(), $this->executions( $ability ) );
		}
	}

	public function test_page_rechecks_tool_and_category_switches() {
		foreach ( array( 'tool', 'category' ) as $switch ) {
			$this->thing( self::DASH, 'dashboard' );
			$id = (int) $this->call( $this->ctx(), self::DASH, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
			$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
			if ( 'tool' === $switch ) {
				\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_tool_enabled( \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::tool_name( self::DASH ), false );
			} else {
				\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', false );
			}
			$this->assertSame( 'refused', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'], $switch );
			$this->assertSame( array(), $this->executor->calls );
			\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_tool_enabled( \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::tool_name( self::DASH ), true );
			\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', true );
		}
	}

	public function test_approve_rechecks_tool_and_category_switches() {
		foreach ( array( 'tool', 'category' ) as $switch ) {
			$this->thing( self::DASH, 'dashboard' );
			$id = (int) $this->call( $this->ctx(), self::DASH, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
			if ( 'tool' === $switch ) {
				\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_tool_enabled( \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::tool_name( self::DASH ), false );
			} else {
				\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', false );
			}
			$this->assertSame( array( 'refused', 'not_available' ), array_values( array_intersect_key( $this->gate->page_decide( $id, $this->user_id, 'approve' ), array_flip( array( 'outcome', 'reason' ) ) ) ), $switch );
			$this->assertSame( 'pending', $this->store->get_approval( $id )['status'], $switch );
			\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_tool_enabled( \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::tool_name( self::DASH ), true );
			\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', true );
		}
	}

	public function test_page_refuses_unregistered_ability_without_notice() {
		$this->thing( self::DASH, 'dashboard' );
		$id = (int) $this->call( $this->ctx(), self::DASH, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertTrue( $this->gate->page_view( $id, $this->user_id )['can_run'] );
		$row = $this->store->get_approval( $id );
		$grant = $this->store->get_grant( (int) $row['grant_id'] );
		wp_unregister_ability( self::DASH );
		$notices = array();
		$capture = function ( $function, $message ) use ( &$notices ) { $notices[] = array( $function, $message ); };
		add_action( 'doing_it_wrong_run', $capture, 10, 2 );
		try {
			// Registration can disappear after the page offered this approval for execution.
			$run = new \ReflectionMethod( $this->gate, 'run_row' );
			$run->setAccessible( true );
			$result = $run->invoke( $this->gate, $row, $grant, $this->user_id );
			$this->assertSame( 'refused', $result['outcome'] );
			$this->assertSame( 'not_available', $result['reason'] );
			$this->assertSame( array(), $this->executor->calls );
			$this->assertSame( array(), $notices );
		} finally {
			remove_action( 'doing_it_wrong_run', $capture, 10 );
		}
	}

	public function test_empty_entities_keep_roster_and_freshness() {
		foreach ( array( 'client', 'tag' ) as $type ) {
			foreach ( array( 'rename', 'delete' ) as $action ) {
				$ctx = $this->ctx();
				$ability = 'mainwp-test/' . $action . '-' . $type . '-v1';
				$this->thing( $ability, 'dashboard', array( 'resolver' => $type ), array( 'props' => array( $type . '_id' => array( 'type' => 'integer' ) ) ) );
				$members = (object) array( 'ids' => array() );
				$this->resolver->map[ $ability ] = function () use ( $members, $type ) { return array( 'site_ids' => $members->ids, 'entity' => array( 'type' => $type, 'id' => 9 ) ); };
				$adapter = new \MainWP\Dashboard\Remote_MCP\MCP_Execution_Adapter( $this->services );
				$this->adapter->map[ $ability ] = function ( $args, $targets ) use ( $adapter, $ability, $ctx ) { return $adapter->build( $ctx, $ability, $args, $targets, null ); };
				$args = array( $type . '_id' => 9 );
				$first = $this->call( $ctx, $ability, $args )['decision'];
				$this->assertSame( 'approval_required', $first['decision'], $ability );
				$id = (int) $first['approval_id'];
				$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
				$row = $this->store->get_approval( $id );
				$this->assertSame( '[]', $row['target_ids'] );
				$this->assertSame( array(), $this->store->approval_call_plan( $row )->entries()[0]['target_ids'] );
				$this->assertSame( 'ran', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
				$ctx = $this->ctx();
				$id = (int) $this->call( $ctx, $ability, $args )['decision']['approval_id'];
				$this->approve( $id );
				$this->assertSame( 'run', $this->call( $ctx, $ability, $args )['decision']['decision'] );
				$ctx = $this->ctx();
				$id = (int) $this->call( $ctx, $ability, $args )['decision']['approval_id'];
				$this->approve( $id );
				$members->ids = array( 3 );
				$this->assertSame( 'stale', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
			}
		}
		$this->thing( self::DASH, 'dashboard' );
		$this->assertSame( 'no_targets', $this->call( $this->ctx(), self::DASH, array( 'site_ids' => array() ) )['decision']['reason'] );
	}

}
