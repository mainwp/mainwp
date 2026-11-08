<?php
/**
 * Remote MCP token store transition tests.
 *
 * Concurrency is proven in process: a seam on the store under test runs the competing call at
 * the race point under test, and each test asserts one winner and the resulting rows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Token_Store
 */
class Test_MainWP_OAuth_Token_Store extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_code_redemption_is_single_winner_under_concurrency() {
		$code       = $this->issue_code();
		$competitor = $this->new_store();
		$inner      = null;
		$this->store->on(
			'after_code_read',
			function () use ( $code, $competitor, &$inner ) {
				$inner = $this->redeem( $code, $competitor );
			}
		);

		$outer = $this->redeem( $code );

		$this->assertIsArray( $inner );
		$this->assertSame( OAuth_Store::OK, $inner['outcome'], 'the competitor claimed first' );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $outer['outcome'], 'the second claim loses' );
		$this->assertArrayNotHasKey( 'access_token', $outer );

		// The loser deleted its pending grant; the code names the winner's grant.
		$grants = $this->rows( 'grants' );
		$this->assertCount( 1, $grants );
		$this->assertSame( (string) $inner['grant_id'], $grants[0]['id'] );
		$codes = $this->rows( 'codes' );
		$this->assertSame( 'redeemed', $codes[0]['status'] );
		$this->assertSame( (string) $inner['grant_id'], $codes[0]['grant_id'] );

		// Both presentations were valid, so the second is a valid replay and ends the family.
		$this->assertSame( 'code_replay', $outer['event'] );
		$this->assertSame( 'revoked', $grants[0]['status'] );
		$this->assertSame( 'code_replay', $grants[0]['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $inner['access_token'] ) );
	}

	public function test_code_redemption_fails_after_expiry() {
		// Presented at its expiry second: refused before any grant row is written.
		$code = $this->issue_code();
		$this->tick( 120 );
		$late = $this->redeem( $code );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $late['outcome'] );
		$this->assertCount( 0, $this->rows( 'grants' ) );

		// Expiring between the lookup and the claim: the claim statement itself refuses it.
		$racing = $this->issue_code();
		$this->store->on(
			'after_code_read',
			function () {
				$this->tick( 120 );
			}
		);
		$result = $this->redeem( $racing );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $result['outcome'] );
		$this->assertCount( 0, $this->rows( 'grants' ), 'the pending grant was removed' );
		foreach ( $this->rows( 'codes' ) as $row ) {
			$this->assertSame( 'live', $row['status'] );
			$this->assertNull( $row['grant_id'] );
		}

		// One second before expiry still works.
		$fresh = $this->issue_code();
		$this->tick( 119 );
		$this->assertSame( OAuth_Store::OK, $this->redeem( $fresh )['outcome'] );
	}

	public function test_valid_replay_of_redeemed_code_revokes_its_grant() {
		$code  = $this->issue_code();
		$first = $this->redeem( $code );
		$this->assertSame( OAuth_Store::OK, $first['outcome'] );

		$other  = $this->connect();
		$before = $this->rows( 'grants' );
		$this->assertSame(
			array( 'outcome' => OAuth_Store::INVALID_GRANT, 'event' => null ),
			$this->redeem( 'mwpac_' . str_repeat( 'A', 43 ) ),
			'an unknown code returns no identity'
		);
		$this->assertSame( $before, $this->rows( 'grants' ), 'an unknown code changes no grant' );

		// Replays with invalid parameters revoke nothing: a stolen code alone can't end the connection.
		$invalid = array(
			'verifier'     => array( self::CLIENT_ID, 'wrong-verifier-0123456789-abcdefghijklmnopqrst', null, null ),
			'client'       => array( 'https://evil.example.com/client.json', self::VERIFIER, null, null ),
			'redirect_uri' => array( self::CLIENT_ID, self::VERIFIER, 'https://claude.ai/other', null ),
			'resource'     => array( self::CLIENT_ID, self::VERIFIER, null, 'https://other.example.org/mcp' ),
		);
		foreach ( $invalid as $label => $args ) {
			$result = $this->store->redeem_code( $code, ...$args );
			$this->assertSame( array( 'outcome' => OAuth_Store::INVALID_GRANT, 'event' => 'code_replay_invalid' ), $result, $label . ': no identity' );
			$this->assertSame( $before, $this->rows( 'grants' ), $label . ': no grant changes' );
		}
		$this->assertTrue( $this->access_ok( $first['access_token'] ) );

		// A valid replay, with redirect_uri and resource omitted, revokes the grant.
		$replay = $this->redeem( $code );
		$expected = array(
			'outcome'   => OAuth_Store::INVALID_GRANT,
			'event'     => 'code_replay',
			'grant_id'  => $first['grant_id'],
			'user_id'   => $this->user_id,
			'client_id' => self::CLIENT_ID,
		);
		$this->assertSame( $expected, $replay, 'confirmed replay returns only safe grant identity' );
		$this->assertSame( $expected, $this->redeem( $code ), 'a repeated replay keeps the same safe identity' );
		$this->assertSame( $before[1], $this->grant( $other['grant_id'] ), 'another grant stays unchanged' );
		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'code_replay', $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $first['access_token'] ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $first['refresh_token'] )['outcome'] );
	}

	public function test_code_replay_during_grant_creation_revokes_eventual_tokens() {
		$code       = $this->issue_code();
		$competitor = $this->new_store();
		$replay     = null;
		$minted     = null;
		$this->store->on(
			'after_claim',
			function () use ( $code, $competitor, &$replay ) {
				$replay = $this->redeem( $code, $competitor );
			}
		);
		$this->store->on(
			'before_publish',
			function ( $grant_id, $tokens ) use ( &$minted ) {
				$minted = $tokens;
			}
		);

		$winner = $this->redeem( $code );

		$this->assertIsArray( $replay );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
		$this->assertSame( 'code_replay', $replay['event'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $winner['outcome'], 'the claimant never hands out tokens' );
		$this->assertArrayNotHasKey( 'access_token', $winner );

		$grants = $this->rows( 'grants' );
		$this->assertCount( 1, $grants );
		$this->assertSame( 'revoked', $grants[0]['status'] );
		$this->assertSame( 'code_replay', $grants[0]['revoke_reason'] );

		// Pair 1 was inserted, but it can never work.
		$this->assertIsArray( $minted );
		$this->assertSame( array( 'access:1' => 1, 'refresh:1' => 1 ), $this->pair_counts( (int) $grants[0]['id'] ) );
		$this->assertFalse( $this->access_ok( $minted['access_token'] ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $minted['refresh_token'] )['outcome'] );
	}

	public function test_pending_grant_tokens_are_unusable_until_published() {
		$code    = $this->issue_code();
		$checker = $this->new_store();
		$during  = null;
		$this->store->on(
			'before_publish',
			function ( $grant_id, $tokens ) use ( $checker, &$during ) {
				$during = array(
					'status'  => $this->grant( $grant_id )['status'],
					'access'  => $checker->check_access_token( $tokens['access_token'] )['outcome'],
					'refresh' => $this->refresh( $tokens['refresh_token'], $checker )['outcome'],
					'tokens'  => $tokens,
				);
			}
		);

		$result = $this->redeem( $code );

		$this->assertIsArray( $during );
		$this->assertSame( 'pending', $during['status'] );
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $during['access'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $during['refresh'] );

		$this->assertSame( OAuth_Store::OK, $result['outcome'] );
		$this->assertSame( $during['tokens']['access_token'], $result['access_token'] );
		$this->assertSame( 'live', $this->grant( $result['grant_id'] )['status'] );
		$this->assertTrue( $this->access_ok( $result['access_token'] ) );
	}

	public function test_refresh_rotation_is_single_winner_under_concurrency() {
		$first      = $this->connect();
		$competitor = $this->new_store();
		$inner      = null;
		$this->store->on(
			'after_grant_read',
			function () use ( $first, $competitor, &$inner ) {
				$inner = $this->refresh( $first['refresh_token'], $competitor );
			}
		);

		$outer = $this->refresh( $first['refresh_token'] );

		$this->assertIsArray( $inner );
		$this->assertSame( OAuth_Store::OK, $inner['outcome'] );
		$this->assertSame( 2, $inner['pair'], 'the competitor rotated' );
		$this->assertSame( OAuth_Store::OK, $outer['outcome'] );
		$this->assertSame( 3, $outer['pair'], 'the loser re-read and got the grace sibling, not a second rotation' );

		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( '1', $grant['previous_pair'] );
		$this->assertSame( '2', $grant['current_pair'] );
		$this->assertSame( '3', $grant['sibling_pair'] );
		$this->assertSame( '3', $grant['pair_seq'] );
		$this->assertSame( '1', $grant['grace_used'] );
		$this->assertSame(
			array(
				'access:1'  => 1,
				'access:2'  => 1,
				'access:3'  => 1,
				'refresh:1' => 1,
				'refresh:2' => 1,
				'refresh:3' => 1,
			),
			$this->pair_counts( $first['grant_id'] )
		);
	}

	public function test_duplicate_refresh_inside_grace_returns_one_sibling() {
		$first   = $this->connect();
		$rotated = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $rotated['outcome'] );
		$this->tick( 10 );

		$competitor = $this->new_store();
		$inner      = null;
		$this->store->on(
			'after_grace_check',
			function () use ( $first, $competitor, &$inner ) {
				$inner = $this->refresh( $first['refresh_token'], $competitor );
			}
		);
		$outer = $this->refresh( $first['refresh_token'] );

		$this->assertIsArray( $inner );
		$this->assertSame( OAuth_Store::OK, $inner['outcome'] );
		$this->assertSame( 3, $inner['pair'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $outer['outcome'] );
		$this->assertSame( 'refresh_duplicate_late', $outer['event'] );

		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertSame( '3', $grant['sibling_pair'] );
		$this->assertSame( '3', $grant['pair_seq'] );
		$this->assertSame( '1', $grant['grace_used'] );
		$this->assertArrayNotHasKey( 'access:4', $this->pair_counts( $first['grant_id'] ) );
		$this->assertSame( 1, $this->pair_counts( $first['grant_id'] )['refresh:3'] );

		// The twins are both accepted until one of them is used.
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $first['refresh_token'] )['outcome'] );
		$this->assertSame( 'live', $this->grant( $first['grant_id'] )['status'] );
	}

	public function test_duplicate_inside_window_after_grace_or_successor_use_keeps_family() {
		// After the grace sibling was issued.
		$a        = $this->connect();
		$a_next   = $this->refresh( $a['refresh_token'] );
		$a_twin   = $this->refresh( $a['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $a_next['outcome'] );
		$this->assertSame( OAuth_Store::OK, $a_twin['outcome'] );
		$this->tick( 5 );
		$late = $this->refresh( $a['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $late['outcome'] );
		$this->assertSame( 'refresh_duplicate_late', $late['event'] );
		$grant = $this->grant( $a['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertNull( $grant['revoke_reason'] );
		$this->assertTrue( $this->access_ok( $a_twin['access_token'] ) );

		// After the successor pair was used.
		$b      = $this->connect();
		$b_next = $this->refresh( $b['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $b_next['outcome'] );
		$this->assertTrue( $this->access_ok( $b_next['access_token'] ) );
		$this->tick( 5 );
		$late = $this->refresh( $b['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $late['outcome'] );
		$this->assertSame( 'refresh_duplicate_late', $late['event'] );
		$grant = $this->grant( $b['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertTrue( $this->access_ok( $b_next['access_token'] ) );
		$this->assertSame( OAuth_Store::OK, $this->refresh( $b_next['refresh_token'] )['outcome'] );
	}

	public function test_refresh_replay_after_grace_revokes_whole_family() {
		// 30 seconds after rotation is still inside the window.
		$edge = $this->connect();
		$this->assertSame( OAuth_Store::OK, $this->refresh( $edge['refresh_token'] )['outcome'] );
		$this->tick( 30 );
		$this->assertSame( OAuth_Store::OK, $this->refresh( $edge['refresh_token'] )['outcome'] );

		$first   = $this->connect();
		$rotated = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $rotated['outcome'] );
		$this->tick( 31 );

		$replay = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
		$this->assertSame( 'refresh_replay', $replay['event'] );

		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'refresh_replay', $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $rotated['access_token'] ) );
		$this->assertFalse( $this->access_ok( $first['access_token'] ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $rotated['refresh_token'] )['outcome'] );
		$this->assertSame( 'live', $this->grant( $edge['grant_id'] )['status'], 'other families are untouched' );
	}

	public function test_first_use_of_either_twin_drops_the_other() {
		// The sibling is used first, by access.
		$a      = $this->connect();
		$a_next = $this->refresh( $a['refresh_token'] );
		$a_twin = $this->refresh( $a['refresh_token'] );
		$this->assertSame( 2, $a_next['pair'] );
		$this->assertSame( 3, $a_twin['pair'] );
		$this->assertTrue( $this->access_ok( $a_twin['access_token'] ) );
		$grant = $this->grant( $a['grant_id'] );
		$this->assertSame( '3', $grant['current_pair'] );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertFalse( $this->access_ok( $a_next['access_token'] ) );

		// The rotated pair is used first, by refresh.
		$b      = $this->connect();
		$b_next = $this->refresh( $b['refresh_token'] );
		$b_twin = $this->refresh( $b['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $b_twin['outcome'] );
		$after = $this->refresh( $b_next['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $after['outcome'] );
		$this->assertSame( 4, $after['pair'] );
		$grant = $this->grant( $b['grant_id'] );
		$this->assertSame( '2', $grant['previous_pair'] );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertFalse( $this->access_ok( $b_twin['access_token'] ) );
	}

	public function test_successor_use_racing_grace_cannot_create_live_sibling() {
		$first      = $this->connect();
		$next       = $this->refresh( $first['refresh_token'] );
		$competitor = $this->new_store();
		$used       = null;
		$this->assertSame( OAuth_Store::OK, $next['outcome'] );
		$this->store->on(
			'after_grace_check',
			function () use ( $next, $competitor, &$used ) {
				$used = $this->access_ok( $next['access_token'], $competitor );
			}
		);

		$duplicate = $this->refresh( $first['refresh_token'] );

		$this->assertTrue( $used, 'the successor was used between the grace check and its update' );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $duplicate['outcome'] );
		$this->assertSame( 'refresh_duplicate_late', $duplicate['event'] );
		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertSame( '2', $grant['pair_seq'] );
		$this->assertSame( '1', $grant['current_used'] );
		$this->assertArrayNotHasKey( 'access:3', $this->pair_counts( $first['grant_id'] ) );
	}

	public function test_access_use_selects_matching_refresh_pair() {
		// Access use of the sibling makes the sibling's refresh token the one that rotates.
		$a      = $this->connect();
		$a_next = $this->refresh( $a['refresh_token'] );
		$a_twin = $this->refresh( $a['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $a_twin['outcome'] );
		$this->assertTrue( $this->access_ok( $a_twin['access_token'] ) );
		$rotated = $this->refresh( $a_twin['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $rotated['outcome'] );
		$this->assertSame( 4, $rotated['pair'] );
		$this->assertSame( '3', $this->grant( $a['grant_id'] )['previous_pair'] );

		// And the dropped twin's refresh token is no longer accepted.
		$b      = $this->connect();
		$b_next = $this->refresh( $b['refresh_token'] );
		$b_twin = $this->refresh( $b['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $b_twin['outcome'] );
		$this->assertTrue( $this->access_ok( $b_twin['access_token'] ) );
		$dropped = $this->refresh( $b_next['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $dropped['outcome'] );
		$this->assertSame( 'refresh_replay', $dropped['event'] );
	}

	public function test_version_conflict_rereads_and_gives_up_after_three_attempts() {
		global $wpdb;
		$grants = Remote_MCP_Install::table( 'grants' );

		foreach ( array( 'refresh', 'access' ) as $kind ) {
			$result = $this->connect();
			$before = $this->grant( $result['grant_id'] );
			$reads  = 0;
			$this->store->on(
				'after_grant_read',
				function () use ( $wpdb, $grants, $result, &$reads ) {
					++$reads;
					$wpdb->query( $wpdb->prepare( "UPDATE `{$grants}` SET version = version + 1 WHERE id = %d", $result['grant_id'] ) );
				},
				4
			);

			$outcome = 'refresh' === $kind
				? $this->refresh( $result['refresh_token'] )
				: $this->store->check_access_token( $result['access_token'] );

			$this->assertSame( OAuth_Store::RETRY, $outcome['outcome'], $kind );
			$this->assertSame( 4, $reads, $kind . ': one read plus three re-reads' );
			$after = $this->grant( $result['grant_id'] );
			$this->assertSame( (int) $before['version'] + 4, (int) $after['version'], $kind . ': only the competitor wrote' );
			$this->assertSame( $before['pair_seq'], $after['pair_seq'] );
			$this->assertSame( $before['current_used'], $after['current_used'] );
			$this->assertSame( array( 'access:1' => 1, 'refresh:1' => 1 ), $this->pair_counts( $result['grant_id'] ) );
		}
	}

	public function test_rotation_never_extends_absolute_session_lifetime() {
		$first   = $this->connect();
		$session = (int) $this->grant( $first['grant_id'] )['session_expires_at'];
		$this->assertSame( $this->clock->now + 30 * DAY_IN_SECONDS, $session );

		$token = $first['refresh_token'];
		for ( $i = 0; $i < 5; $i++ ) {
			$this->tick( 5 * DAY_IN_SECONDS );
			$next = $this->refresh( $token );
			$this->assertSame( OAuth_Store::OK, $next['outcome'] );
			$token = $next['refresh_token'];
			$this->assertSame( (string) $session, $this->grant( $first['grant_id'] )['session_expires_at'] );
		}

		foreach ( $this->rows( 'tokens', 'grant_id = ' . (int) $first['grant_id'] ) as $row ) {
			$this->assertLessThanOrEqual( $session, (int) $row['expires_at'] );
		}
		// Day 25: a new refresh token would run 14 days, so the session end caps it.
		$last = $this->rows( 'tokens', "token_type = 'refresh' AND pair = 6 AND grant_id = " . (int) $first['grant_id'] );
		$this->assertSame( (string) $session, $last[0]['expires_at'] );
	}

	public function test_refresh_refused_after_session_end() {
		global $wpdb;
		$first = $this->connect();
		$grant = $this->grant( $first['grant_id'] );

		// Pull the session end in, leaving both tokens unexpired, to show the session check stands alone.
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . Remote_MCP_Install::table( 'grants' ) . '` SET session_expires_at = %d WHERE id = %d', $this->clock->now + 60, $first['grant_id'] ) );
		$this->tick( 59 );
		$this->assertTrue( $this->access_ok( $first['access_token'] ) );

		$this->tick( 1 );
		$this->assertFalse( $this->access_ok( $first['access_token'] ) );
		$refused = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $refused['outcome'] );
		$after = $this->grant( $first['grant_id'] );
		$this->assertSame( $grant['pair_seq'], $after['pair_seq'], 'nothing was minted' );
		$this->assertSame( 'live', $after['status'], 'an ended session is not a replay' );
	}

	public function test_access_expiry_capped_by_session_end() {
		$first   = $this->connect();
		$session = (int) $this->grant( $first['grant_id'] )['session_expires_at'];
		$this->assertSame( $this->clock->now + 30 * MINUTE_IN_SECONDS, (int) $this->store->lookup_token( $first['access_token'], 'access' )['token_expires_at'] );

		$this->tick( 13 * DAY_IN_SECONDS );
		$token = $this->refresh( $first['refresh_token'] )['refresh_token'];
		$this->tick( 13 * DAY_IN_SECONDS );
		$token = $this->refresh( $token )['refresh_token'];
		$this->clock->now = $session - 600;
		$last             = $this->refresh( $token );

		$this->assertSame( OAuth_Store::OK, $last['outcome'] );
		$this->assertSame( 600, $last['expires_in'] );
		$row = $this->store->lookup_token( $last['access_token'], 'access' );
		$this->assertSame( $session, (int) $row['token_expires_at'] );

		$this->clock->now = $session - 1;
		$this->assertTrue( $this->access_ok( $last['access_token'] ) );
		$this->clock->now = $session;
		$this->assertFalse( $this->access_ok( $last['access_token'] ) );
	}

	public function test_live_grant_cap_per_user_and_client_revokes_oldest() {
		$other_user   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_client = array(
			'client_id'   => 'https://chatgpt.com/oauth/client.json',
			'client_host' => 'chatgpt.com',
		);
		$ids          = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->connect()['grant_id'];
			$this->tick( 1 );
		}
		$other_client_grant = $this->connect( 0, $other_client )['grant_id'];
		$other_user_grant   = $this->connect( $other_user )['grant_id'];
		foreach ( $ids as $id ) {
			$this->assertSame( 'live', $this->grant( $id )['status'] );
		}

		$sixth = $this->connect();

		$oldest = $this->grant( $ids[0] );
		$this->assertSame( 'revoked', $oldest['status'] );
		$this->assertSame( 'cap_limit', $oldest['revoke_reason'] );
		foreach ( array_merge( array_slice( $ids, 1 ), array( $sixth['grant_id'], $other_client_grant, $other_user_grant ) ) as $id ) {
			$this->assertSame( 'live', $this->grant( $id )['status'], 'grant ' . $id );
		}
		$this->assertCount( 5, $this->rows( 'grants', "status = 'live' AND user_id = " . (int) $this->user_id . " AND client_id = '" . self::CLIENT_ID . "'" ) );
	}

	public function test_at_most_three_pairs_accept_access_tokens() {
		$p1 = $this->connect();
		$p2 = $this->refresh( $p1['refresh_token'] );
		$p3 = $this->refresh( $p1['refresh_token'] );
		$this->assertSame( 3, $p3['pair'] );
		$grant = $this->grant( $p1['grant_id'] );
		$this->assertSame( array( '2', '3', '1' ), array( $grant['current_pair'], $grant['sibling_pair'], $grant['previous_pair'] ) );

		// Previous pair: accepted without selecting anything.
		$this->assertTrue( $this->access_ok( $p1['access_token'] ) );
		// Using the sibling drops pair 2.
		$this->assertTrue( $this->access_ok( $p3['access_token'] ) );
		$this->assertFalse( $this->access_ok( $p2['access_token'] ) );

		$p4 = $this->refresh( $p3['refresh_token'] );
		$this->assertSame( 4, $p4['pair'] );

		// Pair 1 is still within its own 30 minutes, but no longer an accepted pair.
		$this->assertFalse( $this->access_ok( $p1['access_token'] ) );
		$this->assertFalse( $this->access_ok( $p2['access_token'] ) );
		$this->assertTrue( $this->access_ok( $p3['access_token'] ) );
		$this->assertTrue( $this->access_ok( $p4['access_token'] ) );
	}

	public function test_access_lookup_refuses_token_of_revoked_grant() {
		$first = $this->connect();
		$this->assertTrue( $this->access_ok( $first['access_token'] ) );
		$this->tick( 7 );

		$this->assertTrue( $this->store->revoke_grant( $first['grant_id'], 'manual' ) );
		$this->assertFalse( $this->store->revoke_grant( $first['grant_id'], 'manual' ), 'already revoked' );

		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'manual', $grant['revoke_reason'] );
		$this->assertSame( (string) $this->clock->now, $grant['revoked_at'] );
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $this->store->check_access_token( $first['access_token'] )['outcome'] );
		$refused = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $refused['outcome'] );
	}

	public function test_purge_deletes_expired_rows_and_keeps_live_ones() {
		$start = $this->clock->now;

		// Live family: pair 1 rotates away, pair 2 becomes previous, pair 3 current.
		$live = $this->connect();
		// Revoked family.
		$revoked = $this->connect();
		$this->store->revoke_grant( $revoked['grant_id'], 'manual' );
		// A burned code (and its issue_failed grant) and an expired unredeemed code.
		$burned = $this->issue_code();
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->store->redeem_code( $burned, self::CLIENT_ID, 'wrong-verifier-0123456789-abcdefghijklmnopqrst' )['outcome'] );
		$this->issue_code();
		// An open consent request that expires.
		$this->store->create_request( $this->request_params() );

		$this->tick( DAY_IN_SECONDS );
		$p2 = $this->refresh( $live['refresh_token'] );
		$this->tick( DAY_IN_SECONDS );
		$p3 = $this->refresh( $p2['refresh_token'] );
		$this->assertSame( 3, $p3['pair'] );

		$this->clock->now = $start + 3 * DAY_IN_SECONDS;
		$fresh_request    = $this->store->create_request( $this->request_params() );
		$fresh_code       = $this->issue_code();

		$this->store->purge();

		$live_id    = (int) $live['grant_id'];
		$revoked_id = (int) $revoked['grant_id'];
		$this->assertSame( array( 'access:2' => 1, 'access:3' => 1, 'refresh:1' => 1, 'refresh:2' => 1, 'refresh:3' => 1 ), $this->pair_counts( $live_id ) );
		$this->assertSame( array( 'refresh:1' => 1 ), $this->pair_counts( $revoked_id ), 'expired access of a revoked family goes after 24 h' );
		$this->assertCount( 3, $this->rows( 'grants' ) );
		$codes = $this->rows( 'codes' );
		$this->assertSame( array( 'redeemed', 'redeemed', 'live' ), array_column( $codes, 'status' ), 'burned and expired codes are gone' );
		$requests = $this->rows( 'requests' );
		$this->assertSame( array( (string) $fresh_request['id'], (string) $codes[2]['request_id'] ), array_column( $requests, 'id' ) );
		$this->assertSame( OAuth_Store::OK, $this->redeem( $fresh_code )['outcome'] );
		$generations = $this->rows( 'generations' );

		// 30 days after revocation the revoked families go with every row they own.
		$this->clock->now = $start + 30 * DAY_IN_SECONDS + 1;
		$this->store->purge();
		$this->assertSame( array(), $this->pair_counts( $revoked_id ) );
		$remaining = array_column( $this->rows( 'grants' ), 'id' );
		$this->assertContains( (string) $live_id, $remaining );
		$this->assertNotContains( (string) $revoked_id, $remaining );
		$this->assertCount( 2, $remaining );
		$this->assertCount( 0, $this->rows( 'codes', 'grant_id = ' . $revoked_id ) );

		// 30 days after its session ended the live family goes too; generation rows never do.
		$this->clock->now = $start + 60 * DAY_IN_SECONDS + 1;
		$this->store->purge();
		$this->assertSame( array(), $this->pair_counts( $live_id ) );
		$this->assertCount( 0, $this->rows( 'codes', 'grant_id = ' . $live_id ) );
		$this->assertNotContains( (string) $live_id, array_column( $this->rows( 'grants' ), 'id' ) );
		$this->assertSame( $generations, $this->rows( 'generations' ) );
	}

	public function test_generation_bump_blocks_pending_grant_publication() {
		foreach ( array( 'user' => $this->user_id, 'site' => 0 ) as $label => $subject ) {
			$code   = $this->issue_code();
			$other  = $this->new_store();
			$minted = null;
			$this->store->on(
				'before_publish',
				function ( $grant_id, $tokens ) use ( $other, $subject, &$minted ) {
					$minted = $tokens;
					// Only the bump half of a trigger: its revoke statement hasn't run yet.
					$other->bump_generation( $subject );
				}
			);

			$result = $this->redeem( $code );

			$this->assertSame( OAuth_Store::INVALID_GRANT, $result['outcome'], $label );
			$this->assertSame( 'generation_changed', $result['event'], $label );
			$this->assertArrayNotHasKey( 'access_token', $result );
			$grants = $this->rows( 'grants', "status <> 'revoked'" );
			$this->assertCount( 0, $grants, $label . ': nothing went live' );
			$last = $this->rows( 'grants' );
			$last = end( $last );
			$this->assertSame( 'generation_changed', $last['revoke_reason'], $label );
			$this->assertIsArray( $minted );
			$this->assertFalse( $this->access_ok( $minted['access_token'] ), $label );
		}
	}

	public function test_generation_bump_invalidates_open_requests_and_live_codes() {
		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$open       = $this->store->create_request( $this->request_params() );
		$others     = $this->store->create_request( $this->request_params( $other_user ) );
		$code       = $this->issue_code();

		$this->store->bump_user_generation( $this->user_id, 'password_changed' );

		$this->assertFalse( $this->store->claim_request( $open['id'], $this->user_id, true ) );
		$this->assertSame( 'pending', $this->rows( 'requests', 'id = ' . (int) $open['id'] )[0]['status'] );

		$result = $this->redeem( $code );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $result['outcome'] );
		$this->assertSame( 'issue_failed', $result['event'] );
		$this->assertSame( 'burned', $this->rows( 'codes' )[0]['status'] );
		$this->assertCount( 0, $this->rows( 'grants', "status <> 'revoked'" ) );

		// Other users are untouched, and new requests carry the new generation.
		$this->assertTrue( $this->store->claim_request( $others['id'], $other_user, false ) );
		$this->assertSame( OAuth_Store::OK, $this->redeem( $this->issue_code() )['outcome'] );
	}

	public function test_token_use_requires_current_user_and_site_generation() {
		// User generation moves without its revoke statement.
		$a = $this->connect();
		$this->assertTrue( $this->access_ok( $a['access_token'] ) );
		$this->store->bump_generation( $this->user_id );
		$this->assertFalse( $this->access_ok( $a['access_token'] ) );
		$this->assertSame( 'live', $this->grant( $a['grant_id'] )['status'] );
		$refused = $this->refresh( $a['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $refused['outcome'] );
		$this->assertSame( 'generation_changed', $this->grant( $a['grant_id'] )['revoke_reason'] );

		// Site generation the same way.
		$b = $this->connect();
		$this->assertTrue( $this->access_ok( $b['access_token'] ) );
		$this->store->bump_generation( 0 );
		$this->assertFalse( $this->access_ok( $b['access_token'] ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $b['refresh_token'] )['outcome'] );
		$this->assertSame( 'generation_changed', $this->grant( $b['grant_id'] )['revoke_reason'] );
	}

	public function test_site_generation_bump_covers_every_user() {
		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$a          = $this->connect();
		$b          = $this->connect( $other_user );
		$open_a     = $this->store->create_request( $this->request_params() );
		$open_b     = $this->store->create_request( $this->request_params( $other_user ) );
		$code_b     = $this->issue_code( $other_user );
		$site       = $this->generation( 0 );

		$this->assertSame( 2, $this->store->bump_site_generation( 'home_changed' ) );

		$this->assertSame( $site + 1, $this->generation( 0 ) );
		foreach ( array( $a, $b ) as $result ) {
			$grant = $this->grant( $result['grant_id'] );
			$this->assertSame( 'revoked', $grant['status'] );
			$this->assertSame( 'home_changed', $grant['revoke_reason'] );
			$this->assertFalse( $this->access_ok( $result['access_token'] ) );
		}
		$this->assertFalse( $this->store->claim_request( $open_a['id'], $this->user_id, true ) );
		$this->assertFalse( $this->store->claim_request( $open_b['id'], $other_user, true ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->redeem( $code_b )['outcome'] );
	}

	public function test_purge_preserves_replay_evidence_for_live_family() {
		$code_text = $this->issue_code();
		$first     = $this->redeem( $code_text );
		$this->assertSame( OAuth_Store::OK, $first['outcome'] );
		$code = $this->rows( 'codes' )[0];
		$this->tick( 10 * DAY_IN_SECONDS );
		$p2 = $this->refresh( $first['refresh_token'] );
		$this->tick( 10 * DAY_IN_SECONDS );
		$p3 = $this->refresh( $p2['refresh_token'] );
		$this->assertSame( 3, $p3['pair'] );
		// Day 29: pair 1's refresh token expired on day 14 and its access token long before.
		$this->tick( 9 * DAY_IN_SECONDS );

		$this->store->purge();

		$this->assertSame( array( 'access:2' => 1, 'access:3' => 1, 'refresh:1' => 1, 'refresh:2' => 1, 'refresh:3' => 1 ), $this->pair_counts( $first['grant_id'] ) );
		$this->assertSame( array( $code ), $this->rows( 'codes' ), 'the redeemed code is kept while its grant can be used' );

		$this->assertSame( 'live', $this->grant( $first['grant_id'] )['status'] );

		// The kept code row still turns a valid replay into a revocation.
		$replay = $this->redeem( $code_text );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
		$this->assertSame( 'code_replay', $replay['event'] );
		$this->assertSame( 'code_replay', $this->grant( $first['grant_id'] )['revoke_reason'] );
	}

	public function test_expired_rotated_refresh_replay_revokes_live_family_after_purge() {
		// An idle-expired refresh token of the current pair is plain invalid_grant.
		$idle = $this->connect();
		$this->tick( 14 * DAY_IN_SECONDS );
		$expired = $this->refresh( $idle['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $expired['outcome'] );
		$this->assertNull( $expired['event'] );
		$this->assertSame( 'live', $this->grant( $idle['grant_id'] )['status'] );

		$first = $this->connect();
		$this->tick( 10 * DAY_IN_SECONDS );
		$p2 = $this->refresh( $first['refresh_token'] );
		$this->tick( 10 * DAY_IN_SECONDS );
		$p3 = $this->refresh( $p2['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $p3['outcome'] );
		$this->tick( 9 * DAY_IN_SECONDS );
		$this->store->purge();

		// Pair 1's refresh token expired on day 14; presenting it is still a replay of the live family.
		$replay = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
		$this->assertSame( 'refresh_replay', $replay['event'] );
		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'refresh_replay', $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $p3['access_token'] ) );
	}

	public function test_grace_refresh_with_expired_presented_token_mints_no_sibling() {
		$first = $this->connect();
		$this->tick( 14 * DAY_IN_SECONDS - 1 );
		$next = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::OK, $next['outcome'] );

		// Two seconds after the rotation, inside the window, but pair 1's refresh token expired a second ago.
		$this->tick( 2 );
		$late = $this->refresh( $first['refresh_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $late['outcome'] );
		$this->assertNull( $late['event'] );
		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'live', $grant['status'] );
		$this->assertNull( $grant['sibling_pair'] );
		$this->assertSame( '2', $grant['pair_seq'] );
		$this->assertSame( '0', $grant['grace_used'] );
		$this->assertArrayNotHasKey( 'refresh:3', $this->pair_counts( $first['grant_id'] ) );

		// After the window the same expired token is replay again.
		$this->tick( 30 );
		$this->assertSame( 'refresh_replay', $this->refresh( $first['refresh_token'] )['event'] );
	}

	public function test_generation_bump_failure_is_retried_and_reported() {
		global $wpdb;
		$bump     = $this->fail_queries( 'ON DUPLICATE KEY UPDATE generation = generation + 1' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			// Two failed statements, then one that persists.
			$bump->failures = 2;
			$before         = $this->generation( $this->user_id );
			$this->assertTrue( $this->store->bump_generation( $this->user_id ) );
			$this->assertSame( 3, $bump->attempts );
			$this->assertSame( $before + 1, $this->generation( $this->user_id ) );

			// A bump that never persists: grants are still revoked, and the trigger reports the failure.
			$user_grant     = $this->connect();
			$bump->failures = PHP_INT_MAX;
			$bump->attempts = 0;
			$this->assertFalse( $this->store->bump_generation( $this->user_id ) );
			$this->assertSame( 3, $bump->attempts );
			$this->assertNull( $this->store->bump_user_generation( $this->user_id, 'password_changed' ) );
			$this->assertSame( $before + 1, $this->generation( $this->user_id ) );
			$this->assertSame( 'password_changed', $this->grant( $user_grant['grant_id'] )['revoke_reason'] );

			$site_grant = $this->connect();
			$site       = $this->generation( 0 );
			$this->assertNull( $this->store->bump_site_generation( 'home_changed' ) );
			$this->assertSame( $site, $this->generation( 0 ) );
			$this->assertSame( 'home_changed', $this->grant( $site_grant['grant_id'] )['revoke_reason'] );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_replay_revocation_failure_is_not_reported_as_invalid_grant() {
		global $wpdb;
		$revoke   = $this->fail_queries( "revoke_reason = 'code_replay'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$code  = $this->issue_code();
			$first = $this->redeem( $code );
			$this->assertSame( OAuth_Store::OK, $first['outcome'] );

			// The revoke statement keeps failing: the family may still be live, so this is a 500.
			$revoke->failures = PHP_INT_MAX;
			$replay           = $this->redeem( $code );
			$this->assertSame( OAuth_Store::SERVER_ERROR, $replay['outcome'] );
			$this->assertSame( 'code_replay', $replay['event'] );
			$this->assertSame(
				array(
					'outcome'   => OAuth_Store::SERVER_ERROR,
					'event'     => 'code_replay',
					'grant_id'  => $first['grant_id'],
					'user_id'   => $this->user_id,
					'client_id' => self::CLIENT_ID,
				),
				$replay,
				'a confirmed replay retains safe identity when revocation fails'
			);
			$this->assertSame( 3, $revoke->attempts );
			$this->assertSame( 'live', $this->grant( $first['grant_id'] )['status'] );

			// Two failures, then the retry revokes.
			$revoke->failures = 2;
			$revoke->attempts = 0;
			$replay           = $this->redeem( $code );
			$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
			$this->assertSame( 'code_replay', $replay['event'] );
			$this->assertSame( 3, $revoke->attempts );
			$this->assertSame( 'code_replay', $this->grant( $first['grant_id'] )['revoke_reason'] );

			// Already revoked: zero affected rows is not a failure.
			$revoke->attempts = 0;
			$replay           = $this->redeem( $code );
			$this->assertSame( OAuth_Store::INVALID_GRANT, $replay['outcome'] );
			$this->assertSame( 1, $revoke->attempts );

			// The refresh replay path uses the same rule.
			$refresh_revoke           = $this->fail_queries( "revoke_reason = 'refresh_replay'" );
			$refresh_revoke->failures = PHP_INT_MAX;
			$second                   = $this->connect();
			$this->assertSame( OAuth_Store::OK, $this->refresh( $second['refresh_token'] )['outcome'] );
			$this->tick( 31 );
			$replay = $this->refresh( $second['refresh_token'] );
			$this->assertSame( OAuth_Store::SERVER_ERROR, $replay['outcome'] );
			$this->assertSame( 'refresh_replay', $replay['event'] );
			$this->assertSame( 3, $refresh_revoke->attempts );
			$this->assertSame( 'live', $this->grant( $second['grant_id'] )['status'] );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_rotation_with_failed_site_bump_throws_and_retry_blocks_old_request() {
		global $wpdb;
		$this->connect();
		$open     = $this->store->create_request( $this->request_params() );
		$bump     = $this->fail_queries( 'ON DUPLICATE KEY UPDATE generation = generation + 1' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			// The pending request isn't hashed with the secret, so only the site bump can end it.
			$bump->failures = PHP_INT_MAX;
			$thrown         = null;
			try {
				$this->store->rotate_secret();
			} catch ( \RuntimeException $e ) {
				$thrown = $e;
			}
			$this->assertInstanceOf( \RuntimeException::class, $thrown, 'rotation reports the failed bump' );

			// The operator retries; the completed rotation leaves the old request unusable.
			$bump->failures = 0;
			$this->store->rotate_secret();
			$this->assertFalse( $this->store->claim_request( $open['id'], $this->user_id, true ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_cap_revocation_failure_withholds_new_tokens() {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->connect()['grant_id'];
			$this->tick( 1 );
		}
		$revoke   = $this->fail_queries( 'WHERE id = ' . $ids[0] . ' AND status IN' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$revoke->failures = PHP_INT_MAX;
			$sixth            = $this->redeem( $this->issue_code() );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( OAuth_Store::SERVER_ERROR, $sixth['outcome'] );
		$this->assertArrayNotHasKey( 'access_token', $sixth );
		$this->assertSame( 'live', $this->grant( $ids[0] )['status'], 'the oldest grant could not be revoked' );
		$this->assertCount( 5, $this->rows( 'grants', "status = 'live'" ), 'the new grant was revoked instead' );
	}

	public function test_cap_read_failure_withholds_new_tokens() {
		global $wpdb;
		$read     = $this->fail_queries( 'ORDER BY created_at DESC, id DESC' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$read->failures = PHP_INT_MAX;
			$result         = $this->redeem( $this->issue_code() );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( OAuth_Store::SERVER_ERROR, $result['outcome'] );
		$this->assertArrayNotHasKey( 'access_token', $result );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );
	}

	public function test_refresh_user_check_revoke_failure_returns_server_error() {
		global $wpdb;
		$first = $this->connect();
		get_userdata( $this->user_id )->set_role( 'subscriber' );
		$revoke   = $this->fail_queries( "revoke_reason = 'cap_lost'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$revoke->failures = PHP_INT_MAX;
			$failed           = $this->refresh( $first['refresh_token'] );
			$this->assertSame( OAuth_Store::SERVER_ERROR, $failed['outcome'] );
			$this->assertSame( 'cap_lost', $failed['event'] );
			$this->assertSame( 'live', $this->grant( $first['grant_id'] )['status'] );

			$revoke->failures = 0;
			$refused          = $this->refresh( $first['refresh_token'] );
			$this->assertSame( OAuth_Store::INVALID_GRANT, $refused['outcome'] );
			$this->assertSame( 'cap_lost', $this->grant( $first['grant_id'] )['revoke_reason'] );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_code_claim_sql_error_returns_server_error_and_keeps_code() {
		global $wpdb;
		$code     = $this->issue_code();
		$claim    = $this->fail_queries( "SET status = 'redeemed'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$claim->failures = 1;
			$failed          = $this->redeem( $code );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( OAuth_Store::SERVER_ERROR, $failed['outcome'] );
		$this->assertCount( 0, $this->rows( 'grants' ) );
		$this->assertSame( 'live', $this->rows( 'codes' )[0]['status'] );
		$this->assertSame( OAuth_Store::OK, $this->redeem( $code )['outcome'], 'the client can retry' );
	}

	public function test_publication_sql_error_returns_server_error() {
		global $wpdb;
		$code     = $this->issue_code();
		$publish  = $this->fail_queries( "SET g.status = 'live'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$publish->failures = 1;
			$result            = $this->redeem( $code );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( OAuth_Store::SERVER_ERROR, $result['outcome'] );
		$this->assertArrayNotHasKey( 'access_token', $result );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );
	}

	public function test_purge_keeps_grant_when_child_delete_fails() {
		global $wpdb;
		$first = $this->connect();
		$this->store->revoke_grant( $first['grant_id'], 'manual' );
		$this->tick( 31 * DAY_IN_SECONDS );
		$delete   = $this->fail_queries( 'DELETE FROM `' . Remote_MCP_Install::table( 'codes' ) . '` WHERE grant_id IN' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$delete->failures = 1;
			$this->store->purge();
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		// The grant row stays, so tomorrow's purge finds its codes again instead of orphaning them.
		$this->assertCount( 1, $this->rows( 'grants' ) );
		$this->assertCount( 1, $this->rows( 'codes' ) );

		$this->store->purge();
		$this->assertCount( 0, $this->rows( 'grants' ) );
		$this->assertCount( 0, $this->rows( 'codes' ) );
	}

	public function test_generation_trigger_revoke_failure_is_reported() {
		global $wpdb;
		$first    = $this->connect();
		$user     = $this->fail_queries( 'WHERE user_id = ' . $this->user_id . ' AND status IN' );
		$site     = $this->fail_queries( 'version = version + 1 WHERE status IN' );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$user->failures = PHP_INT_MAX;
			$before         = $this->generation( $this->user_id );
			$this->assertNull( $this->store->bump_user_generation( $this->user_id, 'password_changed' ) );
			$this->assertSame( 3, $user->attempts );
			$this->assertSame( $before + 1, $this->generation( $this->user_id ) );
			$this->assertFalse( $this->access_ok( $first['access_token'] ), 'the bump still ends the tokens' );

			$site->failures = PHP_INT_MAX;
			$this->assertNull( $this->store->bump_site_generation( 'home_changed' ) );
			$this->assertSame( 3, $site->attempts );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_manual_revoke_sql_error_throws() {
		global $wpdb;
		$first    = $this->connect();
		$revoke   = $this->fail_queries( "revoke_reason = 'manual'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$revoke->failures = PHP_INT_MAX;
			$thrown           = null;
			try {
				$this->store->revoke_grant( $first['grant_id'], 'manual' );
			} catch ( \RuntimeException $e ) {
				$thrown = $e;
			}
			$this->assertInstanceOf( \RuntimeException::class, $thrown );
			$this->assertSame( 3, $revoke->attempts );
			$this->assertSame( 'live', $this->grant( $first['grant_id'] )['status'] );

			$revoke->failures = 0;
			$this->assertTrue( $this->store->revoke_grant( $first['grant_id'], 'manual' ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
	}

	public function test_uninstall_with_failed_drops_and_secret_delete_leaves_no_usable_token() {
		global $wpdb;
		require_once MAINWP_PLUGIN_DIR . 'modules/remote-mcp/uninstall.php';
		$live  = $this->connect();
		$code  = $this->issue_code();
		$after = $this->generation( 0 );
		$this->assertTrue( $this->access_ok( $live['access_token'] ) );
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, Remote_MCP_Install::DB_VERSION, false );

		// A host that refuses DROP and the secret delete, but still runs other writes. The harness has
		// already rewritten DROP TABLE to DROP TEMPORARY TABLE by the time this filter sees it.
		$refuse   = function ( $query ) {
			$is_drop          = 0 === stripos( ltrim( $query ), 'DROP ' );
			$is_secret_delete = 0 === stripos( ltrim( $query ), 'DELETE' ) && false !== strpos( $query, Remote_MCP_Settings::OPTION_SECRET );
			return $is_drop || $is_secret_delete ? 'SELECT 1 FROM `remote_mcp_injected_failure`' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $refuse );
		try {
			\MainWP\Dashboard\Remote_MCP\uninstall();
		} finally {
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( $suppress );
		}
		// delete_option() caches the option as missing even when its DELETE failed.
		wp_cache_delete( Remote_MCP_Settings::OPTION_SECRET, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$this->assertIsArray( get_option( Remote_MCP_Settings::OPTION_SECRET ), 'the secret delete failed, so the old key is still there' );
		$this->assertFalse( $this->access_ok( $live['access_token'] ), 'no token survives an incomplete uninstall' );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $live['refresh_token'] )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->redeem( $code )['outcome'], 'codes issued before uninstall are dead' );
		$this->assertGreaterThan( $after, $this->generation( 0 ) );
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ), 'kept so the next uninstall retries' );
	}

	public function test_uninstall_with_failed_site_bump_still_ends_open_requests_and_codes() {
		global $wpdb;
		require_once MAINWP_PLUGIN_DIR . 'modules/remote-mcp/uninstall.php';
		$params  = $this->request_params();
		$request = $this->store->create_request( $params );
		$code    = $this->issue_code();
		update_option( Remote_MCP_Settings::OPTION_DB_VERSION, Remote_MCP_Install::DB_VERSION, false );

		// The site bump and the secret delete fail; the drops are no-ops on these real tables, so every
		// row survives as it would after a partial uninstall and a reinstall at the same address.
		$bump     = $this->fail_queries( 'VALUES (0, 1) ON DUPLICATE KEY UPDATE generation' );
		$refuse   = function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'DELETE' ) && false !== strpos( $query, Remote_MCP_Settings::OPTION_SECRET ) ? 'SELECT 1 FROM `remote_mcp_injected_failure`' : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $refuse );
		try {
			$bump->failures = PHP_INT_MAX;
			\MainWP\Dashboard\Remote_MCP\uninstall();
		} finally {
			remove_filter( 'query', $refuse );
			$bump->failures = 0;
			$wpdb->suppress_errors( $suppress );
		}
		wp_cache_delete( Remote_MCP_Settings::OPTION_SECRET, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$this->assertGreaterThan( 0, $bump->attempts, 'the bump was attempted' );
		$this->assertFalse( $this->store->claim_request( $request['id'], $params['user_id'], true ), 'an open consent request is ended' );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->redeem( $code )['outcome'], 'an unredeemed code is ended' );
		$this->assertSame( Remote_MCP_Install::DB_VERSION, get_option( Remote_MCP_Settings::OPTION_DB_VERSION ), 'kept so the next uninstall retries' );
	}

	public function test_live_grant_cap_compares_client_id_byte_for_byte() {
		$upper = array(
			'client_id'   => 'https://clients.example/App.json',
			'client_host' => 'clients.example',
		);
		$lower = array(
			'client_id'   => 'https://clients.example/app.json',
			'client_host' => 'clients.example',
		);
		$upper_grant = $this->connect( 0, $upper )['grant_id'];
		for ( $i = 0; $i < 5; $i++ ) {
			$this->tick( 1 );
			$this->connect( 0, $lower );
		}

		$this->assertSame( 'live', $this->grant( $upper_grant )['status'], 'a case-distinct client_id is a different client' );
	}

	public function test_find_request_matches_key_exactly_and_reports_expiry() {
		$params  = $this->request_params();
		$created = $this->store->create_request( $params );
		$key     = $created['request_key'];

		$found = $this->store->find_request( $key );
		$this->assertIsArray( $found );
		$fields = array_keys( $found );
		sort( $fields );
		$this->assertSame( array( 'client_host', 'client_id', 'expired', 'id', 'redirect_uri', 'request_key', 'resource', 'scope', 'state', 'status', 'user_id' ), $fields, 'no code_challenge, generations or decision columns' );
		$this->assertSame( (int) $created['id'], $found['id'] );
		$this->assertSame( $this->user_id, $found['user_id'] );
		$this->assertSame( $key, $found['request_key'] );
		foreach ( array( 'client_id', 'client_host', 'redirect_uri', 'scope', 'resource', 'state' ) as $field ) {
			$this->assertSame( $params[ $field ], $found[ $field ], $field );
		}
		$this->assertSame( 'pending', $found['status'] );
		$this->assertFalse( $found['expired'] );

		// The column collation is case-insensitive, but keys are distinct whenever their bytes differ.
		$variant = preg_replace_callback(
			'/[A-Za-z]/',
			function ( $letter ) {
				return ctype_upper( $letter[0] ) ? strtolower( $letter[0] ) : strtoupper( $letter[0] );
			},
			$key,
			1
		);
		$this->assertNotSame( $key, $variant );
		$this->assertNull( $this->store->find_request( $variant ), 'a case variant of the key finds nothing' );
		$this->assertNull( $this->store->find_request( str_repeat( 'A', 43 ) ), 'an unknown key finds nothing' );
		$this->assertNull( $this->store->find_request( '' ) );

		// Expired by the store's clock once expires_at is reached, and still returned.
		$this->tick( OAuth_Store::REQUEST_LIFETIME - 1 );
		$this->assertFalse( $this->store->find_request( $key )['expired'] );
		$this->tick( 1 );
		$expired = $this->store->find_request( $key );
		$this->assertTrue( $expired['expired'] );
		$this->assertSame( 'pending', $expired['status'] );
	}

	public function test_fail_request_moves_only_a_pending_row_once() {
		$pending = $this->store->create_request( $this->request_params() );
		$this->assertTrue( $this->store->fail_request( (int) $pending['id'] ) );
		$this->assertSame( 'failed', $this->rows( 'requests', 'id = ' . (int) $pending['id'] )[0]['status'] );
		$this->assertFalse( $this->store->fail_request( (int) $pending['id'] ), 'a second call fails nothing' );

		$approved = $this->store->create_request( $this->request_params() );
		$this->assertTrue( $this->store->claim_request( $approved['id'], $this->user_id, true ) );
		$this->assertIsString( $this->store->issue_code( $approved['id'] ) );
		$this->assertFalse( $this->store->fail_request( (int) $approved['id'] ), 'an approved request stays approved' );
		$this->assertSame( 'approved', $this->rows( 'requests', 'id = ' . (int) $approved['id'] )[0]['status'] );

		$redeemed = $this->store->create_request( $this->request_params() );
		$this->assertTrue( $this->store->claim_request( $redeemed['id'], $this->user_id, true ) );
		$this->assertSame( OAuth_Store::OK, $this->redeem( $this->store->issue_code( $redeemed['id'] ) )['outcome'] );
		$this->assertFalse( $this->store->fail_request( (int) $redeemed['id'] ), 'a redeemed request stays approved' );
		$this->assertSame( 'approved', $this->rows( 'requests', 'id = ' . (int) $redeemed['id'] )[0]['status'] );

		$this->assertFalse( $this->store->fail_request( 0 ) );
	}
}
