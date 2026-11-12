<?php
/**
 * Remote MCP connection token panel tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Connection_Tokens
 */
class Test_MainWP_Remote_MCP_Connection_Tokens extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Mint through the panel.
	 *
	 * @param array $fields Form fields.
	 * @return array Result.
	 */
	private function mint( array $fields = array() ): array {
		return $this->post(
			'mint_token',
			'',
			array_merge(
				array(
					'label'  => 'AnythingLLM',
					'access' => 'read',
					'policy' => 'standard',
					'expiry' => '90',
				),
				$fields
			)
		);
	}

	public function test_connection_token_mint_shows_once_without_a_per_user_cap() {
		$first = $this->mint();
		$this->assertTrue( $first['ok'], $first['code'] );
		$this->assertSame( 'AnythingLLM', $first['secret_label'], 'the one-time details identify the named token' );
		$this->assertMatchesRegularExpression( '/^mwpct_[A-Za-z0-9_-]{43}$/', $first['secret'] );
		$this->assertSame( OAuth_Store::OK, $this->store->check_connect_token( $first['secret'] )['outcome'], 'the shown token works' );

		$admin = $this->admin();
		$shown = $admin->render( $first );
		$this->assertStringContainsString( $first['secret'], $shown, 'shown with its snippets' );
		$this->assertStringContainsString( 'Authorization: Bearer', $shown );
		$this->assertStringNotContainsString( $first['secret'], $admin->render(), 'never shown again' );
		foreach ( $this->rows( 'tokens', "token_type = 'connect'" ) as $row ) {
			$this->assertStringNotContainsString( $first['secret'], wp_json_encode( $row ), 'not stored in clear' );
		}

		for ( $i = 2; $i <= 25; $i++ ) {
			$this->assertTrue( $this->mint( array( 'label' => 'App ' . $i ) )['ok'], 'token ' . $i );
		}
		$this->assertCount( 25, $this->store->connection_tokens_for_user( $this->user_id ) );
		$this->assertSame( OAuth_Store::OK, $this->store->check_connect_token( $first['secret'] )['outcome'], 'the oldest still works' );
		$html = $admin->render();
		$this->assertMatchesRegularExpression( '#<button type="submit"(?![^>]*\bdisabled\b)[^>]*>Create Token</button>#', $html );
		$this->assertStringNotContainsString( 'Revoke one first', $html );
	}

	public function test_connection_token_expiry_choices_have_no_never() {
		$html = $this->admin()->render();
		$this->assertSame( 1, preg_match( '#<select [^>]*name="expiry"[^>]*>(.*?)</select>#s', $html, $select ) );
		preg_match_all( '#<option value="([^"]*)"#', $select[1], $values );
		$this->assertSame( array( '30', '90', '365' ), $values[1] );
		$this->assertMatchesRegularExpression( '#<option value="90" selected#', $select[1], '90 days is the default' );
		$this->assertStringNotContainsString( 'never', strtolower( $select[1] ) );

		foreach ( array( 'never', '0', '', '366', '1000', '-1', '7' ) as $choice ) {
			$this->assertSame( 'invalid', $this->mint( array( 'expiry' => $choice ) )['code'], var_export( $choice, true ) );
		}
		$this->assertSame( array(), $this->store->connection_tokens_for_user( $this->user_id ) );

		// Mint computes expiry from the real time; keep the store clock on it so a second boundary
		// passing during the test can't push 365 days past the store's limit.
		$this->clock->now = time();
		$long             = $this->mint( array( 'expiry' => '365' ) );
		$this->assertTrue( $long['ok'] );
		$this->assertEqualsWithDelta( time() + 365 * DAY_IN_SECONDS, $long['expires_at'], 5 );
	}

	public function test_connection_token_mint_fires_grant_issued() {
		$result = $this->mint( array( 'access' => 'write' ) );
		$this->assertTrue( $result['ok'], $result['code'] );
		$this->assertSame(
			array( array( $result['grant_id'], $this->user_id, OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, 'token', 'connection_token' ) ),
			$this->issued
		);
		$minted = $this->audits_of( 'connection_token_minted' );
		$this->assertCount( 1, $minted );
		$this->assertSame( $result['grant_id'], $minted[0]['grant_id'] );
		$this->assertSame( 'mainwp:read mainwp:write', $this->grant( $result['grant_id'] )['scope'] );

		// A refused mint fires nothing.
		$this->mint( array( 'expiry' => 'never' ) );
		$this->assertCount( 1, $this->issued );
	}

	public function test_mint_refuses_hands_off_for_team_control_limited_user() {
		$limited = $this->limited_admin();
		wp_set_current_user( $limited );

		$refused = $this->mint(
			array(
				'access' => 'write',
				'policy' => 'hands_off',
			)
		);
		$this->assertFalse( $refused['ok'] );
		$this->assertSame( 'hands_off_not_allowed', $refused['code'] );
		$this->assertSame( array(), $this->store->connection_tokens_for_user( $limited ) );
		$this->assertStringNotContainsString( 'value="hands_off"', $this->admin()->render(), 'not offered either' );

		$this->assertTrue(
			$this->mint(
				array(
					'access' => 'write',
					'policy' => 'standard',
				)
			)['ok']
		);

		wp_set_current_user( $this->user_id );
		$this->assertStringContainsString( 'value="hands_off"', $this->admin()->render() );
		$full = $this->mint(
			array(
				'access' => 'write',
				'policy' => 'hands_off',
			)
		);
		$this->assertTrue( $full['ok'], $full['code'] );
		$this->assertSame( 'hands_off', $this->grant( $full['grant_id'] )['approval_policy'] );
	}
	public function test_per_app_connection_token_snippet_fields_render_once() {
		$result = $this->mint();
		$this->assertTrue( $result['ok'] );
		$html = html_entity_decode( $this->admin()->render( $result ), ENT_QUOTES, 'UTF-8' );
		foreach ( array(
			'AnythingLLM' => 'streamable',
			'5ire'        => 'Authorization',
			'Msty'        => 'Authorization',
			'Roo Code'    => 'streamable-http',
			'LM Studio'   => 'mcpServers',
			'Jan'         => 'headers',
		) as $app => $field ) {
			$snippet = $result['snippets'][ $app ] ?? '';
			$this->assertStringContainsString( $field, $snippet, $app );
			$this->assertStringContainsString( self::RESOURCE, $snippet, $app );
			$this->assertStringContainsString( 'Bearer ' . $result['secret'], $snippet, $app );
			$this->assertStringContainsString( $snippet, $html, $app . ' rendered' );
		}
		$this->assertStringNotContainsString( $result['secret'], $this->admin()->render(), 'token snippets are shown once' );
	}
}
