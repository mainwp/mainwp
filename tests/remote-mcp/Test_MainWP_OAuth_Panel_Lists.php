<?php
/**
 * Remote MCP panel listing tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Panel_Lists
 */
class Test_MainWP_OAuth_Panel_Lists extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Columns no list may return.
	 *
	 * @var array
	 */
	const SECRET_COLUMNS = array( 'token_hash', 'code_hash', 'secret_hash', 'handle_hash', 'args_digest', 'plan_digest', 'call_plan', 'native_preview_token', 'gate_values', 'request_key', 'code_challenge' );

	/**
	 * Assert no row carries a hash, secret or sealed column, and no value looks like a credential.
	 *
	 * @param array  $rows  Rows.
	 * @param string $label Message.
	 */
	private function assert_no_secrets( array $rows, string $label ): void {
		foreach ( $rows as $row ) {
			$this->assertIsArray( $row, $label );
			foreach ( self::SECRET_COLUMNS as $column ) {
				$this->assertArrayNotHasKey( $column, $row, $label . ': ' . $column );
			}
			$this->assertDoesNotMatchRegularExpression( '/mw(pat|prt|pac|pct|pap|pws)_|v\d+:[A-Za-z0-9+\/]{20}/', wp_json_encode( $row ), $label );
		}
	}

	/**
	 * Connect a user with write scope.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	private function connect_write( int $user_id ): array {
		$params  = $this->request_params( $user_id, array( 'scope' => 'mainwp:read mainwp:write offline_access' ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $user_id, true, 'mainwp:read mainwp:write offline_access', 'standard' ) );
		$result = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$this->assertSame( OAuth_Store::OK, $result['outcome'] );
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
					'ability'    => 'mainwp/sync-sites-v1',
					'args'       => array( 'site_ids_or_domains' => array( 2 ) ),
					'target_ids' => array( 2 ),
				),
			)
		);
	}

	public function test_panel_list_methods_scope_rows_by_user_and_kind_and_return_no_secret_columns() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$mine    = $this->connect_write( $this->user_id );
		$this->tick( 10 );
		$newer   = $this->connect_write( $this->user_id );
		$revoked = $this->connect_write( $this->user_id );
		$this->store->revoke_grant( $revoked['grant_id'], 'manual' );
		$theirs  = $this->connect_write( $other );
		$token   = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'strict', 'LM Studio', $this->clock->now + 86400 );
		$this->assertSame( OAuth_Store::OK, $token['outcome'] );
		$expired = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Old app', $this->clock->now + 60 );
		$this->tick( 61 );

		// Grants: own, live and unexpired, newest first; a kind filter narrows them.
		$grants = $this->store->grants_for_user( $this->user_id );
		$this->assertSame( array( $token['grant_id'], $newer['grant_id'], $mine['grant_id'] ), array_map( 'intval', array_column( $grants, 'id' ) ) );
		$this->assert_no_secrets( $grants, 'grants_for_user' );
		$this->assertSame( 'LM Studio', $grants[0]['label'] );
		$this->assertSame( array( $newer['grant_id'], $mine['grant_id'] ), array_map( 'intval', array_column( $this->store->grants_for_user( $this->user_id, 'bundled' ), 'id' ) ) );
		$this->assertSame( array( $token['grant_id'] ), array_map( 'intval', array_column( $this->store->grants_for_user( $this->user_id, 'token' ), 'id' ) ) );
		$this->assertSame( array( $theirs['grant_id'] ), array_map( 'intval', array_column( $this->store->grants_for_user( $other ), 'id' ) ) );

		$all = $this->store->all_live_grants();
		$this->assertEqualsCanonicalizing( array( $token['grant_id'], $newer['grant_id'], $mine['grant_id'], $theirs['grant_id'] ), array_map( 'intval', array_column( $all, 'id' ) ) );
		$this->assert_no_secrets( $all, 'all_live_grants' );
		$this->assertSame( array( $token['grant_id'] ), array_map( 'intval', array_column( $this->store->all_live_grants( 'token' ), 'id' ) ) );

		// Connection tokens: the panel's columns only, own and unexpired.
		$tokens = $this->store->connection_tokens_for_user( $this->user_id );
		$this->assertCount( 1, $tokens );
		$this->assertSame( array( 'grant_id', 'label', 'scope', 'approval_policy', 'created_at', 'expires_at', 'last_used_at' ), array_keys( $tokens[0] ) );
		$this->assertSame( $token['grant_id'], $tokens[0]['grant_id'] );
		$this->assertSame( 'strict', $tokens[0]['approval_policy'] );
		$this->assertSame( array(), $this->store->connection_tokens_for_user( $other ) );
		$this->assertNotContains( $expired['grant_id'], array_column( $tokens, 'grant_id' ) );

		// Clients by kind, never the web app's secret hash.
		$registered = $this->store->create_client( 'registered', array( 'redirect_uris' => array( 'http://127.0.0.1/callback' ), 'client_name' => 'Jan', 'created_ip' => '203.0.113.5' ) );
		$webapp     = $this->store->create_client( 'webapp', array( 'redirect_uris' => array( 'https://chat.example.com/oauth/callback' ), 'client_name' => 'Open WebUI', 'created_by' => $this->user_id ) );
		$listed     = $this->store->clients_by_kind( 'webapp' );
		$this->assertSame( array( $webapp['client_id'] ), array_column( $listed, 'client_id' ) );
		$this->assertSame( array( 'https://chat.example.com/oauth/callback' ), $listed[0]['redirect_uris'] );
		$this->assert_no_secrets( $listed, 'clients_by_kind webapp' );
		$this->assertStringNotContainsString( $webapp['client_secret'], wp_json_encode( $listed ) );
		$this->assertSame( array( $registered['client_id'] ), array_column( $this->store->clients_by_kind( 'registered' ), 'client_id' ) );
		$this->assertSame( array(), $this->store->clients_by_kind( 'token' ) );

		// Pending approvals: own, pending, unexpired, Dashboard tier only.
		$base    = array(
			'ability'     => 'mainwp/sync-sites-v1',
			'args_digest' => str_repeat( 'd', 64 ),
			'target_ids'  => array( 2 ),
			'summary'     => 'Sync site 2',
			'call_plan'   => $this->plan(),
		);
		$pending = $this->store->create_approval( array_merge( $base, array( 'grant_id' => $newer['grant_id'], 'tier' => 'dashboard', 'native_preview_token' => 'native-token' ) ) );
		$this->store->create_approval( array_merge( $base, array( 'grant_id' => $newer['grant_id'], 'tier' => 'confirm' ) ) );
		$this->store->create_approval( array_merge( $base, array( 'grant_id' => $theirs['grant_id'], 'tier' => 'dashboard' ) ) );
		$stale = $this->store->create_approval( array_merge( $base, array( 'grant_id' => $newer['grant_id'], 'tier' => 'dashboard' ) ) );
		$this->store->mark_approval_stale( $stale['approval_id'] );
		$approvals = $this->store->pending_approvals_for_user( $this->user_id );
		$this->assertSame( array( $pending['approval_id'] ), array_map( 'intval', array_column( $approvals, 'id' ) ) );
		$this->assertSame( array( 2 ), $approvals[0]['target_ids'] );
		$this->assert_no_secrets( $approvals, 'pending_approvals_for_user' );
		$this->tick( OAuth_Store::APPROVAL_PENDING_LIFETIME );
		$this->assertSame( array(), $this->store->pending_approvals_for_user( $this->user_id ), 'expired rows drop out' );

		// Jobs: own, optionally by status, never the plan.
		$running = $this->store->enqueue_job( $newer['grant_id'], 1, $this->plan() );
		$done    = $this->store->enqueue_job( $newer['grant_id'], 1, $this->plan() );
		$this->store->finish_job( $done['job_id'], false );
		$this->store->enqueue_job( $theirs['grant_id'], 1, $this->plan() );
		$jobs = $this->store->jobs_for_user( $this->user_id );
		$this->assertEqualsCanonicalizing( array( $running['job_id'], $done['job_id'] ), array_map( 'intval', array_column( $jobs, 'id' ) ) );
		$this->assert_no_secrets( $jobs, 'jobs_for_user' );
		$this->assertSame( array( $done['job_id'] ), array_map( 'intval', array_column( $this->store->jobs_for_user( $this->user_id, 'done' ), 'id' ) ) );
	}
}
