<?php
/**
 * Remote MCP write step-up tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Step_Up
 */
class Test_MainWP_MCP_Step_Up extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';
	const CHATGPT   = 'https://chatgpt.com/oauth/client.json';

	/**
	 * A write call (delete-site, dashboard tier) with a token.
	 *
	 * @param string $token Token.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	private function write_call( string $token ) {
		return $this->legacy(
			'tools/call',
			array(
				'name'      => 'delete_site_v1',
				'arguments' => array( 'site_id_or_domain' => 3 ),
			),
			$token
		);
	}

	public function test_write_call_on_read_grant_returns_insufficient_scope_challenge() {
		$connection = $this->connection( 'mainwp:read' );
		$response   = $this->write_call( $connection['access_token'] );
		$this->assertSame( 403, $response->status() );
		$this->assertSame(
			'Bearer error="insufficient_scope", scope="mainwp:read mainwp:write", resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", error_description="This connection can only view"',
			$response->header( 'WWW-Authenticate' )
		);
		$this->assertSame( array(), $this->gate->checks, 'nothing reached the gate' );
		$this->assertSame( 'insufficient_scope', $this->grant( $connection['grant_id'] )['last_failure'] );

		// The same grant still reads, and a write grant goes to the gate.
		$this->assertSame( 200, $this->legacy( 'tools/list', array(), $connection['access_token'] )->status() );
		$this->gate->deliver_result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'gated',
				),
			),
			'isError' => false,
		);
		$this->assertSame( 200, $this->write_call( $this->token() )->status() );
		$this->assertCount( 1, $this->gate->checks );
	}

	public function test_challenge_scope_refusal_audits_once_without_arguments() {
		$connection = $this->connection( 'mainwp:read' );
		$events     = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$events ) {
				if ( 'mcp_call' === $event ) {
					$events[] = $fields;
				}
			},
			10,
			2
		);
		foreach ( array( 'legacy', 'modern' ) as $era ) {
			$events   = array();
			$response = $this->$era(
				'tools/call',
				array(
					'name'      => 'delete_site_v1',
					'arguments' => array(
						'site_id_or_domain' => 987654,
						'api_key'           => 'private-value',
						'preview_token'     => 'native-secret',
					),
				),
				$connection['access_token']
			);
			$this->assertSame( 403, $response->status() );
			$this->assertCount( 1, $events, $era . ' challenge refusal needs exactly one audit event' );
			$fields = $events[0];
			$this->assertSame( 'refused_scope', $fields['outcome'] );
			$this->assertSame( 'delete_site_v1', $fields['tool'] );
			$this->assertSame( 'mainwp/delete-site-v1', $fields['ability'] );
			$this->assertSame( 'dashboard', $fields['tier'] );
			$this->assertSame( $connection['grant_id'], $fields['grant_id'] );
			$this->assertSame( $this->user_id, $fields['user_id'] );
			$this->assertSame( 'bundled', $fields['client_kind'] );
			$this->assertSame( 'standard', $fields['approval_policy'] );
			$this->assertSame( 'legacy' === $era ? '2025-11-25' : '2026-07-28', $fields['protocol_version'] );
			$this->assertIsInt( $fields['duration_ms'] );
			$this->assertGreaterThanOrEqual( 0, $fields['duration_ms'] );
			$keys = array_keys( $fields );
			sort( $keys );
			$this->assertSame( array( 'ability', 'approval_policy', 'client_kind', 'duration_ms', 'grant_id', 'outcome', 'protocol_version', 'tier', 'tool', 'user_id' ), $keys );
			foreach ( array( '987654', 'private-value', 'native-secret', $connection['access_token'] ) as $private ) {
				$this->assertStringNotContainsString( $private, (string) wp_json_encode( $fields ) );
			}
		}
		$this->assertSame( array(), $this->gate->checks );
		$this->assertSame( array(), self::$executions );
	}

	public function test_missing_read_scope_challenge_audits_once() {
		$connection = $this->connection( '' );
		$events = array();
		add_action( Remote_MCP_Events::AUDIT, function ( $event, $fields ) use ( &$events ) {
			if ( 'mcp_call' === $event ) {
				$events[] = $fields;
			}
		}, 10, 2 );
		foreach ( array( 'legacy', 'modern' ) as $era ) {
			$events = array();
			$response = $this->$era( 'tools/call', array( 'name' => 'private-untrusted-name', 'arguments' => array( 'api_key' => 'private-value' ) ), $connection['access_token'] );
			$this->assertSame( 403, $response->status() );
			$this->assertCount( 1, $events, $era . ' missing-read challenge needs one audit event' );
			$this->assertSame( 'refused_scope', $events[0]['outcome'] );
			$this->assertSame( $connection['grant_id'], $events[0]['grant_id'] );
			$this->assertSame( $this->user_id, $events[0]['user_id'] );
			foreach ( array( 'private-untrusted-name', 'private-value', $connection['access_token'] ) as $private ) {
				$this->assertStringNotContainsString( $private, (string) wp_json_encode( $events[0] ) );
			}
		}
		$this->assertSame( array(), $this->gate->checks );
		$this->assertSame( array(), self::$executions );
	}

	public function test_switched_off_write_tool_on_read_grant_is_unknown_not_challenged() {
		\MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings::set_tool_enabled( 'delete_site_v1', false );
		$read     = $this->write_call( $this->connection( 'mainwp:read' )['access_token'] );
		$write    = $this->write_call( $this->token() );
		$this->assertNotSame( 403, $read->status(), 'no step-up for a tool the connection could never call' );
		$this->assertNull( $read->header( 'WWW-Authenticate' ) );
		$this->assertSame( $this->json( $write )['error'] ?? null, $this->json( $read )['error'] ?? null, 'the same answer a write grant gets' );
		$this->assertNotNull( $this->json( $read )['error'] ?? null );
		$this->assertSame( array(), $this->gate->checks );
	}

	public function test_job_status_errors_are_audited_as_errors_not_scope_refusals() {
		$events = array();
		add_action( Remote_MCP_Events::AUDIT, function ( $event, $fields ) use ( &$events ) {
			if ( 'mcp_call' === $event ) {
				$events[] = $fields;
			}
		}, 10, 2 );
		$token = $this->connection( 'mainwp:read' )['access_token'];
		$this->legacy( 'tools/call', array( 'name' => \MainWP\Dashboard\Remote_MCP\MCP_Tool_Catalog::MODULE_TOOL, 'arguments' => array( 'job_id' => 'not-a-number' ) ), $token );
		$this->assertCount( 1, $events );
		$this->assertSame( 'ability_error', $events[0]['outcome'] );
	}

	public function test_chatgpt_client_gets_www_authenticate_meta_instead_of_403() {
		$token    = $this->connection( 'mainwp:read', 'standard', self::CHATGPT )['access_token'];
		$response = $this->write_call( $token );
		$this->assertSame( 200, $response->status() );
		$this->assertNull( $response->header( 'WWW-Authenticate' ) );
		$result = $this->json( $response )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertArrayHasKey( '_meta', $result, 'the challenge travels in the result' );
		$this->assertSame(
			array( 'Bearer resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", scope="mainwp:read mainwp:write", error="insufficient_scope", error_description="This connection can only view. Reconnect and allow changes."' ),
			$result['_meta']['mcp/www_authenticate']
		);
		$this->assertSame( array(), $this->gate->checks );
	}

	public function test_chatgpt_step_up_result_matches_fixture() {
		$token    = $this->connection( 'mainwp:read', 'standard', self::CHATGPT )['access_token'];
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mcp/chatgpt-step-up.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$this->assertIsArray( $expected );
		$this->assertSame( $expected, $this->json( $this->write_call( $token ) )['result'] );
	}

	public function test_view_only_connection_token_write_gets_step_up_text() {
		$minted   = $this->connect_token( 'mainwp:read' );
		$response = $this->write_call( $minted['token'] );
		$this->assertSame( 200, $response->status() );
		$this->assertNull( $response->header( 'WWW-Authenticate' ), 'no OAuth challenge for a pasted token' );
		$result = $this->json( $response )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'This token can only view. Create a token with changes allowed in AI > MainWP MCP > Connect an app.', $result['content'][0]['text'] );
		$this->assertSame( array(), $this->gate->checks );

		// A registered app that can't step up gets the plain reconnect text.
		$static = $this->connection( 'mainwp:read', 'standard', 'mainwp-cursor' )['access_token'];
		$result = $this->json( $this->write_call( $static ) )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 'Reconnect with changes allowed.', $result['content'][0]['text'] );
	}
}
