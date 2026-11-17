<?php
/**
 * Remote MCP control contract tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Control_Contract
 */
class Test_MainWP_MCP_Control_Contract extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Boolean schema property.
	 *
	 * @return array
	 */
	private function bool(): array {
		return array( 'type' => 'boolean' );
	}

	/**
	 * An audited contract with a native preview token.
	 *
	 * @param array $o Overrides: `kind`, `ability`, `arg_map`, `preview_controls`, `execute_controls`, `tier`.
	 * @return array
	 */
	private function token_contract( array $o = array() ): array {
		return array(
			'source'             => 'audit',
			'preview'            => array(
				'kind'          => $o['kind'] ?? 'dry_run',
				'ability'       => $o['ability'] ?? null,
				'arg_map'       => $o['arg_map'] ?? null,
				'controls'      => $o['preview_controls'] ?? array( 'dry_run' => true ),
				'capture_token' => 'preview_token',
			),
			'execute'            => array(
				'controls'           => $o['execute_controls'] ?? array( 'dry_run' => false ),
				'native_token_field' => 'preview_token',
			),
			'sensitive'          => array(),
			'deferred'           => false,
			'credential_bearing' => false,
			'gate_supplied'      => array(),
			'compare_fields'     => array( 'effect' ),
			'tier'               => $o['tier'] ?? 'confirm',
		);
	}

	/**
	 * A result callback that mints a fresh token on every preview.
	 *
	 * @param \stdClass $state `n` counter and `effect`.
	 * @return callable
	 */
	private function minting( \stdClass $state ): callable {
		return function ( $input ) use ( $state ) {
			if ( ! empty( $input['dry_run'] ) ) {
				++$state->n;
				return array(
					'effect'        => $state->effect,
					'preview_token' => 'tok-' . $state->n,
					'fingerprint'   => wp_generate_uuid4(),
				);
			}
			return array( 'done' => true );
		};
	}

	/**
	 * Confirm a preview and run it.
	 *
	 * @param mixed  $ctx     Context.
	 * @param string $ability Ability.
	 * @param array  $args    Arguments.
	 * @return array
	 */
	private function confirm_and_run( $ctx, string $ability, array $args ): array {
		$first = $this->call( $ctx, $ability, $args )['result'];
		$this->assertIsArray( $first, 'the native preview must succeed before a confirmation is offered' );
		$token = $this->structured( $first )['confirmation_token'];
		return $this->call(
			$ctx,
			$ability,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
	}

	public function test_client_confirm_and_dry_run_never_reach_any_tier() {
		$ctx    = $this->ctx();
		$props  = array(
			'note'    => array( 'type' => 'string' ),
			'confirm' => $this->bool(),
			'dry_run' => $this->bool(),
		);
		$client = array(
			'note'          => 'n',
			'confirm'       => false,
			'dry_run'       => true,
			'preview_token' => 'client-token',
		);
		foreach ( array( 'read', 'write', 'confirm', 'dashboard' ) as $tier ) {
			$name = 'mainwp-test/' . $tier . '-thing-v1';
			$this->ability( $name, $props );
			$this->entry( $name, $tier );
			if ( 'confirm' === $tier ) {
				$this->confirm_and_run( $ctx, $name, $client );
			} elseif ( 'dashboard' === $tier ) {
				$this->approve( (int) $this->call( $ctx, $name, $client )['decision']['approval_id'] );
				$this->call( $ctx, $name, $client );
			} else {
				$this->call( $ctx, $name, $client );
			}
			$calls = $this->ran( $name );
			$this->assertNotEmpty( $calls, $tier );
			foreach ( $calls as $call ) {
				$this->assertArrayNotHasKey( 'preview_token', $call['input'], $tier );
			}
			$last = end( $calls )['input'];
			if ( 'read' === $tier ) {
				$this->assertArrayNotHasKey( 'confirm', $last );
				$this->assertArrayNotHasKey( 'dry_run', $last );
			} else {
				$this->assertFalse( $last['dry_run'], $tier . ': the client dry_run never reaches the execution' );
				$this->assertTrue( $last['confirm'], $tier . ': the client confirm never reaches the execution' );
			}
			if ( 'confirm' === $tier ) {
				$this->assertTrue( $calls[0]['input']['dry_run'] );
				$this->assertArrayNotHasKey( 'confirm', $calls[0]['input'], 'confirm is optional, so the preview sends none' );
			}
		}
	}

	public function test_confirm_injected_only_when_declared() {
		$ctx = $this->ctx();
		$this->ability( 'mainwp-test/dry-only-v1', array( 'dry_run' => $this->bool() ) );
		$this->entry( 'mainwp-test/dry-only-v1', 'confirm' );
		$this->confirm_and_run( $ctx, 'mainwp-test/dry-only-v1', array( 'confirm' => true ) );
		$calls = $this->ran( 'mainwp-test/dry-only-v1' );
		$this->assertSame( array( 'dry_run' => true ), $calls[0]['input'] );
		$this->assertSame( array( 'dry_run' => false ), $calls[1]['input'] );

		$this->ability( 'mainwp-test/confirm-only-v1', array( 'confirm' => $this->bool() ) );
		$this->entry( 'mainwp-test/confirm-only-v1', 'confirm' );
		$this->confirm_and_run( $ctx, 'mainwp-test/confirm-only-v1', array() );
		$calls = $this->ran( 'mainwp-test/confirm-only-v1' );
		$this->assertCount( 1, $calls, 'no dry_run declared: the preview comes from the plan' );
		$this->assertSame( array( 'confirm' => true ), $calls[0]['input'] );

		$this->ability(
			'mainwp-test/confirm-required-v1',
			array(
				'confirm' => $this->bool(),
				'dry_run' => $this->bool(),
			),
			array( 'required' => array( 'confirm' ) )
		);
		$this->entry( 'mainwp-test/confirm-required-v1', 'confirm' );
		$this->confirm_and_run( $ctx, 'mainwp-test/confirm-required-v1', array() );
		$calls = $this->ran( 'mainwp-test/confirm-required-v1' );
		$this->assertSame(
			array(
				'confirm' => false,
				'dry_run' => true,
			),
			$this->sorted( $calls[0]['input'] )
		);
		$this->assertSame(
			array(
				'confirm' => true,
				'dry_run' => false,
			),
			$this->sorted( $calls[1]['input'] )
		);

		$this->ability( 'mainwp-test/plain-write-v1', array( 'note' => array( 'type' => 'string' ) ) );
		$this->entry( 'mainwp-test/plain-write-v1', 'write' );
		$this->call(
			$this->ctx(),
			'mainwp-test/plain-write-v1',
			array(
				'note'    => 'x',
				'confirm' => true,
			)
		);
		$this->assertSame( array( 'note' => 'x' ), $this->ran( 'mainwp-test/plain-write-v1' )[0]['input'] );
	}

	/**
	 * Keys sorted.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	private function sorted( array $values ): array {
		ksort( $values );
		return $values;
	}

	public function test_closed_schema_ability_runs_after_dashboard_approval() {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/add-thing-v1';
		$this->ability(
			$name,
			array(
				'site_id' => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
			),
			array( 'closed' => true )
		);
		$this->entry( $name, 'dashboard' );
		$args = array(
			'site_id'        => 4,
			'name'           => 'n',
			'confirm'        => true,
			'dry_run'        => true,
			'user_confirmed' => true,
			'extra'          => 'x',
		);
		$this->approve( (int) $this->call( $ctx, $name, $args )['decision']['approval_id'] );
		$run = $this->call( $ctx, $name, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertFalse( $run['result']['isError'], 'a closed schema never receives an undeclared key' );
		$this->assertSame(
			array(
				'name'    => 'n',
				'site_id' => 4,
			),
			$this->sorted( $this->ran( $name )[0]['input'] )
		);
	}

	public function test_native_preview_token_passed_through_on_execute() {
		$ctx   = $this->ctx();
		$state = (object) array(
			'n'      => 0,
			'effect' => 'apply',
		);
		$name  = 'mainwp-test/assign-thing-v1';
		$this->ability(
			$name,
			array(
				'site_id'       => array( 'type' => 'integer' ),
				'dry_run'       => $this->bool(),
				'confirm'       => $this->bool(),
				'preview_token' => array( 'type' => 'string' ),
			),
			array(
				'result' => function ( $input ) use ( $state ) {
					if ( ! empty( $input['dry_run'] ) && array_key_exists( 'confirm', $input ) ) {
						return new \WP_Error( 'conflicting_controls', 'Preview received execution controls' );
					}
					return call_user_func( $this->minting( $state ), $input );
				},
			)
		);
		$this->entry(
			$name,
			'confirm',
			array(
				'source'         => 'audit',
				'contract'       => $this->token_contract(
					array(
						'execute_controls' => array(
							'dry_run' => false,
							'confirm' => true,
						),
					)
				),
				'compare_fields' => array( 'effect' ),
			)
		);
		$run = $this->confirm_and_run(
			$ctx,
			$name,
			array(
				'site_id'       => 4,
				'preview_token' => 'client-token',
			)
		);
		$this->assertSame( 'run', $run['decision']['decision'] );
		$calls = $this->ran( $name );
		$this->assertCount( 3, $calls, 'preview, the refresh at execution, then the execution' );
		$this->assertArrayNotHasKey( 'preview_token', $calls[0]['input'] );
		$this->assertSame( 'tok-2', $calls[2]['input']['preview_token'], 'the fresh token, never the client one' );
		$this->assertFalse( $calls[2]['input']['dry_run'] );
		$this->assertTrue( $calls[2]['input']['confirm'] );
		$this->assertArrayNotHasKey( 'confirm', $calls[1]['input'], 'refresh applies exactly the preview controls' );
	}

	public function test_team_control_preview_and_execute_controls_match_contract() {
		$ctx   = $this->ctx();
		$state = (object) array(
			'n'      => 0,
			'effect' => 'role',
		);
		$name  = 'mainwp-test/replace-team-control-role-v1';
		$this->ability(
			$name,
			array(
				'role_id'       => array( 'type' => 'integer' ),
				'dry_run'       => $this->bool(),
				'preview_token' => array( 'type' => array( 'string', 'null' ) ),
			),
			array(
				'required' => array( 'dry_run', 'preview_token' ),
				'result'   => $this->minting( $state ),
			)
		);
		$this->entry(
			$name,
			'confirm',
			array(
				'source'   => 'audit',
				'contract' => $this->token_contract(
					array(
						'preview_controls' => array(
							'dry_run'       => true,
							'preview_token' => null,
						),
					)
				),
			)
		);
		$this->confirm_and_run( $ctx, $name, array( 'role_id' => 2 ) );
		$calls = $this->ran( $name );
		$this->assertSame(
			array(
				'dry_run'       => true,
				'preview_token' => null,
				'role_id'       => 2,
			),
			$this->sorted( $calls[0]['input'] ),
			'exact preview controls, null included'
		);
		$this->assertSame(
			array(
				'dry_run'       => false,
				'preview_token' => 'tok-2',
				'role_id'       => 2,
			),
			$this->sorted( end( $calls )['input'] )
		);
	}

	public function test_required_dry_run_false_sent_on_execute() {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/required-dry-run-v1';
		$this->ability(
			$name,
			array(
				'site_id' => array( 'type' => 'integer' ),
				'dry_run' => $this->bool(),
			),
			array( 'required' => array( 'site_id', 'dry_run' ) )
		);
		$this->entry( $name, 'dashboard' );
		$args = array( 'site_id' => 4 );
		$this->approve( (int) $this->call( $ctx, $name, $args )['decision']['approval_id'] );
		$run = $this->call( $ctx, $name, $args );
		$this->assertFalse( $run['result']['isError'] );
		$last = end( $this->calls[ $name ] )['input'];
		$this->assertArrayHasKey( 'dry_run', $last );
		$this->assertFalse( $last['dry_run'] );
	}

	public function test_separate_preview_ability_token_captured_and_supplied() {
		$ctx     = $this->ctx();
		$state   = (object) array( 'n' => 0 );
		$preview = 'mainwp-test/preview-fathom-delete-v1';
		$name    = 'mainwp-test/delete-fathom-site-v1';
		$this->ability(
			$preview,
			array( 'site' => array( 'type' => 'integer' ) ),
			array(
				'result' => function () use ( $state ) {
					++$state->n;
					return array(
						'effect'        => 'delete',
						'preview_token' => 'fathom-' . $state->n,
					);
				},
			)
		);
		$this->ability(
			$name,
			array(
				'site_id'       => array( 'type' => 'integer' ),
				'preview_token' => array( 'type' => 'string' ),
			)
		);
		$this->entry(
			$name,
			'confirm',
			array(
				'source'   => 'audit',
				'contract' => $this->token_contract(
					array(
						'kind'             => 'ability',
						'ability'          => $preview,
						'arg_map'          => array( 'site_id' => 'site' ),
						'preview_controls' => array(),
						'execute_controls' => array(),
					)
				),
			)
		);
		$token = $this->structured( $this->call( $ctx, $name, array( 'site_id' => 4 ) )['result'] )['confirmation_token'];
		$this->assertSame( array( array( 'site' => 4 ) ), array_column( $this->ran( $preview ), 'input' ), 'mapped arguments' );
		$this->assertSame( array(), $this->ran( $name ) );

		$this->call(
			$ctx,
			$name,
			array(
				'site_id'            => 4,
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertCount( 2, $this->ran( $preview ) );
		$this->assertSame(
			array(
				'preview_token' => 'fathom-2',
				'site_id'       => 4,
			),
			$this->sorted( $this->ran( $name )[0]['input'] )
		);
	}

	public function test_gate_supplies_request_ref_and_retry_matches_approved_row() {
		$ctx   = $this->ctx();
		$name  = 'mainwp-test/request-gsc-refresh-v1';
		$props = array(
			'site_id'     => array( 'type' => 'integer' ),
			'request_ref' => array( 'type' => 'string' ),
		);
		$this->ability( $name, $props, array( 'required' => array( 'site_id', 'request_ref' ) ) );
		$this->entry(
			$name,
			'dashboard',
			array(
				'source'        => 'uncontracted',
				'gate_supplied' => array( 'request_ref' ),
			)
		);
		$first = $this->call(
			$ctx,
			$name,
			array(
				'site_id'     => 4,
				'request_ref' => 'model-chosen-1',
			)
		);
		$id    = (int) $first['decision']['approval_id'];
		$ref   = json_decode( (string) $this->store->get_approval( $id )['gate_values'], true )['fields']['request_ref'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ref );

		$retry = $this->call(
			$ctx,
			$name,
			array(
				'site_id'     => 4,
				'request_ref' => 'model-chosen-2',
			)
		);
		$this->assertSame( $id, (int) $retry['decision']['approval_id'], 'the model-chosen ref is outside the digest' );
		$this->approve( $id );
		$run = $this->call( $ctx, $name, array( 'site_id' => 4 ) );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertSame( $ref, $this->ran( $name )[0]['input']['request_ref'] );

		// The confirm flow keeps one ref per row too.
		$this->ability( 'mainwp-test/replace-gsc-mapping-v1', $props + array( 'dry_run' => $this->bool() ) );
		$this->entry(
			'mainwp-test/replace-gsc-mapping-v1',
			'confirm',
			array(
				'source'        => 'audit',
				'gate_supplied' => array( 'request_ref' ),
			)
		);
		$this->confirm_and_run(
			$ctx,
			'mainwp-test/replace-gsc-mapping-v1',
			array(
				'site_id'     => 4,
				'request_ref' => 'model',
			)
		);
		$calls = $this->ran( 'mainwp-test/replace-gsc-mapping-v1' );
		$this->assertNotSame( 'model', $calls[0]['input']['request_ref'] );
		$this->assertSame( $calls[0]['input']['request_ref'], $calls[1]['input']['request_ref'], 'preview and execution send the same ref' );
	}

	public function test_native_preview_rerun_after_dashboard_approval_compares_declared_fields() {
		$ctx   = $this->ctx();
		$state = (object) array(
			'n'      => 0,
			'effect' => 'connect',
		);
		$name  = 'mainwp-test/replace-matomo-assignment-v1';
		$this->ability(
			$name,
			array(
				'site_id'       => array( 'type' => 'integer' ),
				'dry_run'       => $this->bool(),
				'preview_token' => array( 'type' => 'string' ),
			),
			array( 'result' => $this->minting( $state ) )
		);
		$this->entry(
			$name,
			'dashboard',
			array(
				'source'         => 'audit',
				'contract'       => $this->token_contract( array( 'tier' => 'dashboard' ) ),
				'compare_fields' => array( 'effect' ),
			)
		);
		$args = array( 'site_id' => 4 );
		$id   = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$this->approve( $id );
		$row      = $this->store->get_approval( $id );
		$baseline = json_decode( (string) $row['gate_values'], true )['preview_baseline'];
		$this->assertSame( $baseline, $this->store->approval_call_plan( $row )->entries()[0]['preview_baseline'], 'the token-bound entry carries the click-time baseline' );

		// After the token's 300 s life the effect is unchanged: renewed, not stale.
		$this->tick( 400 );
		$run = $this->call( $ctx, $name, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$last = end( $this->calls[ $name ] )['input'];
		$this->assertSame( 'tok-2', $last['preview_token'] );
		$this->assertFalse( $last['dry_run'] );

		// A changed effect stales the row.
		$id = (int) $this->call( $ctx, $name, $args )['decision']['approval_id'];
		$this->approve( $id );
		$state->effect = 'disconnect';
		$stale         = $this->call( $ctx, $name, $args );
		$this->assertSame( 'approval_required', $stale['decision']['decision'] );
		$this->assertSame( 'stale', $this->store->get_approval( $id )['status'] );
		$this->assertCount( 1, $this->executor->calls );

		// A plan-less confirm row under Hands-off keeps its first-call baseline the same way.
		$hands = $this->ctx( array( 'policy' => 'hands_off' ) );
		$cred  = 'mainwp-test/replace-termageddon-credential-v1';
		$this->ability(
			$cred,
			array(
				'api_key'       => array( 'type' => 'string' ),
				'dry_run'       => $this->bool(),
				'preview_token' => array( 'type' => 'string' ),
			),
			array( 'result' => $this->minting( $state ) )
		);
		$this->entry(
			$cred,
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
				'contract'           => $this->token_contract( array( 'tier' => 'dashboard' ) ),
				'compare_fields'     => array( 'effect' ),
			)
		);
		$key   = array( 'api_key' => 'K' );
		$first = $this->call( $hands, $cred, $key );
		$this->assertSame( 'confirmation_required', $first['decision']['decision'] );
		$plan_less = $this->store->get_approval( (int) $first['decision']['approval_id'] );
		$this->assertNull( $plan_less['call_plan'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', json_decode( (string) $plan_less['gate_values'], true )['preview_baseline'] );
		$state->effect = 'changed';
		$token         = $this->structured( $first['result'] )['confirmation_token'];
		$changed       = $this->call(
			$hands,
			$cred,
			$key + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertNotSame( 'run', $changed['decision']['decision'] );
		$this->assertCount( 1, $this->executor->calls );
	}

	public function test_projection_forwards_only_declared_keys() {
		$ctx   = $this->ctx();
		$props = array(
			'a' => array( 'type' => 'string' ),
			'b' => array( 'type' => 'integer' ),
		);
		$this->ability( 'mainwp-test/open-write-v1', $props );
		$this->entry( 'mainwp-test/open-write-v1', 'write' );
		$sent = array(
			'a'              => 'x',
			'b'              => 2,
			'c'              => 'undeclared',
			'site_ids'       => array( 1 ),
			'user_confirmed' => true,
		);
		$this->call( $ctx, 'mainwp-test/open-write-v1', $sent );
		$this->assertSame(
			array(
				'a' => 'x',
				'b' => 2,
			),
			$this->ran( 'mainwp-test/open-write-v1' )[0]['input']
		);

		$this->ability( 'mainwp-test/open-dash-v1', $props );
		$this->entry( 'mainwp-test/open-dash-v1', 'dashboard' );
		$row  = $this->store->get_approval( (int) $this->call( $ctx, 'mainwp-test/open-dash-v1', $sent )['decision']['approval_id'] );
		$args = $this->store->approval_call_plan( $row )->entries()[0]['args'];
		$this->assertSame( array( 'a', 'b' ), array_keys( $args ), 'the pending plan holds declared keys only' );
	}

	public function test_contract_control_names_never_come_from_the_client() {
		$project = new \ReflectionMethod( \MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate::class, 'project' );
		$project->setAccessible( true );
		$projected = $project->invoke(
			null,
			array(
				'target' => 'a',
				'commit' => true,
			),
			array(
				'target' => array( 'type' => 'string' ),
				'commit' => array( 'type' => 'boolean' ),
			),
			array(),
			null,
			array( 'commit' )
		);
		$this->assertSame( array( 'target' => 'a' ), $projected, 'a contract control is set by the gate, never by the client' );
	}

	public function test_separate_preview_controls_do_not_reserve_execution_arguments() {
		$names = new \ReflectionMethod( \MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate::class, 'contract_control_names' );
		$names->setAccessible( true );
		$separate = array(
			'preview' => array(
				'ability'  => 'acme/preview-v1',
				'controls' => array( 'format' => 'summary' ),
			),
			'execute' => array( 'controls' => array( 'commit' => true ) ),
		);
		$this->assertSame( array( 'commit' ), $names->invoke( null, $separate ), 'a separate preview ability has its own arguments' );
		$same = array(
			'preview' => array(
				'ability'  => null,
				'controls' => array( 'dry_run' => true ),
			),
			'execute' => array( 'controls' => array( 'dry_run' => false ) ),
		);
		$this->assertSame( array( 'dry_run' ), $names->invoke( null, $same ) );
	}
}
