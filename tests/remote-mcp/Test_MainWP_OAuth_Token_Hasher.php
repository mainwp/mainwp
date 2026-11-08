<?php
/**
 * Remote MCP token format, keyed hashing, lookup and secret rotation tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\OAuth_Token_Hasher;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Token_Hasher
 */
class Test_MainWP_OAuth_Token_Hasher extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_tokens_have_256_bits_and_type_prefix() {
		$prefixes = array(
			OAuth_Token_Hasher::TYPE_ACCESS  => 'mwpat_',
			OAuth_Token_Hasher::TYPE_REFRESH => 'mwprt_',
			OAuth_Token_Hasher::TYPE_CODE    => 'mwpac_',
		);
		foreach ( $prefixes as $type => $prefix ) {
			$seen = array();
			for ( $i = 0; $i < 20; $i++ ) {
				$token = $this->hasher->generate( $type );
				$this->assertMatchesRegularExpression( '/^' . $prefix . '[A-Za-z0-9_-]{43}$/', $token );
				$raw = base64_decode( strtr( substr( $token, 6 ), '-_', '+/' ) . '=', true );
				$this->assertSame( 32, strlen( (string) $raw ), 'decodes to 32 random bytes' );
				$seen[ $raw ] = true;
				foreach ( array_keys( $prefixes ) as $other ) {
					$this->assertSame( $other === $type, $this->hasher->matches_type( $token, $other ), $type . ' token checked as ' . $other );
				}
			}
			$this->assertCount( 20, $seen, $type . ' tokens are unique' );
		}
	}

	public function test_connect_token_type_accepted_by_hasher() {
		$this->assertSame( 'connect', OAuth_Token_Hasher::TYPE_CONNECT );
		$this->assertSame( 'mwpct_', OAuth_Token_Hasher::PREFIXES[ OAuth_Token_Hasher::TYPE_CONNECT ] );
		$this->assertLessThanOrEqual( 8, strlen( OAuth_Token_Hasher::TYPE_CONNECT ), 'fits tokens.token_type varchar(8)' );

		$token = $this->hasher->generate( OAuth_Token_Hasher::TYPE_CONNECT );
		$this->assertMatchesRegularExpression( '/^mwpct_[A-Za-z0-9_-]{43}$/', $token );
		$this->assertSame( 32, strlen( (string) base64_decode( strtr( substr( $token, 6 ), '-_', '+/' ) . '=', true ) ) );
		$this->assertTrue( $this->hasher->matches_type( $token, OAuth_Token_Hasher::TYPE_CONNECT ) );
		foreach ( array( OAuth_Token_Hasher::TYPE_ACCESS, OAuth_Token_Hasher::TYPE_REFRESH, OAuth_Token_Hasher::TYPE_CODE ) as $other ) {
			$this->assertFalse( $this->hasher->matches_type( $token, $other ), 'connect token checked as ' . $other );
			$this->assertFalse( $this->hasher->matches_type( $this->hasher->generate( $other ), OAuth_Token_Hasher::TYPE_CONNECT ), $other . ' token checked as connect' );
		}
		$stored   = $this->hasher->hash( $token, self::RESOURCE );
		$secret   = Remote_MCP_Settings::get_secret();
		$expected = hash_hmac( 'sha256', $token, hash_hmac( 'sha256', 'mainwp-remote-mcp-token-v1|' . self::RESOURCE, $secret['key'] ) );
		$this->assertSame( $expected, $stored, 'the 13.3 keyed HMAC, computed independently' );

		// The stored row is found as a connect credential and only as one.
		$minted = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'AnythingLLM', $this->clock->now + 86400 );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$row = $this->store->lookup_token( $minted['token'], OAuth_Token_Hasher::TYPE_CONNECT );
		$this->assertIsArray( $row );
		$this->assertSame( 'connect', $row['token_type'] );
		$this->assertNull( $this->store->lookup_token( $minted['token'], OAuth_Token_Hasher::TYPE_ACCESS ) );
		$this->assertNull( $this->store->lookup_token( 'mwpat_' . substr( $minted['token'], 6 ), OAuth_Token_Hasher::TYPE_ACCESS ), 'same secret, other prefix: no row' );
	}

	public function test_preview_baseline_digests_only_named_fields_with_missing_as_null() {
		$preview = array(
			'items'         => array( 'akismet', 'jetpack' ),
			'role'          => array(
				'name'  => 'Editor',
				'caps'  => array( 'edit_posts' ),
				'users' => 3,
			),
			'fingerprint'   => 'random-a',
			'preview_token' => 'tok_1',
		);
		$fields  = array( 'role', 'items', 'missing' );
		$json    = '{"items":["akismet","jetpack"],"missing":null,"role":{"caps":["edit_posts"],"name":"Editor","users":3}}';

		$baseline = $this->hasher->preview_baseline( $preview, $fields );
		$this->assertSame( $this->hasher->digest( 'preview_baseline', $json ), $baseline, 'canonical JSON of exactly the named fields, a missing one as null, under its own purpose' );
		$this->assertNotSame( $this->hasher->digest( 'args', $json ), $baseline );

		// Fields outside compare_fields never move the baseline: a random fingerprint or a renewed token.
		$this->assertSame( $baseline, $this->hasher->preview_baseline( array_merge( $preview, array( 'fingerprint' => 'random-b', 'preview_token' => 'tok_2' ) ), $fields ) );
		$this->assertSame( $baseline, $this->hasher->preview_baseline( array_reverse( $preview, true ), array_reverse( $fields ) ), 'key and field order do not matter' );
		$this->assertSame( $baseline, $this->hasher->preview_baseline( json_decode( (string) wp_json_encode( $preview ) ), $fields ), 'an object preview digests like its array form' );
		$this->assertSame( $baseline, $this->hasher->preview_baseline( array_merge( $preview, array( 'missing' => null ) ), $fields ), 'missing and null are the same' );

		// A named field that changes moves it.
		$changed                  = $preview;
		$changed['role']['users'] = 4;
		$this->assertNotSame( $baseline, $this->hasher->preview_baseline( $changed, $fields ) );
		$this->assertNotSame( $baseline, $this->hasher->preview_baseline( array_merge( $preview, array( 'missing' => false ) ), $fields ) );

		// No baseline for a preview that isn't an object or has no JSON form, or for a malformed field list.
		foreach ( array( 'a string', 42, null, true, array( 'akismet', 'jetpack' ), new \DateTime() ) as $bad ) {
			$this->assertNull( $this->hasher->preview_baseline( $bad, $fields ), wp_json_encode( $bad ) );
		}
		$this->assertNull( $this->hasher->preview_baseline( array_merge( $preview, array( 'fingerprint' => INF ) ), $fields ), 'the whole preview needs a JSON form' );
		$this->assertNull( $this->hasher->preview_baseline( $preview, array( 'items', 5 ) ) );
		$this->assertNull( $this->hasher->preview_baseline( $preview, array( 'items', '' ) ) );
	}

	public function test_stored_value_is_keyed_hmac_not_plaintext_or_bare_sha256() {
		global $wpdb;
		$code   = $this->issue_code();
		$result = $this->redeem( $code );
		$this->assertSame( OAuth_Store::OK, $result['outcome'] );

		$secret = Remote_MCP_Settings::get_secret();
		$this->assertIsArray( $secret );
		$this->assertSame( 32, strlen( $secret['key'] ) );
		$key = hash_hmac( 'sha256', 'mainwp-remote-mcp-token-v1|' . self::RESOURCE, $secret['key'] );

		$rows = $this->rows( 'tokens', 'grant_id = ' . (int) $result['grant_id'] );
		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$token = 'access' === $row['token_type'] ? $result['access_token'] : $result['refresh_token'];
			$this->assertSame( hash_hmac( 'sha256', $token, $key ), $row['token_hash'], $row['token_type'] );
			$this->assertNotSame( hash( 'sha256', $token ), $row['token_hash'] );
			$this->assertSame( $secret['version'], (int) $row['secret_version'] );
			foreach ( $row as $value ) {
				$this->assertStringNotContainsString( substr( $token, 6 ), (string) $value, 'plaintext is never stored' );
			}
		}
		$this->assertSame( hash_hmac( 'sha256', $result['access_token'], $key ), $this->hasher->hash( $result['access_token'], self::RESOURCE ) );

		$code_row = $this->rows( 'codes' );
		$this->assertCount( 1, $code_row );
		$this->assertSame( hash_hmac( 'sha256', $code, $key ), $code_row[0]['code_hash'] );

		// The secret never loads with every request: its option is not autoloaded.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Remote_MCP_Settings::OPTION_SECRET ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_lookup_rejects_wrong_secret_version() {
		global $wpdb;
		$result = $this->connect();
		$grant  = $this->grant( $result['grant_id'] );
		$this->assertIsArray( $this->store->lookup_token( $result['access_token'], OAuth_Token_Hasher::TYPE_ACCESS ) );

		// A credential presented as the wrong type is not found and changes nothing.
		$this->assertNull( $this->store->lookup_token( $result['access_token'], OAuth_Token_Hasher::TYPE_REFRESH ) );
		$wrong_type = $this->refresh( $result['access_token'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $wrong_type['outcome'] );
		$this->assertSame( $grant, $this->grant( $result['grant_id'] ) );

		$tokens = Remote_MCP_Install::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tokens}` SET secret_version = secret_version + 1 WHERE grant_id = %d", $result['grant_id'] ) );

		$this->assertNull( $this->store->lookup_token( $result['access_token'], OAuth_Token_Hasher::TYPE_ACCESS ) );
		$this->assertNull( $this->store->lookup_token( $result['refresh_token'], OAuth_Token_Hasher::TYPE_REFRESH ) );
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $this->store->check_access_token( $result['access_token'] )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $result['refresh_token'] )['outcome'] );
		$this->assertSame( $grant, $this->grant( $result['grant_id'] ), 'a version mismatch is not a replay' );
	}

	public function test_key_is_bound_to_canonical_resource() {
		$other_resource = 'https://staging.example.org/wp-json/mainwp-remote/v1/mcp';
		$token          = $this->hasher->generate( OAuth_Token_Hasher::TYPE_ACCESS );
		$this->assertNotSame( $this->hasher->hash( $token, self::RESOURCE ), $this->hasher->hash( $token, $other_resource ) );

		$result = $this->connect();
		$grant  = $this->grant( $result['grant_id'] );
		$clone  = $this->new_store( $other_resource );

		// A copied database under another canonical resource can't find the tokens.
		$this->assertNull( $clone->lookup_token( $result['access_token'], OAuth_Token_Hasher::TYPE_ACCESS ) );
		$this->assertSame( OAuth_Store::INVALID_TOKEN, $clone->check_access_token( $result['access_token'] )['outcome'] );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $result['refresh_token'], $clone )['outcome'] );
		$this->assertSame( $grant, $this->grant( $result['grant_id'] ) );

		$this->assertTrue( $this->access_ok( $result['access_token'] ) );
	}

	public function test_secret_rotation_revokes_every_grant_and_bumps_version() {
		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$first      = $this->connect();
		$second     = $this->connect( $other_user );
		$code       = $this->issue_code();
		$version    = $this->hasher->secret_version();
		$old_secret = Remote_MCP_Settings::get_secret();
		$site_gen   = $this->generation( 0 );
		$this->tick( 5 );

		$this->assertSame( $version + 1, $this->store->rotate_secret() );

		$new_secret = Remote_MCP_Settings::get_secret();
		$this->assertSame( $version + 1, $new_secret['version'] );
		$this->assertSame( $version + 1, $this->hasher->secret_version() );
		$this->assertSame( 32, strlen( $new_secret['key'] ) );
		$this->assertNotSame( $old_secret['key'], $new_secret['key'] );
		$this->assertSame( $site_gen + 1, $this->generation( 0 ) );

		foreach ( array( $first, $second ) as $result ) {
			$grant = $this->grant( $result['grant_id'] );
			$this->assertSame( 'revoked', $grant['status'] );
			$this->assertSame( 'secret_rotated', $grant['revoke_reason'] );
			$this->assertSame( (string) $this->clock->now, $grant['revoked_at'] );
			$this->assertFalse( $this->access_ok( $result['access_token'] ) );
			$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( $result['refresh_token'] )['outcome'] );
		}
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->redeem( $code )['outcome'], 'codes issued before rotation are dead' );

		// New connections work under the new secret.
		$fresh = $this->connect();
		$this->assertTrue( $this->access_ok( $fresh['access_token'] ) );
	}

	public function test_concurrent_first_secret_use_keeps_the_first_writer() {
		global $wpdb;
		$this->assertNull( Remote_MCP_Settings::get_secret() );
		$competitor = random_bytes( 32 );
		$raced      = false;
		add_filter(
			'query',
			function ( $query ) use ( $wpdb, $competitor, &$raced ) {
				if ( $raced || 0 !== stripos( ltrim( $query ), 'INSERT' ) || false === strpos( $query, Remote_MCP_Settings::OPTION_SECRET ) ) {
					return $query;
				}
				$raced = true;
				// Another request stores its secret between this request's cache miss and its insert.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						Remote_MCP_Settings::OPTION_SECRET,
						maybe_serialize(
							array(
								'version' => 1,
								'key'     => base64_encode( $competitor ),
							)
						)
					)
				);
				return $query;
			}
		);

		$token = 'mwpat_' . str_repeat( 'A', 43 );
		$hash  = $this->hasher->hash( $token, self::RESOURCE );

		$this->assertTrue( $raced, 'the competing insert ran' );
		$stored = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Remote_MCP_Settings::OPTION_SECRET ) ) );
		$this->assertSame( base64_encode( $competitor ), $stored['key'], 'the first writer keeps its secret' );
		$key = hash_hmac( 'sha256', 'mainwp-remote-mcp-token-v1|' . self::RESOURCE, $competitor );
		$this->assertSame( hash_hmac( 'sha256', $token, $key ), $hash, 'the loser hashes with the stored secret' );
	}

	public function test_secret_write_failure_throws_and_still_revokes_every_grant() {
		global $wpdb;
		$first    = $this->connect();
		$before   = Remote_MCP_Settings::get_secret();
		$write    = $this->fail_queries( "WHERE `option_name` = '" . Remote_MCP_Settings::OPTION_SECRET . "'" );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$write->failures = PHP_INT_MAX;
			$thrown          = null;
			try {
				$this->store->rotate_secret();
			} catch ( \RuntimeException $e ) {
				$thrown = $e;
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'rotation reports the failed secret write' );
		wp_cache_delete( Remote_MCP_Settings::OPTION_SECRET, 'options' );
		$this->assertSame( $before, Remote_MCP_Settings::get_secret(), 'the stored secret is unchanged' );
		$grant = $this->grant( $first['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'], 'sessions end anyway' );
		$this->assertSame( 'secret_rotated', $grant['revoke_reason'] );
	}
}
