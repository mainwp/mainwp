<?php
/**
 * Remote MCP scope and approval-policy store tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Grant_Scope_Policy
 */
class Test_MainWP_OAuth_Grant_Scope_Policy extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';
	const WRITE     = 'mainwp:read mainwp:write offline_access';

	/**
	 * Consent with an explicit scope and policy choice, then the code exchange.
	 *
	 * @param string $requested Requested scope.
	 * @param string $approved  Scope the user approved.
	 * @param string $policy    Approval policy.
	 * @return array Exchange result plus `request_id`.
	 */
	private function consent( string $requested, string $approved, string $policy ): array {
		$params  = $this->request_params( 0, array( 'scope' => $requested ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $this->user_id, true, $approved, $policy ), 'consent claim' );
		$code = $this->store->issue_code( $request['id'] );
		$this->assertIsString( $code );
		$result = $this->redeem( $code );
		$this->assertSame( OAuth_Store::OK, $result['outcome'] );
		$result['request_id'] = $request['id'];
		return $result;
	}

	/**
	 * A one-entry plan.
	 *
	 * @return Call_Plan
	 */
	private function plan(): Call_Plan {
		return Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/suspend-site-v1',
					'args'       => array( 'site_id_or_domain' => 4 ),
					'target_ids' => array( 4 ),
				),
			)
		);
	}

	/**
	 * Store an approval for a grant.
	 *
	 * @param int    $grant_id Grant.
	 * @param string $tier     Tier.
	 * @return array
	 */
	private function approval( int $grant_id, string $tier = 'confirm' ): array {
		$created = $this->store->create_approval(
			array(
				'grant_id'    => $grant_id,
				'ability'     => 'mainwp/suspend-site-v1',
				'tier'        => $tier,
				'args_digest' => str_repeat( 'a', 64 ),
				'target_ids'  => array( 4 ),
				'summary'     => 'Suspend site 4',
				'call_plan'   => $this->plan(),
			)
		);
		$this->assertSame( OAuth_Store::OK, $created['outcome'], 'approval stored' );
		return $created;
	}

	public function test_view_only_consent_redeems_to_read_only_token() {
		$result = $this->consent( self::WRITE, 'mainwp:read offline_access', 'standard' );

		$request = $this->rows( 'requests', 'id = ' . (int) $result['request_id'] )[0];
		$this->assertSame( self::WRITE, $request['scope'], 'the request keeps what the app asked for' );
		$this->assertSame( 'mainwp:read offline_access', $request['approved_scope'] );
		$this->assertSame( 'mainwp:read offline_access', $this->rows( 'codes' )[0]['scope'], 'the code carries the approved scope' );
		$this->assertSame( 'mainwp:read offline_access', $result['scope'] );
		$this->assertSame( 'mainwp:read offline_access', $this->grant( $result['grant_id'] )['scope'] );
		foreach ( $this->rows( 'tokens', 'grant_id = ' . (int) $result['grant_id'] ) as $token ) {
			$this->assertSame( 'mainwp:read offline_access', $token['scope'] );
		}
		$access = $this->store->check_access_token( $result['access_token'] );
		$this->assertSame( OAuth_Store::OK, $access['outcome'] );
		$this->assertSame( 'mainwp:read offline_access', $access['scope'] );

		$rotated = $this->refresh( $result['refresh_token'] );
		$this->assertSame( 'mainwp:read offline_access', $rotated['scope'] );
		$this->assertSame( 'mainwp:read offline_access', $this->store->check_access_token( $rotated['access_token'] )['scope'] );

		// "View and make changes" keeps write through the same path.
		$write = $this->consent( self::WRITE, self::WRITE, 'standard' );
		$this->assertSame( self::WRITE, $this->store->check_access_token( $write['access_token'] )['scope'] );

		// Approving more than the app asked for is refused and leaves the request pending.
		$read_only = $this->store->create_request( $this->request_params() );
		$this->assertFalse( $this->store->claim_request( $read_only['id'], $this->user_id, true, self::WRITE, 'standard' ) );
		$this->assertSame( 'pending', $this->rows( 'requests', 'id = ' . (int) $read_only['id'] )[0]['status'] );

		// An approval that names no choice is view only under Strict.
		$silent = $this->store->create_request( $this->request_params( 0, array( 'scope' => self::WRITE ) ) );
		$this->assertTrue( $this->store->claim_request( $silent['id'], $this->user_id, true ) );
		$row = $this->rows( 'requests', 'id = ' . (int) $silent['id'] )[0];
		$this->assertSame( 'mainwp:read offline_access', $row['approved_scope'] );
		$this->assertSame( 'strict', $row['approval_policy'] );
	}

	public function test_strict_policy_survives_code_redemption_into_grant() {
		$result = $this->consent( self::WRITE, self::WRITE, 'strict' );

		$this->assertSame( 'strict', $this->rows( 'requests', 'id = ' . (int) $result['request_id'] )[0]['approval_policy'] );
		$this->assertSame( 'strict', $this->rows( 'codes' )[0]['approval_policy'] );
		$grant = $this->grant( $result['grant_id'] );
		$this->assertSame( 'strict', $grant['approval_policy'] );
		$this->assertSame( '1', $grant['policy_version'] );
		$this->assertSame( 'bundled', $grant['client_kind'] );

		$access = $this->store->check_access_token( $result['access_token'] );
		$this->assertSame( 'strict', $access['approval_policy'] );
		$this->assertSame( 1, $access['policy_version'] );

		$rotated = $this->refresh( $result['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $rotated['outcome'] );
		$this->assertSame( 'strict', $this->store->check_access_token( $rotated['access_token'] )['approval_policy'] );

		// Only the three policies exist.
		$other = $this->store->create_request( $this->request_params() );
		$this->assertFalse( $this->store->claim_request( $other['id'], $this->user_id, true, 'mainwp:read offline_access', 'yolo' ) );
	}

	public function test_effective_scope_is_intersection_of_token_and_grant() {
		$result = $this->consent( self::WRITE, self::WRITE, 'standard' );
		$this->assertSame( self::WRITE, $this->store->check_access_token( $result['access_token'] )['scope'] );

		// A token narrower than its grant: the refresh asked for less.
		$narrow_token = $this->store->refresh( $result['refresh_token'], self::CLIENT_ID, 'mainwp:read offline_access' );
		$this->assertSame( OAuth_Store::OK, $narrow_token['outcome'] );
		$this->assertSame( self::WRITE, $this->grant( $result['grant_id'] )['scope'] );
		$this->assertSame( 'mainwp:read offline_access', $this->store->check_access_token( $narrow_token['access_token'] )['scope'] );

		// A grant narrower than its token: the panel narrowed it in place, no reissue.
		$this->assertTrue( $this->store->narrow_grant_scope( $result['grant_id'], 'mainwp:read' ) );
		$this->assertSame( 'mainwp:read', $this->store->check_access_token( $narrow_token['access_token'] )['scope'] );
		$token_rows = $this->rows( 'tokens', "token_type = 'access' AND grant_id = " . (int) $result['grant_id'] );
		$this->assertSame( 'mainwp:read offline_access', end( $token_rows )['scope'], 'stored token rows never change' );

		// Narrowing never widens.
		$this->assertFalse( $this->store->narrow_grant_scope( $result['grant_id'], self::WRITE ) );
		$this->assertFalse( $this->store->narrow_grant_scope( $result['grant_id'], 'mainwp:write' ) );
		$this->assertSame( 'mainwp:read', $this->grant( $result['grant_id'] )['scope'] );

		// A refresh can't ask for more than the grant now holds.
		$wider = $this->store->refresh( $narrow_token['refresh_token'], self::CLIENT_ID, 'mainwp:read mainwp:write' );
		$this->assertSame( OAuth_Store::INVALID_SCOPE, $wider['outcome'] );
	}

	public function test_connection_status_setters_record_ip_and_failure() {
		$result = $this->consent( self::WRITE, self::WRITE, 'standard' );
		$before = $this->grant( $result['grant_id'] );

		$this->tick( 120 );
		$this->assertTrue( $this->store->record_grant_use( $result['grant_id'], '203.0.113.9' ) );
		$this->assertFalse( $this->store->record_grant_use( $result['grant_id'], '203.0.113.9' ), 'at most once a minute for the same address' );
		$this->assertTrue( $this->store->record_grant_failure( $result['grant_id'], 'auth_token_rejected' ) );

		$after = $this->grant( $result['grant_id'] );
		$this->assertSame( '203.0.113.9', $after['last_ip'] );
		$this->assertSame( (string) $this->clock->now, $after['last_used_at'] );
		$this->assertSame( 'auth_token_rejected', $after['last_failure'] );
		$this->assertSame( (string) $this->clock->now, $after['last_failure_at'] );
		$this->assertSame( $before['version'], $after['version'], 'status writes never touch the family lock' );
	}

	public function test_refresh_refuses_write_without_read() {
		$result = $this->consent( self::WRITE, self::WRITE, 'standard' );
		$before = $this->grant( $result['grant_id'] );

		$refused = $this->store->refresh( $result['refresh_token'], self::CLIENT_ID, 'mainwp:write offline_access' );

		$this->assertSame( OAuth_Store::INVALID_SCOPE, $refused['outcome'] );
		$this->assertArrayNotHasKey( 'access_token', $refused );
		$after = $this->grant( $result['grant_id'] );
		$this->assertSame( $before['version'], $after['version'], 'no transition ran' );
		$this->assertSame( $before['current_pair'], $after['current_pair'] );
		$this->assertSame( OAuth_Store::OK, $this->refresh( $result['refresh_token'] )['outcome'], 'the token still works' );
	}

	public function test_policy_change_bumps_policy_version() {
		$result   = $this->consent( self::WRITE, self::WRITE, 'standard' );
		$grant_id = $result['grant_id'];
		$chat     = $this->approval( $grant_id, 'confirm' );
		$page     = $this->approval( $grant_id, 'dashboard' );
		$job      = $this->store->enqueue_job( $grant_id, 1, $this->plan() );
		$this->assertSame( OAuth_Store::OK, $job['outcome'] );

		$this->assertTrue( $this->store->set_approval_policy( $grant_id, 'strict' ) );

		$grant = $this->grant( $grant_id );
		$this->assertSame( 'strict', $grant['approval_policy'] );
		$this->assertSame( '2', $grant['policy_version'] );
		$this->assertSame( 2, $this->store->check_access_token( $result['access_token'] )['policy_version'] );

		// Open approvals and running jobs from the old version are ended.
		foreach ( array( $chat, $page ) as $approval ) {
			$row = $this->store->get_approval( $approval['approval_id'] );
			$this->assertSame( 'stale', $row['status'] );
			$this->assertNull( $row['call_plan'], 'a stale row keeps no plan' );
		}
		$this->assertFalse( $this->store->consume_approval( $chat['approval_id'], 'confirm' ) );
		$this->assertSame( 'cancelled', $this->store->get_job( $job['job_id'] )['status'] );
		$this->assertFalse( $this->store->authorization_active( $grant_id, 1 ) );
		$this->assertTrue( $this->store->authorization_active( $grant_id, 2 ) );

		// An approval made under the new version can be consumed, once.
		$fresh = $this->approval( $grant_id, 'confirm' );
		$this->assertTrue( $this->store->consume_approval( $fresh['approval_id'], 'confirm' ) );
		$this->assertFalse( $this->store->consume_approval( $fresh['approval_id'], 'confirm' ) );

		// Only a real change bumps, and only to a known policy.
		$this->assertFalse( $this->store->set_approval_policy( $grant_id, 'strict' ) );
		$this->assertFalse( $this->store->set_approval_policy( $grant_id, 'anything' ) );
		$this->assertSame( '2', $this->grant( $grant_id )['policy_version'] );
		$this->assertTrue( $this->store->set_approval_policy( $grant_id, 'standard' ) );
		$this->assertSame( '3', $this->grant( $grant_id )['policy_version'], 'loosening bumps too' );
	}
}
