<?php
/**
 * Remote MCP authorization context tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Context
 */
class Test_MainWP_Remote_MCP_Context extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	public function test_context_carries_protocol_era_url_elicitation_and_request_state_with_safe_defaults() {
		$defaults = new Remote_MCP_Context( 7, 1, 'https://claude.ai/oauth/claude-code-client-metadata', 'bundled', array( 'mainwp:read' ), 'standard', 1, 'access' );
		$this->assertSame( 'none', $defaults->protocol_era );
		$this->assertFalse( $defaults->client_supports_url_elicitation );
		$this->assertNull( $defaults->request_state );

		$modern = new Remote_MCP_Context( 7, 1, 'https://claude.ai/oauth/claude-code-client-metadata', 'bundled', array( 'mainwp:read' ), 'standard', 1, 'access', 'modern', true, 'sealed-state' );
		$this->assertSame( 'modern', $modern->protocol_era );
		$this->assertTrue( $modern->client_supports_url_elicitation );
		$this->assertSame( 'sealed-state', $modern->request_state );

		// An era outside the three is no era: nothing era-specific can be chosen from it.
		$odd = new Remote_MCP_Context( 7, 1, 'x', 'bundled', array(), 'standard', 1, 'access', 'future', true, null );
		$this->assertSame( 'none', $odd->protocol_era );
		$this->assertFalse( $odd->client_supports_url_elicitation, 'no era, no elicitation' );
		// 2025-11-25 clients get URL elicitation through -32042; requestState is 2026-07-28 only.
		$legacy = new Remote_MCP_Context( 7, 1, 'x', 'bundled', array(), 'standard', 1, 'access', 'legacy', true, 'state' );
		$this->assertSame( 'legacy', $legacy->protocol_era );
		$this->assertTrue( $legacy->client_supports_url_elicitation );
		$this->assertNull( $legacy->request_state, 'requestState exists only in the modern era' );

		$check = array(
			'outcome'         => OAuth_Store::OK,
			'grant_id'        => 9,
			'user_id'         => 2,
			'client_id'       => 'mainwp-connection-token',
			'client_kind'     => 'token',
			'scope'           => 'mainwp:read mainwp:write',
			'approval_policy' => 'strict',
			'policy_version'  => 3,
			'token_type'      => 'connect',
		);
		$from = Remote_MCP_Context::from_check( $check );
		$this->assertSame( array( 'none', false, null ), array( $from->protocol_era, $from->client_supports_url_elicitation, $from->request_state ) );
		$from = Remote_MCP_Context::from_check( $check, 'modern', true, 'sealed' );
		$this->assertSame( array( 'modern', true, 'sealed' ), array( $from->protocol_era, $from->client_supports_url_elicitation, $from->request_state ) );
		$this->assertSame( array( 'mainwp:read', 'mainwp:write' ), $from->scopes );
		$this->assertNull( Remote_MCP_Context::from_check( array( 'outcome' => OAuth_Store::INVALID_TOKEN ), 'modern', true, 'x' ) );
	}
}
