<?php
/**
 * Remote MCP approval store tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Approval_Store
 */
class Test_MainWP_OAuth_Approval_Store extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_acknowledge_denied_approval_closes_once_and_lookup_skips_closed() {
		$params  = $this->request_params( 0, array( 'scope' => 'mainwp:read mainwp:write offline_access' ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $this->user_id, true, 'mainwp:read mainwp:write offline_access', 'standard' ) );
		$grant  = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$digest = str_repeat( 'e', 64 );
		$base   = array(
			'grant_id'    => $grant['grant_id'],
			'ability'     => 'mainwp/delete-site-v1',
			'tier'        => 'dashboard',
			'args_digest' => $digest,
		);

		$older = $this->store->create_approval( $base );
		$this->assertTrue( $this->store->decide_approval( $older['approval_id'], $this->user_id, true ) );
		$denied = $this->store->create_approval( $base );
		$this->assertTrue( $this->store->decide_approval( $denied['approval_id'], $this->user_id, false ) );
		$this->assertSame( (string) $denied['approval_id'], $this->store->latest_dashboard_approval( $grant['grant_id'], 'mainwp/delete-site-v1', $digest )['id'], 'the denial is answered once' );

		$this->assertTrue( $this->store->acknowledge_denied_approval( $denied['approval_id'] ) );
		$this->assertFalse( $this->store->acknowledge_denied_approval( $denied['approval_id'] ), 'one transition only' );
		$this->assertSame( 'closed', $this->store->get_approval( $denied['approval_id'] )['status'] );
		$this->assertNull( $this->store->latest_dashboard_approval( $grant['grant_id'], 'mainwp/delete-site-v1', $digest ), 'a closed denial ends the lookup; the older approval never resurfaces' );

		// Only a denied row can be closed.
		$this->assertFalse( $this->store->acknowledge_denied_approval( $older['approval_id'] ) );
		$this->assertSame( 'approved', $this->store->get_approval( $older['approval_id'] )['status'] );
		$pending = $this->store->create_approval( $base );
		$this->assertFalse( $this->store->acknowledge_denied_approval( $pending['approval_id'] ) );
		$this->assertFalse( $this->store->acknowledge_denied_approval( 999999 ) );
	}

	public function test_decide_approval_writes_click_time_capture_only_on_approve() {
		global $wpdb;
		$params  = $this->request_params( 0, array( 'scope' => 'mainwp:read mainwp:write offline_access' ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $this->user_id, true, 'mainwp:read mainwp:write offline_access', 'standard' ) );
		$grant  = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$ref    = '6f1c2b52-3c0e-4f43-9a55-0c8f3d1d7a10';
		$first  = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/request-gsc-refresh-v1',
					'args'       => array( 'request_ref' => $ref ),
					'target_ids' => array( 4 ),
				),
			)
		);
		$base   = array(
			'grant_id'    => $grant['grant_id'],
			'ability'     => 'mainwp/request-gsc-refresh-v1',
			'tier'        => 'dashboard',
			'args_digest' => str_repeat( 'd', 64 ),
			'target_ids'  => array( 4 ),
			'call_plan'   => $first,
			'gate_values' => array( 'fields' => array( 'request_ref' => $ref ) ),
			'summary'     => 'first call',
		);
		$baseline   = str_repeat( 'b', 64 );
		$executable = Call_Plan::from_entries(
			array(
				array(
					'ability'          => 'mainwp/request-gsc-refresh-v1',
					'args'             => array(
						'request_ref' => $ref,
						'confirm'     => true,
					),
					'target_ids'       => array( 4 ),
					'preview_baseline' => $baseline,
				),
			)
		);
		$capture    = array(
			'call_plan'            => $executable,
			'native_preview_token' => 'native-token-secret-123',
			'gate_values'          => array(
				'fields'           => array( 'request_ref' => $ref ),
				'preview_baseline' => $baseline,
			),
			'summary'              => 'click summary with preview',
		);
		$columns    = function ( int $id ) use ( $wpdb ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT status, call_plan, native_preview_token, gate_values, summary FROM %i WHERE id = %d', \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::table( 'approvals' ), $id ), ARRAY_A );
		};

		// A deny writes none of the capture.
		$denied = $this->store->create_approval( $base );
		$before = $columns( $denied['approval_id'] );
		$this->assertTrue( $this->store->decide_approval( $denied['approval_id'], $this->user_id, false, $capture ) );
		$after = $columns( $denied['approval_id'] );
		$this->assertSame( 'denied', $after['status'] );
		unset( $before['status'], $after['status'] );
		$this->assertSame( $before, $after, 'a deny keeps the first call plan, gate values and summary' );

		// A capture with any key outside the four, or a value of the wrong shape, writes nothing.
		$row    = $this->store->create_approval( $base );
		$before = $columns( $row['approval_id'] );
		foreach ( array(
			'extra key'           => array_merge( $capture, array( 'args' => array( 'request_ref' => $ref ) ) ),
			'credential key'      => array( 'http_pass' => 'hunter2' ),
			'plan as json'        => array_merge( $capture, array( 'call_plan' => $executable->to_json() ) ),
			'token not a string'  => array_merge( $capture, array( 'native_preview_token' => array( 'x' ) ) ),
			'gate values a list'  => array_merge( $capture, array( 'gate_values' => array( $ref ) ) ),
			'unknown gate member' => array_merge( $capture, array( 'gate_values' => array( 'request_ref' => $ref ) ) ),
			'bad baseline'        => array_merge( $capture, array( 'gate_values' => array( 'preview_baseline' => 'zz' ) ) ),
			'summary not text'    => array_merge( $capture, array( 'summary' => 5 ) ),
		) as $label => $bad ) {
			$this->assertFalse( $this->store->decide_approval( $row['approval_id'], $this->user_id, true, $bad ), $label );
			$this->assertSame( $before, $columns( $row['approval_id'] ), $label . ': the row is unchanged and still pending' );
		}
		// The capture carries the executable plan; a pending plan can never be what Approve stores.
		$pending = Call_Plan::pending(
			array(
				'ability'    => 'mainwp/request-gsc-refresh-v1',
				'args'       => array( 'request_ref' => $ref ),
				'target_ids' => array( 4 ),
			)
		);
		$this->assertFalse( $this->store->decide_approval( $row['approval_id'], $this->user_id, true, array_merge( $capture, array( 'call_plan' => $pending ) ) ) );
		$this->assertSame( $before, $columns( $row['approval_id'] ) );

		// Approve with a capture writes all four in the deciding statement, sealed where secret.
		$this->assertTrue( $this->store->decide_approval( $row['approval_id'], $this->user_id, true, $capture ) );
		$stored = $this->store->get_approval( $row['approval_id'] );
		$this->assertSame( 'approved', $stored['status'] );
		$this->assertSame( 'page', $stored['decided_via'] );
		$this->assertTrue( $this->store->approval_call_plan( $stored )->equals( $executable ), 'the executable plan replaces the first call plan' );
		$this->assertSame( 'native-token-secret-123', $this->store->approval_secret( $stored, 'native_preview_token' ) );
		$this->assertStringNotContainsString( 'native-token-secret-123', (string) $stored['native_preview_token'] );
		$this->assertStringNotContainsString( $ref, (string) $stored['call_plan'], 'the plan is sealed' );
		$this->assertSame( '{"fields":{"request_ref":"' . $ref . '"},"preview_baseline":"' . $baseline . '"}', $stored['gate_values'], 'the full object, fields kept' );
		$this->assertSame( 'click summary with preview', $stored['summary'] );

		// Decided once: a second Approve changes nothing.
		$other = array_merge( $capture, array( 'summary' => 'second click' ) );
		$this->assertFalse( $this->store->decide_approval( $row['approval_id'], $this->user_id, true, $other ) );
		$this->assertSame( 'click summary with preview', $this->store->get_approval( $row['approval_id'] )['summary'] );

		// Approve without a capture (a plan-less row) decides and leaves the columns alone.
		$plain  = $this->store->create_approval( $base );
		$before = $columns( $plain['approval_id'] );
		$this->assertTrue( $this->store->decide_approval( $plain['approval_id'], $this->user_id, true ) );
		$after = $columns( $plain['approval_id'] );
		$this->assertSame( 'approved', $after['status'] );
		unset( $before['status'], $after['status'] );
		$this->assertSame( $before, $after );

		// An empty token in the capture is "no token": the column keeps what it had.
		$empty  = $this->store->create_approval( $base );
		$before = $columns( $empty['approval_id'] );
		$this->assertTrue( $this->store->decide_approval( $empty['approval_id'], $this->user_id, true, array_merge( $capture, array( 'native_preview_token' => '' ) ) ) );
		$this->assertSame( $before['native_preview_token'], $columns( $empty['approval_id'] )['native_preview_token'] );
	}

	public function test_create_approval_stores_gate_values_and_takes_no_exec_payload() {
		$params  = $this->request_params( 0, array( 'scope' => 'mainwp:read mainwp:write offline_access' ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $this->user_id, true, 'mainwp:read mainwp:write offline_access', 'standard' ) );
		$grant = $this->redeem( $this->store->issue_code( $request['id'] ) );
		$this->assertSame( OAuth_Store::OK, $grant['outcome'] );
		$plan   = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/request-gsc-refresh-v1',
					'args'       => array( 'request_ref' => '6f1c2b52-3c0e-4f43-9a55-0c8f3d1d7a10' ),
					'target_ids' => array( 4 ),
				),
			)
		);
		$params = array(
			'grant_id'    => $grant['grant_id'],
			'ability'     => 'mainwp/request-gsc-refresh-v1',
			'tier'        => 'dashboard',
			'args_digest' => str_repeat( 'c', 64 ),
			'target_ids'  => array( 4 ),
			'gate_values' => array( 'request_ref' => '6f1c2b52-3c0e-4f43-9a55-0c8f3d1d7a10' ),
			'call_plan'   => $plan,
		);

		$created = $this->store->create_approval( $params );

		$this->assertSame( OAuth_Store::OK, $created['outcome'] );
		$expected = '{"request_ref":"6f1c2b52-3c0e-4f43-9a55-0c8f3d1d7a10"}';
		$row      = $this->store->get_approval( $created['approval_id'] );
		$this->assertSame( $expected, $row['gate_values'] );
		$this->assertArrayNotHasKey( 'exec_payload', $row, 'the call plan is the only execution source' );
		$this->assertSame( $expected, $this->store->find_approval_by_handle( $created['handle'] )['gate_values'] );
		$this->assertSame( $expected, $this->store->latest_dashboard_approval( $grant['grant_id'], 'mainwp/request-gsc-refresh-v1', str_repeat( 'c', 64 ) )['gate_values'] );
		$this->assertTrue( $this->store->approval_call_plan( $row )->equals( $plan ) );
		$this->assertNull( $this->store->approval_secret( $row, 'exec_payload' ) );

		// The parameter no longer exists: passing it is refused, not ignored.
		$with_payload = $this->store->create_approval( array_merge( $params, array( 'exec_payload' => '{"site_id":4}' ) ) );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $with_payload['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->store->create_approval( array_merge( $params, array( 'gate_values' => 'not-an-object' ) ) )['outcome'] );

		// Without gate values the column stays empty.
		unset( $params['gate_values'] );
		$plain = $this->store->create_approval( $params );
		$this->assertNull( $this->store->get_approval( $plain['approval_id'] )['gate_values'] );
	}
}
