<?php
/**
 * Remote MCP extension contract and uncontracted-path tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Control_Contracts;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Extension_Contracts
 */
class Test_MainWP_MCP_Extension_Contracts extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const UNREVIEWED = 'acme-ext/purge-thing-v1';

	/**
	 * A declared contract as an extension would publish it.
	 *
	 * @param array $o Overrides.
	 * @return array
	 */
	private function declared( array $o = array() ): array {
		return array_merge(
			array(
				'tier'           => 'confirm',
				'preview'        => array(
					'kind'     => 'dry_run',
					'controls' => array( 'dry_run' => true ),
				),
				'execute'        => array( 'controls' => array( 'dry_run' => false ) ),
				'resolver'       => 'site',
				'adapter'        => 'atomic',
				'sensitive'      => array(),
				'gate_supplied'  => array(),
				'compare_fields' => array(),
				'deferred'       => false,
			),
			$o
		);
	}

	/**
	 * Publish contracts through the extension filter.
	 *
	 * @param array $contracts Ability => contract.
	 */
	private function publish( array $contracts ): void {
		add_filter(
			'mainwp_remote_mcp_extension_contracts',
			function ( $existing ) use ( $contracts ) {
				return array_merge( (array) $existing, $contracts );
			}
		);
	}

	/**
	 * An uncontracted extension write with valid annotations.
	 *
	 * @param array $props Extra props.
	 * @param array $o     Ability options.
	 */
	private function unreviewed( array $props = array(), array $o = array() ): void {
		$this->ability(
			self::UNREVIEWED,
			$props + array(
				'site_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'secret'   => array( 'type' => 'string' ),
				'label'    => array( 'type' => 'string' ),
				'dry_run'  => array( 'type' => 'boolean' ),
				'confirm'  => array( 'type' => 'boolean' ),
			),
			$o + array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
				),
			)
		);
		$this->entry(
			self::UNREVIEWED,
			'dashboard',
			array(
				'source'   => 'uncontracted',
				'resolver' => 'sites',
				'adapter'  => 'atomic',
			)
		);
	}

	public function test_native_token_contract_without_compare_fields_is_refused() {
		$name = 'mainwp-test/native-token-no-compare-v1';
		$this->ability(
			$name,
			array(
				'dry_run'       => array( 'type' => 'boolean' ),
				'preview_token' => array( 'type' => 'string' ),
			)
		);
		$contracts = new \MainWP\Dashboard\Remote_MCP\MCP_Control_Contracts( $this->services );
		$contract  = function ( $compare ) {
			$c = array(
				'source'             => 'declared',
				'preview'            => array(
					'kind'          => 'dry_run',
					'ability'       => null,
					'arg_map'       => null,
					'controls'      => array( 'dry_run' => true ),
					'capture_token' => 'preview_token',
				),
				'execute'            => array(
					'controls'           => array( 'dry_run' => false ),
					'native_token_field' => 'preview_token',
				),
				'sensitive'          => array(),
				'deferred'           => false,
				'credential_bearing' => false,
				'gate_supplied'      => array(),
				'tier'               => 'dashboard',
			);
			if ( null !== $compare ) {
				$c['compare_fields'] = $compare;
			}
			return array(
				'source'   => 'declared',
				'contract' => $c,
			);
		};
		// Without compare fields, a renewed native token could carry an effect the user never saw.
		$this->assertNull( $contracts->from_entry( $name, $contract( null ) ), 'omitted compare_fields' );
		$this->assertNull( $contracts->from_entry( $name, $contract( array() ) ), 'empty compare_fields' );
		$this->assertIsArray( $contracts->from_entry( $name, $contract( array( 'effect' ) ) ), 'declared compare_fields' );
	}

	public function test_declared_contract_cannot_claim_write_tier() {
		$contracts = new MCP_Control_Contracts( $this->services );
		$this->publish(
			array(
				'acme-ext/write-claim-v1'   => $this->declared( array( 'tier' => 'write' ) ),
				'acme-ext/read-claim-v1'    => $this->declared( array( 'tier' => 'read' ) ),
				'acme-ext/confirm-v1'       => $this->declared(),
				'acme-ext/dashboard-v1'     => $this->declared( array( 'tier' => 'dashboard' ) ),
				'acme-ext/no-execute-v1'    => array_diff_key( $this->declared(), array( 'execute' => true ) ),
				'acme-ext/bad-adapter-v1'   => $this->declared( array( 'adapter' => 'queue_it' ) ),
				'acme-ext/bad-sensitive-v1' => $this->declared( array( 'sensitive' => 'secret' ) ),
			)
		);
		$this->assertNull( $contracts->declared_contract( 'acme-ext/write-claim-v1' ) );
		$this->assertNull( $contracts->declared_contract( 'acme-ext/read-claim-v1' ) );
		$this->assertNull( $contracts->declared_contract( 'acme-ext/no-execute-v1' ) );
		$this->assertNull( $contracts->declared_contract( 'acme-ext/bad-adapter-v1' ) );
		$this->assertNull( $contracts->declared_contract( 'acme-ext/bad-sensitive-v1' ) );
		$this->assertNull( $contracts->declared_contract( 'acme-ext/never-declared-v1' ) );
		$confirm = $contracts->declared_contract( 'acme-ext/confirm-v1' );
		$this->assertSame( 'confirm', $confirm['tier'] );
		$this->assertSame( 'declared', $confirm['source'] );
		$this->assertSame( array( 'dry_run' => true ), $confirm['preview']['controls'] );
		$this->assertSame( 'dashboard', $contracts->declared_contract( 'acme-ext/dashboard-v1' )['tier'] );
	}

	public function test_deferred_contract_requires_authorization_active_check() {
		$this->assertTrue( function_exists( 'mainwp_remote_mcp_authorization_active' ), 'the public check exists once the module boots' );
		$contracts = new MCP_Control_Contracts( $this->services );
		$this->publish( array( 'acme-ext/queue-thing-v1' => $this->declared( array( 'deferred' => true ) ) ) );
		$declared = $contracts->declared_contract( 'acme-ext/queue-thing-v1' );
		$this->assertTrue( $declared['deferred'] );

		// The extension reads the grant and version while the entry runs, as the tool executor sets them.
		$seen = (object) array( 'context' => null );
		$this->ability(
			'acme-ext/queue-thing-v1',
			array(
				'site_id' => array( 'type' => 'integer' ),
				'dry_run' => array( 'type' => 'boolean' ),
			),
			array(
				'result' => function ( $input ) use ( $seen ) {
					if ( empty( $input['dry_run'] ) ) {
						$seen->context = apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null );
					}
					return array( 'queued' => 1 );
				},
			)
		);
		$this->entry(
			'acme-ext/queue-thing-v1',
			'confirm',
			array(
				'source'   => 'declared',
				'contract' => $declared,
				'deferred' => true,
			)
		);
		$ctx                            = $this->ctx();
		$this->executor->deferred_store = $this->store;
		$token                          = $this->structured( $this->call( $ctx, 'acme-ext/queue-thing-v1', array( 'site_id' => 4 ) )['result'] )['confirmation_token'];
		$this->call(
			$ctx,
			'acme-ext/queue-thing-v1',
			array(
				'site_id'            => 4,
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame(
			array(
				'grant_id'       => $ctx->grant_id,
				'policy_version' => $ctx->policy_version,
			),
			$seen->context
		);
		$this->assertNull( apply_filters( Remote_MCP_Events::DEFERRED_CONTEXT, null ), 'only while the entry runs' );

		$this->assertTrue( mainwp_remote_mcp_authorization_active( $ctx->grant_id, $ctx->policy_version ) );
		$this->assertFalse( mainwp_remote_mcp_authorization_active( $ctx->grant_id, $ctx->policy_version + 1 ) );

		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$this->assertFalse( mainwp_remote_mcp_authorization_active( $ctx->grant_id, $ctx->policy_version ), 'the connector off stops queued work' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );

		// Narrowed to View only: false, at the old version and at the new one.
		$this->assertTrue( $this->store->narrow_grant_scope( $ctx->grant_id, 'mainwp:read offline_access' ) );
		$this->assertFalse( mainwp_remote_mcp_authorization_active( $ctx->grant_id, $ctx->policy_version ) );
		$narrowed = (int) $this->store->get_grant( $ctx->grant_id )['policy_version'];
		$this->assertTrue( $this->store->authorization_active( $ctx->grant_id, $narrowed ), 'the store side alone passes at the new version' );
		$this->assertFalse( mainwp_remote_mcp_authorization_active( $ctx->grant_id, $narrowed ), 'the scope check stops it' );
	}

	public function test_uncontracted_extension_write_is_dashboard_even_when_nondestructive() {
		$ctx = $this->ctx();
		$this->unreviewed();
		// A tier table claiming a weaker tier must not lower an unreviewed write.
		$this->registry->entries[ self::UNREVIEWED ]['tier'] = 'write';
		$out = $this->call(
			$ctx,
			self::UNREVIEWED,
			array(
				'site_ids' => array( 3 ),
				'label'    => 'x',
			)
		);
		$this->assertSame( 'approval_required', $out['decision']['decision'] );
		$this->assertSame( 'dashboard', $this->store->get_approval( (int) $out['decision']['approval_id'] )['tier'] );
		$this->assertStringContainsString( 'MainWP hasn\'t reviewed which sites this changes', (string) wp_json_encode( $this->gate->page_view( (int) $out['decision']['approval_id'], $this->user_id )['summary'] ) );
		$this->assertSame( 'uncontracted', ( new MCP_Control_Contracts( $this->services ) )->contract_for( $ctx, self::UNREVIEWED )['source'] );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ) );
	}

	public function test_uncontracted_extension_write_is_confirm_only_under_hands_off() {
		$ctx = $this->ctx( array( 'policy' => 'hands_off' ) );
		$this->unreviewed();
		$args  = array(
			'site_ids' => array( 3 ),
			'secret'   => 'UNREVIEWED-SECRET',
			'label'    => 'shown',
		);
		$first = $this->call( $ctx, self::UNREVIEWED, $args );
		$this->assertSame( 'confirmation_required', $first['decision']['decision'] );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ), 'zero calls at the first call, dry_run included' );
		$preview = (string) wp_json_encode( $this->structured( $first['result'] )['preview'] );
		$this->assertStringContainsString( 'shown', $preview, 'the preview is the labeled arguments' );
		$this->assertStringNotContainsString( 'UNREVIEWED-SECRET', $preview );
		$this->assertStringNotContainsString( 'UNREVIEWED-SECRET', (string) wp_json_encode( $first['result'] ) );

		$token = $this->structured( $first['result'] )['confirmation_token'];
		$run   = $this->call(
			$ctx,
			self::UNREVIEWED,
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertCount( 1, $this->ran( self::UNREVIEWED ) );
		$this->assertFalse( $this->ran( self::UNREVIEWED )[0]['input']['dry_run'] );
	}

	public function test_uncontracted_extension_write_has_no_run_from_page() {
		$ctx = $this->ctx();
		$this->unreviewed();
		$id = (int) $this->call( $ctx, self::UNREVIEWED, array( 'site_ids' => array( 3 ) ) )['decision']['approval_id'];
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertFalse( $this->gate->page_view( $id, $this->user_id )['can_run'] );
		$this->assertSame( 'refused', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ) );
		$this->assertSame( 'approved', $this->store->get_approval( $id )['status'] );
	}

	public function test_tier_table_refusal_overrides_uncontracted_path() {
		$ctx = $this->ctx();
		$this->unreviewed();
		$this->entry( self::UNREVIEWED, 'dashboard', array( 'source' => 'refused' ) );
		$this->publish( array( self::UNREVIEWED => $this->declared() ) );
		$this->policy->tier_for_refused = 'dashboard';
		$decision                       = $this->call( $ctx, self::UNREVIEWED, array( 'site_ids' => array( 3 ) ) )['decision'];
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ), 'a tier table refusal must stop before creating an approval' );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'not_available', $decision['reason'] );
		$this->assertNull( ( new MCP_Control_Contracts( $this->services ) )->contract_for( $ctx, self::UNREVIEWED ) );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ) );
	}

	public function test_uncontracted_extension_write_never_becomes_job_or_stores_plan() {
		add_filter(
			'mainwp_remote_mcp_chunk_size',
			function () {
				return 2;
			}
		);
		$ctx = $this->ctx();
		$this->unreviewed();
		$args = array( 'site_ids' => array( 1, 2, 3, 4, 5 ) );
		$id   = (int) $this->call( $ctx, self::UNREVIEWED, $args )['decision']['approval_id'];
		$this->assertNull( $this->store->get_approval( $id )['call_plan'] );
		$this->approve( $id );
		$this->assertNull( $this->store->get_approval( $id )['call_plan'], 'Approve stores nothing either' );
		$run = $this->call( $ctx, self::UNREVIEWED, $args );
		$this->assertSame( 'run', $run['decision']['decision'] );
		$this->assertSame( array(), $this->jobs->calls );
		$this->assertCount( 1, $this->executor->calls, 'one inline entry' );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $this->executor->calls[0]['entry']['target_ids'] );
	}

	public function test_uncontracted_ability_needing_native_preview_token_refused() {
		$ctx = $this->ctx();
		$this->unreviewed( array( 'preview_token' => array( 'type' => 'string' ) ) );
		$decision = $this->gate->check( $ctx, self::UNREVIEWED, array( 'site_ids' => array( 3 ) ) );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'needs_contract', $decision['reason'] );
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ) );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ) );
	}

	public function test_uncontracted_execution_sends_declared_dry_run_false_and_confirm_true() {
		$ctx = $this->ctx();
		$this->unreviewed();
		$args = array(
			'site_ids' => array( 3 ),
			'dry_run'  => true,
			'confirm'  => false,
		);
		$this->approve( (int) $this->call( $ctx, self::UNREVIEWED, $args )['decision']['approval_id'] );
		$this->call( $ctx, self::UNREVIEWED, $args );
		$input = $this->ran( self::UNREVIEWED )[0]['input'];
		$this->assertFalse( $input['dry_run'] ?? null );
		$this->assertTrue( $input['confirm'] ?? null );

		$this->ability( 'acme-ext/plain-thing-v1', array( 'label' => array( 'type' => 'string' ) ) );
		$this->entry( 'acme-ext/plain-thing-v1', 'dashboard', array( 'source' => 'uncontracted' ) );
		$this->approve( (int) $this->call( $ctx, 'acme-ext/plain-thing-v1', array( 'label' => 'x' ) )['decision']['approval_id'] );
		$this->call( $ctx, 'acme-ext/plain-thing-v1', array( 'label' => 'x' ) );
		$this->assertSame( array( 'label' => 'x' ), $this->ran( 'acme-ext/plain-thing-v1' )[0]['input'], 'nothing undeclared is added' );
	}

	public function test_uncontracted_underivable_controls_refused_before_approval() {
		$ctx = $this->ctx();
		$this->ability(
			self::UNREVIEWED,
			array(
				'label'   => array( 'type' => 'string' ),
				'confirm' => array( 'type' => 'string' ),
			)
		);
		$this->entry( self::UNREVIEWED, 'dashboard', array( 'source' => 'uncontracted' ) );
		$decision = $this->gate->check( $ctx, self::UNREVIEWED, array( 'label' => 'x' ) );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'needs_contract', $decision['reason'] );
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ), 'refused before any approval is requested' );
	}

	public function test_uncontracted_extension_write_never_write_tier() {
		$this->unreviewed();
		// Even a policy that answers `write` for it can't run it directly.
		$this->entry(
			self::UNREVIEWED,
			'write',
			array(
				'source'      => 'uncontracted',
				'conditional' => 'always_write',
			)
		);
		$this->policy->conditionals['always_write'] = function () {
			return 'write';
		};
		$args                                       = array( 'site_ids' => array( 3 ) );
		$out                                        = $this->call( $this->ctx(), self::UNREVIEWED, $args );
		$this->assertSame( 'approval_required', $out['decision']['decision'] );
		$this->assertSame( 'dashboard', $out['decision']['tier'] );
		$hands = $this->call( $this->ctx( array( 'policy' => 'hands_off' ) ), self::UNREVIEWED, $args );
		$this->assertSame( 'approval_required', $hands['decision']['decision'], 'a malformed tier gets the strictest one' );
		$this->assertSame( array(), $this->ran( self::UNREVIEWED ) );
	}

	public function test_credential_replace_abilities_run_dashboard_inline_on_retry_and_are_never_stored() {
		$ctx    = $this->ctx();
		$matomo = 'mainwp-test/replace-matomo-connection-v1';
		$terms  = 'mainwp-test/replace-termageddon-credential-v1';
		$echo   = function ( $input ) {
			return array(
				'saved' => true,
				'echo'  => $input,
			);
		};
		$this->ability(
			$matomo,
			array(
				'origin_url' => array( 'type' => 'string' ),
				'api_token'  => array( 'type' => array( 'string', 'null' ) ),
				'dry_run'    => array( 'type' => 'boolean' ),
				'confirm'    => array( 'type' => 'boolean' ),
			),
			array( 'result' => $echo )
		);
		$this->entry(
			$matomo,
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
				'sensitive'          => array( 'api_token' ),
			)
		);
		$minted = (object) array( 'n' => 0 );
		$this->ability(
			$terms,
			array(
				'license_key'   => array( 'type' => 'string' ),
				'dry_run'       => array( 'type' => 'boolean' ),
				'preview_token' => array( 'type' => 'string' ),
			),
			array(
				'result' => function ( $input ) use ( $minted, $echo ) {
					if ( ! empty( $input['dry_run'] ) ) {
						++$minted->n;
						return array(
							'effect'        => 'replace',
							'preview_token' => 'term-' . $minted->n,
						);
					}
					return $echo( $input );
				},
			)
		);
		$this->entry(
			$terms,
			'dashboard',
			array(
				'source'             => 'audit',
				'credential_bearing' => true,
				'compare_fields'     => array( 'effect' ),
				'contract'           => array(
					'source'             => 'audit',
					'preview'            => array(
						'kind'          => 'dry_run',
						'ability'       => null,
						'arg_map'       => null,
						'controls'      => array( 'dry_run' => true ),
						'capture_token' => 'preview_token',
					),
					'execute'            => array(
						'controls'           => array( 'dry_run' => false ),
						'native_token_field' => 'preview_token',
					),
					'sensitive'          => array(),
					'deferred'           => false,
					'credential_bearing' => true,
					'gate_supplied'      => array(),
					'compare_fields'     => array( 'effect' ),
					'tier'               => 'dashboard',
				),
			)
		);
		$calls = array(
			$matomo => array(
				'origin_url' => 'https://matomo.example',
				'api_token'  => 'MATOMO-SECRET',
			),
			$terms  => array( 'license_key' => 'TERMS-SECRET' ),
		);
		foreach ( $calls as $ability => $args ) {
			$secret = 'mainwp-test/replace-matomo-connection-v1' === $ability ? 'MATOMO-SECRET' : 'TERMS-SECRET';
			$first  = $this->call( $ctx, $ability, $args );
			$id     = (int) $first['decision']['approval_id'];
			$row    = $this->store->get_approval( $id );
			$this->assertNull( $row['call_plan'], $ability );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $row ) );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $first['result'] ) );

			$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
			$this->assertSame( array(), $this->ran( $ability ), 'Approve runs nothing for ' . $ability );
			$view = $this->gate->page_view( $id, $this->user_id );
			$this->assertTrue( $view['plan_less'] );
			$this->assertFalse( $view['can_run'] );
			$this->assertStringContainsString( 'supplied', (string) wp_json_encode( $view['summary'] ) );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $view ) );

			$run = $this->call( $ctx, $ability, $args );
			$this->assertSame( 'run', $run['decision']['decision'], $ability );
			$this->assertFalse( $run['result']['isError'] );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $run['result'] ), 'the result never echoes the secret' );
			$ran = $this->ran( $ability );
			$this->assertTrue( $ran[0]['input']['dry_run'], 'the preview first runs on the retry' );
			$this->assertSame( $secret, end( $ran )['input'][ 'mainwp-test/replace-matomo-connection-v1' === $ability ? 'api_token' : 'license_key' ] );
		}
		$this->assertSame( 'term-1', end( $this->calls[ $terms ] )['input']['preview_token'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->audit ) );
		$this->assertSame( array(), $this->jobs->calls );
	}

	public function test_updraftplus_abilities_inert_until_a_release_enables_them() {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/replace-updraftplus-policy-v1';
		$this->ability( $name, array( 'site_id' => array( 'type' => 'integer' ) ) );
		$this->entry(
			$name,
			'dashboard',
			array(
				'source'      => 'audit',
				'inert'       => true,
				'min_version' => '5.2',
			)
		);
		$decision = $this->gate->check( $ctx, $name, array( 'site_id' => 4 ) );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'not_available_yet', $decision['reason'] );
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ) );

		$this->entry(
			$name,
			'dashboard',
			array(
				'source'      => 'audit',
				'min_version' => '5.2',
			)
		);
		$this->assertSame( 'approval_required', $this->gate->check( $ctx, $name, array( 'site_id' => 4 ) )['decision'], 'the release that enables them makes the entry live' );
		$this->assertSame( array(), $this->ran( $name ) );
	}

	public function test_value_conditional_extension_ability_tier_follows_deciding_field() {
		$ctx   = $this->ctx();
		$clone = 'mainwp-test/set-clone-enabled-v1';
		$this->ability( $clone, array( 'enabled' => array( 'type' => 'boolean' ) ) );
		$this->entry(
			$clone,
			'dashboard',
			array(
				'source'      => 'audit',
				'conditional' => 'clone_enabled',
			)
		);
		$this->policy->conditionals['clone_enabled'] = function ( $args ) {
			return ! empty( $args['enabled'] ) ? 'dashboard' : 'write';
		};
		$this->assertSame( 'run', $this->call( $ctx, $clone, array( 'enabled' => false ) )['decision']['decision'], 'turning clone off is write' );
		$this->assertSame( 'approval_required', $this->call( $ctx, $clone, array( 'enabled' => true ) )['decision']['decision'] );
		$this->assertSame( array( false, true ), array_column( array_column( array_slice( $this->policy->calls, 0 ), 'arguments' ), 'enabled' ), 'the deciding field reaches the tier policy' );

		$remove = 'mainwp-test/remove-termageddon-page-v1';
		$this->ability(
			$remove,
			array(
				'mode'    => array( 'type' => 'string' ),
				'dry_run' => array( 'type' => 'boolean' ),
			)
		);
		$this->entry(
			$remove,
			'dashboard',
			array(
				'source'      => 'audit',
				'conditional' => 'termageddon_remove_mode',
			)
		);
		$this->policy->conditionals['termageddon_remove_mode'] = function ( $args ) {
			return 'unlink_local' === ( $args['mode'] ?? '' ) ? 'confirm' : 'dashboard';
		};
		$this->assertSame( 'confirmation_required', $this->call( $ctx, $remove, array( 'mode' => 'unlink_local' ) )['decision']['decision'] );
		$this->assertSame( 'approval_required', $this->call( $ctx, $remove, array( 'mode' => 'delete_child_and_unlink' ) )['decision']['decision'] );
		$this->assertCount( 1, $this->executions_of( $clone ) );
	}

	/**
	 * Calls of an ability that weren't previews.
	 *
	 * @param string $name Ability.
	 * @return array
	 */
	private function executions_of( string $name ): array {
		return array_values(
			array_filter(
				$this->ran( $name ),
				function ( $call ) {
					return empty( $call['input']['dry_run'] );
				}
			)
		);
	}
}
