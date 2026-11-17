<?php
/**
 * Remote MCP event tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Events
 */
class Test_MainWP_Remote_MCP_Events extends \WP_UnitTestCase {

	use Remote_MCP_Front_Fixtures;

	public function test_audit_helper_keeps_typed_policy_and_scope_transitions() {
		$fired = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$fired ) {
				$fired[] = array( $event, $fields );
			},
			10,
			2
		);

		Remote_MCP_Events::audit(
			'policy_changed',
			array(
				'grant_id'      => 4,
				'policy_before' => 'standard',
				'policy_after'  => 'hands_off',
			)
		);
		Remote_MCP_Events::audit(
			'scope_narrowed',
			array(
				'grant_id'     => 4,
				'scope_before' => 'mainwp:read mainwp:write offline_access',
				'scope_after'  => 'mainwp:read offline_access',
			)
		);
		Remote_MCP_Events::audit(
			'policy_changed',
			array(
				'grant_id'      => 5,
				'policy_before' => 'yolo',
				'policy_after'  => array( 'strict' ),
				'scope_before'  => 'mainwp:read <script>',
				'scope_after'   => 'mainwp:admin',
			)
		);
		Remote_MCP_Events::audit(
			'scope_narrowed',
			array(
				'scope_before' => '',
				'scope_after'  => 'mainwp:read  mainwp:read',
			)
		);

		$this->assertSame(
			array(
				array(
					'policy_changed',
					array(
						'grant_id'      => 4,
						'policy_before' => 'standard',
						'policy_after'  => 'hands_off',
					),
				),
				array(
					'scope_narrowed',
					array(
						'grant_id'     => 4,
						'scope_before' => 'mainwp:read mainwp:write offline_access',
						'scope_after'  => 'mainwp:read offline_access',
					),
				),
				array( 'policy_changed', array( 'grant_id' => 5 ) ),
				array( 'scope_narrowed', array() ),
			),
			$fired,
			'only known policies and well-formed known scopes survive'
		);
	}

	public function test_audit_helper_drops_unknown_events_and_fields() {
		$this->assertSame( 'mainwp_remote_mcp_grant_issued', Remote_MCP_Events::GRANT_ISSUED );
		$this->assertSame( 'mainwp_remote_mcp_audit', Remote_MCP_Events::AUDIT );
		foreach ( array( 'http_refused', 'consent_approved', 'token_issued', 'code_replay', 'registration_created', 'mcp_call', 'approval_required', 'approval_forgery', 'job_chunk', 'job_cancelled', 'client_added', 'scope_narrowed' ) as $event ) {
			$this->assertContains( $event, Remote_MCP_Events::AUDIT_EVENTS, $event );
		}

		$fired = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$fired ) {
				$fired[] = array( $event, $fields );
			},
			10,
			2
		);

		$this->assertTrue(
			Remote_MCP_Events::audit(
				'mcp_call',
				array(
					'user_id'         => 7,
					'grant_id'        => 12,
					'tool'            => 'list_sites_v1',
					'ability'         => 'mainwp/list-sites-v1',
					'outcome'         => 'ok',
					'duration_ms'     => 41,
					'client_kind'     => 'bundled',
					'site_ids'        => array( 3, 4 ),
					'arguments'       => array( 'search' => 'secret' ),
					'access_token'    => 'mwpat_x',
					'client_name'     => 'Spoofed',
					'state'           => 'abc',
					'request_body'    => '{}',
					'approval_policy' => array( 'not', 'a', 'string' ),
					'job_id'          => 'nine',
				)
			)
		);
		$this->assertSame(
			array(
				array(
					'mcp_call',
					array(
						'user_id'     => 7,
						'grant_id'    => 12,
						'tool'        => 'list_sites_v1',
						'ability'     => 'mainwp/list-sites-v1',
						'outcome'     => 'ok',
						'duration_ms' => 41,
						'client_kind' => 'bundled',
						'site_ids'    => array( 3, 4 ),
					),
				),
			),
			$fired,
			'only the Logged fields, with their expected types'
		);

		$this->assertFalse( Remote_MCP_Events::audit( 'made_up_event', array( 'user_id' => 1 ) ) );
		$this->assertFalse( Remote_MCP_Events::audit( 'MCP_CALL', array() ) );
		$this->assertCount( 1, $fired, 'unknown events never fire' );

		// The versioned ability name is a string field, cut to 191 bytes like the others.
		$this->assertTrue(
			Remote_MCP_Events::audit(
				'approval_required',
				array(
					'ability' => 'vendor/' . str_repeat( 'a', 250 ) . '-v1',
					'tier'    => 'dashboard',
				)
			)
		);
		$this->assertSame(
			array(
				'ability' => substr( 'vendor/' . str_repeat( 'a', 250 ), 0, 191 ),
				'tier'    => 'dashboard',
			),
			$fired[1][1]
		);
		Remote_MCP_Events::audit( 'mcp_call', array( 'ability' => array( 'mainwp/list-sites-v1' ) ) );
		$this->assertSame( array( 'mcp_call', array() ), end( $fired ), 'a non-string ability is dropped' );

		// The front controller reports a plain-HTTP refusal with the endpoint name only.
		unset( $_SERVER['HTTPS'] );
		$_SERVER['SERVER_PORT'] = '80';
		$this->dispatch( $this->controller(), '/wp-json/mainwp-remote/v1/oauth/token?code=mwpac_x', 'POST', $this->rest( '/mainwp-remote/v1/oauth/token' ), 'code=mwpac_secret' );
		$this->assertSame( array( 'http_refused', array( 'endpoint' => 'token' ) ), end( $fired ) );
	}

	public function test_http_refused_is_logged_once_an_hour_per_endpoint() {
		$fired = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$fired ) {
				if ( 'http_refused' === $event ) {
					$fired[] = $fields['endpoint'];
				}
			},
			10,
			2
		);
		unset( $_SERVER['HTTPS'] );
		$_SERVER['SERVER_PORT'] = '80';
		foreach ( array( 'oauth/token', 'oauth/token', 'oauth/token', 'mcp', 'mcp' ) as $route ) {
			$response = $this->dispatch( $this->controller(), '/wp-json/mainwp-remote/v1/' . $route, 'POST', $this->rest( '/mainwp-remote/v1/' . $route ), '' );
			$this->assertNotNull( $response, 'every request is still refused' );
		}
		$this->assertSame( array( 'token', 'mcp' ), $fired );
	}
}
