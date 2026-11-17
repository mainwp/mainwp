<?php
/**
 * Remote MCP connection token store tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Connection_Token
 */
class Test_MainWP_OAuth_Connection_Token extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';
	const DAY       = 86400;

	/**
	 * Mint a token for the default user.
	 *
	 * @param string $label   Label.
	 * @param int    $days    Lifetime in days.
	 * @param string $scope   Scope.
	 * @param int    $user_id Owner.
	 * @return array
	 */
	private function mint( string $label = 'LM Studio', int $days = 90, string $scope = 'mainwp:read', int $user_id = 0 ): array {
		return $this->store->mint_connection_token( $user_id ? $user_id : $this->user_id, $scope, 'standard', $label, $this->clock->now + $days * self::DAY );
	}

	/**
	 * Whether a connection token authenticates.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private function connect_ok( string $token ): bool {
		return OAuth_Store::OK === $this->store->check_connect_token( $token )['outcome'];
	}

	public function test_mint_connection_token_publishes_grant_and_single_connect_row() {
		$expires = $this->clock->now + 90 * self::DAY;
		$minted  = $this->store->mint_connection_token( $this->user_id, 'mainwp:read mainwp:write', 'strict', 'LM Studio', $expires );

		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$this->assertMatchesRegularExpression( '/^mwpct_[A-Za-z0-9_-]{43}$/', $minted['token'] );
		$this->assertSame( $expires, $minted['expires_at'] );

		$grant = $this->grant( $minted['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertSame( OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, $grant['client_id'] );
		$this->assertSame( 'mainwp-connection-token', $grant['client_id'] );
		$this->assertSame( 'token', $grant['client_kind'] );
		$this->assertSame( 'LM Studio', $grant['label'] );
		$this->assertSame( 'mainwp:read mainwp:write', $grant['scope'] );
		$this->assertSame( 'strict', $grant['approval_policy'] );
		$this->assertSame( '1', $grant['policy_version'] );
		$this->assertSame( (string) $expires, $grant['session_expires_at'] );
		$this->assertSame( array( '1', '1', '1' ), array( $grant['pair_seq'], $grant['current_pair'], $grant['version'] ) );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertNull( $grant['previous_pair'] );

		$tokens = $this->rows( 'tokens', 'grant_id = ' . (int) $minted['grant_id'] );
		$this->assertCount( 1, $tokens, 'one connect row and nothing else' );
		$this->assertSame( 'connect', $tokens[0]['token_type'] );
		$this->assertSame( '1', $tokens[0]['pair'] );
		$this->assertSame( (string) $expires, $tokens[0]['expires_at'] );
		$this->assertSame( $this->hasher->hash( $minted['token'], self::RESOURCE ), $tokens[0]['token_hash'] );

		$check = $this->store->check_connect_token( $minted['token'] );
		$this->assertSame( OAuth_Store::OK, $check['outcome'] );
		$this->assertSame( $minted['grant_id'], $check['grant_id'] );
		$this->assertSame( $this->user_id, $check['user_id'] );
		$this->assertSame( 'mainwp:read mainwp:write', $check['scope'] );
		$this->assertSame( 'token', $check['client_kind'] );
		$this->assertSame( 'strict', $check['approval_policy'] );

		for ( $i = 2; $i <= 25; $i++ ) {
			$this->assertSame( OAuth_Store::OK, $this->mint( 'App ' . $i )['outcome'], 'token ' . $i );
		}
		$this->assertCount( 25, $this->rows( 'grants', "status = 'live' AND client_id = 'mainwp-connection-token'" ) );
		$this->assertTrue( $this->connect_ok( $minted['token'] ), 'the oldest still works' );
		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( OAuth_Store::OK, $this->mint( 'Other', 90, 'mainwp:read', $other_user )['outcome'], 'another user can mint tokens' );

		// Publication is the generation-checked statement code exchange uses.
		$racing = $this->new_store();
		$racing->on(
			'before_publish',
			function () use ( $other_user ) {
				$this->store->bump_user_generation( $other_user, 'password_changed' );
			}
		);
		$lost = $racing->mint_connection_token( $other_user, 'mainwp:read', 'standard', 'Racing', $this->clock->now + self::DAY );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $lost['outcome'] );
		$this->assertArrayNotHasKey( 'token', $lost );
		$this->assertCount( 0, $this->rows( 'grants', "label = 'Racing' AND status = 'live'" ) );

		// Only the choices the panel offers are accepted.
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->mint( 'Bad', 90, 'mainwp:write' )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->mint( 'Bad', 90, 'mainwp:read offline_access' )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->mint( 'Bad', 366 )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->mint( 'Bad', 0 )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_REQUEST, $this->store->mint_connection_token( $other_user, 'mainwp:read', 'yolo', 'Bad', $this->clock->now + self::DAY )['outcome'] );
	}

	public function test_connect_token_refused_after_revoke_expiry_generation_bump_and_secret_rotation() {
		$revoked = $this->mint( 'Revoked' );
		$this->assertTrue( $this->connect_ok( $revoked['token'] ) );
		$this->assertTrue( $this->store->revoke_grant( $revoked['grant_id'], 'manual' ) );
		$this->assertFalse( $this->connect_ok( $revoked['token'] ), 'revoked' );

		$expiring = $this->mint( 'Expiring', 30 );
		$this->tick( 30 * self::DAY - 1 );
		$this->assertTrue( $this->connect_ok( $expiring['token'] ) );
		$this->tick( 1 );
		$this->assertFalse( $this->connect_ok( $expiring['token'] ), 'expired' );

		$bumped = $this->mint( 'Bumped' );
		$this->assertTrue( $this->connect_ok( $bumped['token'] ) );
		$this->store->bump_generation( $this->user_id );
		$this->assertFalse( $this->connect_ok( $bumped['token'] ), 'user generation moved, even before the revoke statement' );

		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site       = $this->mint( 'Site', 90, 'mainwp:read', $other_user );
		$this->assertTrue( $this->connect_ok( $site['token'] ) );
		$this->store->bump_generation( 0 );
		$this->assertFalse( $this->connect_ok( $site['token'] ), 'site generation moved' );

		$rotated = $this->mint( 'Rotated', 90, 'mainwp:read', $other_user );
		$this->assertTrue( $this->connect_ok( $rotated['token'] ) );
		$this->store->rotate_secret();
		$this->assertFalse( $this->connect_ok( $rotated['token'] ), 'secret rotated' );

		$this->assertSame( OAuth_Store::OK, $this->mint( 'After', 90, 'mainwp:read', $other_user )['outcome'], 'new tokens work after rotation' );
	}

	public function test_connection_tokens_expire_without_a_per_user_cap() {
		for ( $i = 1; $i <= 25; $i++ ) {
			$short = $this->mint( 'Short ' . $i, 30 );
			$this->assertSame( OAuth_Store::OK, $short['outcome'] );
		}
		$this->assertSame( OAuth_Store::OK, $this->mint( 'Another' )['outcome'] );

		// Expired grants remain stored until the purge.
		$this->tick( 30 * self::DAY );
		$this->assertFalse( $this->connect_ok( $short['token'] ), 'expired tokens cannot authenticate' );
		$this->assertCount( 26, $this->rows( 'grants', "status = 'live' AND client_id = 'mainwp-connection-token'" ) );
		$replacement = $this->mint( 'Replacement' );
		$this->assertSame( OAuth_Store::OK, $replacement['outcome'] );
		$this->assertTrue( $this->connect_ok( $replacement['token'] ) );
	}

	public function test_connect_token_never_rotates_or_refreshes() {
		$minted   = $this->mint();
		$before   = $this->grant( $minted['grant_id'] );
		$presents = array(
			$this->store->refresh( $minted['token'], OAuth_Store::CONNECTION_TOKEN_CLIENT_ID ),
			$this->refresh( $minted['token'] ),
		);
		foreach ( $presents as $result ) {
			$this->assertSame( OAuth_Store::INVALID_GRANT, $result['outcome'] );
			$this->assertArrayNotHasKey( 'access_token', $result );
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$this->tick( 600 );
			$this->assertTrue( $this->connect_ok( $minted['token'] ) );
		}
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $this->store->check_access_token( $minted['token'] )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->store->redeem_code( $minted['token'], OAuth_Store::CONNECTION_TOKEN_CLIENT_ID, self::VERIFIER )['outcome'] );

		$after = $this->grant( $minted['grant_id'] );
		foreach ( array( 'status', 'version', 'pair_seq', 'current_pair', 'current_used', 'sibling_pair', 'previous_pair', 'previous_rotated_at', 'grace_used', 'session_expires_at' ) as $column ) {
			$this->assertSame( $before[ $column ], $after[ $column ], $column . ' unchanged' );
		}
		$this->assertCount( 1, $this->rows( 'tokens', 'grant_id = ' . (int) $minted['grant_id'] ) );

		// An access token can't authenticate as a connection token either.
		$oauth = $this->connect();
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $this->store->check_connect_token( $oauth['access_token'] )['outcome'] );
		$this->assertFalse( $this->hasher->matches_type( $minted['token'], OAuth_Token_Hasher::TYPE_REFRESH ) );
	}
}
