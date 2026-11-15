<?php
/**
 * ChatGPT connection tests use signed tokens so signature failures are observable.
 *
 * @package MainWP/Dashboard/Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_ChatGPT_Plan as Plan;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Real signatures and isolated key files keep credential failures observable.
 */
class Test_Assistant_ChatGPT_Plan extends MainWP_Assistant_Test_Case {
	/**
	 * One signing key avoids repeated RSA generation across cases.
	 *
	 * @var \OpenSSLAsymmetricKey|null
	 */
	private static $rsa;
	/**
	 * The published key must correspond to the real fixture signer.
	 *
	 * @var array
	 */
	private static $jwk;
	/**
	 * Credential ownership must stay explicit in each assertion.
	 *
	 * @var int
	 */
	private $user_id;
	/**
	 * Key files need an isolated disposable location.
	 *
	 * @var string
	 */
	private $directory;
	/**
	 * Request counts expose duplicate refreshes and unexpected exchanges.
	 *
	 * @var array
	 */
	private $requests = array();
	/**
	 * Token failures must be injectable without provider traffic.
	 *
	 * @var array|\WP_Error|null
	 */
	private $token_reply;
	/**
	 * Claim overrides exercise the verifier with real signatures.
	 *
	 * @var array
	 */
	private $claims = array();
	/**
	 * Key identifiers exercise cached-key rotation.
	 *
	 * @var string
	 */
	private $kid = 'test-key';
	/**
	 * Corruption must invalidate a genuinely signed fixture.
	 *
	 * @var bool
	 */
	private $bad_signature = false;
	/**
	 * Key discovery failures must remain independent of token responses.
	 *
	 * @var array|null
	 */
	private $jwks_reply;
	/**
	 * Local sign-out needs coverage when revocation fails.
	 *
	 * @var array|\WP_Error|null
	 */
	private $revoke_reply;
	/**
	 * Discovery fixtures must include hidden and visible choices.
	 *
	 * @var array
	 */
	private $models_reply;
	/**
	 * The original session must survive the test fixture.
	 *
	 * @var string|null
	 */
	private $cookie;

	/**
	 * Each case needs independent credentials and session state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->user_id = $this->set_current_user_as_admin();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restore the original cookie bytes after the session fixture.
		$this->cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$this->session( 'session-one' );
		$this->directory = sys_get_temp_dir() . '/mainwp-chatgpt-' . wp_generate_uuid4();
		mkdir( $this->directory );
		add_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		delete_transient( Plan::JWKS_CACHE );
		if ( null === self::$rsa ) {
			self::$rsa = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
			$details   = openssl_pkey_get_details( self::$rsa );
			self::$jwk = array(
				'kty' => 'RSA',
				'kid' => 'test-key',
				'alg' => 'RS256',
				'use' => 'sig',
				'n'   => $this->b64( $details['rsa']['n'] ),
				'e'   => $this->b64( $details['rsa']['e'] ),
			);
		}
		$this->models_reply = $this->response(
			array(
				'models' => array(
					array(
						'slug'         => 'first',
						'display_name' => 'First',
						'visibility'   => 'list',
					),
					array(
						'slug'         => 'hidden',
						'display_name' => 'Hidden',
						'visibility'   => 'hidden',
					),
					array(
						'slug'         => 'second',
						'display_name' => 'Second',
						'visibility'   => 'list',
					),
				),
			)
		);
	}

	/**
	 * Credential fixtures must not affect later Assistant tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->modified_user_ids as $user_id ) {
			Plan::delete_user( $user_id );
			delete_option( Plan::LOCK_PREFIX . $user_id );
		}
		remove_filter( 'mainwp_get_wp_upload_dir', array( $this, 'upload_directory' ) );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		delete_transient( Plan::JWKS_CACHE );
		delete_option( 'mainwp_chatgpt_plan_host_id' );
		if ( null === $this->cookie ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->cookie;
		}
		$this->remove_directory( $this->directory );
		parent::tearDown();
	}

	/**
	 * Encryption fixtures must not leave private keys on disk.
	 *
	 * @param string $path Fixture directory.
	 * @return void
	 */
	private function remove_directory( $path ) {
		foreach ( glob( $path . '/{*,.[!.]*}', GLOB_BRACE ) as $entry ) {
			if ( is_dir( $entry ) ) {
				$this->remove_directory( $entry );
			} else {
				unlink( $entry );
			}
		}
		rmdir( $path );
	}

	/**
	 * Key writes must stay inside the disposable fixture directory.
	 *
	 * @param array $upload WordPress upload paths.
	 * @return array
	 */
	public function upload_directory( $upload ) {
		$upload['basedir'] = $this->directory;
		return $upload;
	}

	/**
	 * Session binding needs a reproducible cookie without real authentication.
	 *
	 * @param string $token Session identifier.
	 * @return void
	 */
	private function session( $token ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'test|' . ( time() + 3600 ) . '|' . $token . '|test-hmac';
	}

	/**
	 * Signed fixtures must use the same unpadded encoding as OAuth.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function b64( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Provider fixtures need realistic status, body and diagnostic headers.
	 *
	 * @param array|string $body Provider payload.
	 * @param int          $status HTTP status.
	 * @return array
	 */
	private function response( $body, $status = 200 ) {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'x-request-id' => 'request-test' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		);
	}

	/**
	 * Claim and signature failures must reach the real verifier.
	 *
	 * @return string
	 */
	private function jwt() {
		$pending = get_user_meta( $this->user_id, Plan::PENDING, true );
		$claims  = array_merge(
			array(
				'iss'   => 'https://auth.openai.com',
				'aud'   => array( 'oaiapp_test' ),
				'exp'   => time() + 3600,
				'iat'   => time(),
				'nonce' => $this->claims['nonce'] ?? ( $pending['nonce'] ?? '' ),
				'sub'   => 'account-one',
				'email' => 'owner@example.com',
			),
			$this->claims
		);
		$message = $this->b64(
			wp_json_encode(
				array(
					'alg' => 'RS256',
					'kid' => $this->kid,
				)
			)
		) . '.' . $this->b64( wp_json_encode( $claims ) );
		openssl_sign( $message, $signature, self::$rsa, OPENSSL_ALGO_SHA256 );
		if ( $this->bad_signature ) {
			$signature[0] = chr( ord( $signature[0] ) ^ 1 );
		}
		return $message . '.' . $this->b64( $signature );
	}

	/**
	 * Every outbound request must be accounted for without contacting a provider.
	 *
	 * @param mixed  $preempt Earlier HTTP override.
	 * @param array  $args HTTP arguments.
	 * @param string $url Requested endpoint.
	 * @return array|\WP_Error
	 */
	public function http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		switch ( $url ) {
			case Plan::TOKEN:
				return $this->token_reply;
			case Plan::JWKS:
				return $this->jwks_reply ?? $this->response( array( 'keys' => array( self::$jwk ) ) );
			case Plan::REVOKE:
				return $this->revoke_reply ?? $this->response( '' );
			case Plan::RESOURCE . '/models':
				return $this->models_reply;
			default:
				$this->fail( 'Unexpected outbound request: ' . $url );
		}
	}

	/**
	 * Endpoint counts distinguish rejected callbacks from attempted exchanges.
	 *
	 * @param string $url Requested endpoint.
	 * @return array
	 */
	private function calls( $url ) {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $call ) use ( $url ) {
					return $call['url'] === $url;
				}
			)
		);
	}

	/**
	 * Fixtures must use authorization values generated by the connection.
	 *
	 * @return array
	 */
	private function start() {
		$url = Plan::start_connect( $this->user_id );
		$this->assertIsString( $url );
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
		return $params;
	}

	/**
	 * Callback variations must retain the current attempt unless overridden.
	 *
	 * @param array $params Callback field overrides.
	 * @return string
	 */
	private function callback_url( $params = array() ) {
		$pending = get_user_meta( $this->user_id, Plan::PENDING, true );
		return Plan::REDIRECT_URI . '?' . http_build_query(
			array_merge(
				array(
					'code'      => 'authorization-code',
					'state'     => $pending['state'],
					'client_id' => 'oaiapp_test',
				),
				$params
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Rotated credentials and malformed responses share the same signed fixture.
	 *
	 * @param array $overrides Token response overrides.
	 * @return array
	 */
	private function tokens( $overrides = array() ) {
		return array_merge(
			array(
				'access_token'  => 'access-secret-one',
				'refresh_token' => 'refresh-secret-one',
				'id_token'      => $this->jwt(),
				'token_type'    => 'Bearer',
				'scope'         => Plan::SCOPES,
				'expires_in'    => 3600,
			),
			$overrides
		);
	}

	/**
	 * Later operations require a connection established through real validation.
	 *
	 * @param array $overrides Token response overrides.
	 * @return array
	 */
	private function connect( $overrides = array() ) {
		$pending               = $this->start();
		$this->claims['nonce'] = $pending['nonce'];
		$this->token_reply     = $this->response( $this->tokens( $overrides ) );
		$result                = Plan::complete_connect( $this->user_id, $this->callback_url() );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Storage assertions must inspect persisted metadata independently of status.
	 *
	 * @return array
	 */
	private function record() {
		return get_user_meta( $this->user_id, Plan::META, true );
	}

	/**
	 * Envelope readability must be checked through the real key manager.
	 *
	 * @param array|null $record Registration, or the current stored fixture.
	 * @return array
	 */
	private function secrets( $record = null ) {
		$record = $record ?? $this->record();
		return json_decode( MainWP_Keys_Manager::instance()->decrypt_keys_data( $record['tokens'] ), true );
	}

	/**
	 * Near-expiry fixtures must exercise refresh without waiting for expiration.
	 *
	 * @return void
	 */
	private function expire() {
		$record               = $this->record();
		$record['expires_at'] = time() + 119;
		update_user_meta( $this->user_id, Plan::META, $record );
		$this->requests = array();
	}

	/** Regression coverage for invalid client drops mangled registration then clean retry succeeds. */
	public function test_invalid_client_drops_mangled_registration_then_clean_retry_succeeds() {
		$this->start();
		$this->token_reply = $this->response( array( 'error' => 'invalid_client' ), 401 );
		$result            = Plan::complete_connect( $this->user_id, $this->callback_url( array( 'client_id' => 'oaiapp_truncated' ) ) );
		$this->assertSame( 'invalid_client', $result->get_error_code() );
		$this->assertSame( 'disconnected', Plan::get_status( $this->user_id )['status'] );
		$this->assertArrayNotHasKey( 'client_id', $this->record() );
		$this->assertSame( 'dynamic_agent_client', $this->start()['client_id'] );
		$this->connect();
		$this->assertSame( 'oaiapp_test', $this->record()['client_id'] );
	}

	/** Regression coverage for fresh registration revokes old client before replacing same subject. */
	public function test_fresh_registration_revokes_old_client_before_replacing_same_subject() {
		$this->connect();
		$old = $this->record();
		$url = Plan::start_connect( $this->user_id, true );
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$calls = $this->calls( Plan::REVOKE );
		parse_str( $calls[0]['args']['body'], $body );
		$this->assertSame( 'oaiapp_test', $body['client_id'] );
		$this->assertSame( 'refresh-secret-one', $body['token'] );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $old['tokens']['file_key'] );
		$this->assertArrayNotHasKey( 'client_id', $this->record() );
		$this->assertSame( 'dynamic_agent_client', $params['client_id'] );
		$this->assertSame( 'MainWP Dashboard', $params['agent_name_hint'] );
		$this->assertArrayNotHasKey( 'login_hint', $params );
		$this->claims['aud']   = 'oaiapp_new_workspace';
		$this->claims['nonce'] = $params['nonce'];
		$this->token_reply     = $this->response( $this->tokens() );
		$result                = Plan::complete_connect( $this->user_id, $this->callback_url( array( 'client_id' => 'oaiapp_new_workspace' ) ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['remote_revoke_confirmed'] );
		$this->assertSame( '', $result['notice'] );
		$this->assertSame( $old['sub'], $this->record()['sub'] );
		$this->assertSame( 'oaiapp_new_workspace', $this->record()['client_id'] );
		$this->assertCount( 1, $this->calls( Plan::REVOKE ) );
	}

	/** A completed connection retains an earlier revocation warning. */
	public function test_fresh_registration_preserves_unconfirmed_revoke_after_connect() {
		$this->connect();
		$this->revoke_reply = $this->response( '', 503 );
		$url                = Plan::start_connect( $this->user_id, true );
		$this->assertIsString( $url );
		$this->assertSame( 'revoke_unconfirmed', Plan::get_status( $this->user_id )['last_error']['code'] );
		parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$this->claims['aud']   = 'oaiapp_new_workspace';
		$this->claims['nonce'] = $params['nonce'];
		$this->token_reply     = $this->response( $this->tokens() );
		$result                = Plan::complete_connect( $this->user_id, $this->callback_url( array( 'client_id' => 'oaiapp_new_workspace' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'connected', $result['status'] );
		$this->assertFalse( $result['remote_revoke_confirmed'] );
		$this->assertSame( 'Remote revocation was not confirmed. Remove this app in ChatGPT Settings.', $result['notice'] );
	}

	/** Regression coverage for ajax fresh registration uses explicit option. */
	public function test_ajax_fresh_registration_uses_explicit_option() {
		$this->connect();
		$result = $this->ajax( 'start', array( 'new_registration' => '1' ) );
		$this->assertTrue( $result['success'] );
		parse_str( wp_parse_url( $result['data']['authorize_url'], PHP_URL_QUERY ), $params );
		$this->assertSame( 'dynamic_agent_client', $params['client_id'] );
		$this->assertCount( 1, $this->calls( Plan::REVOKE ) );
	}

	/**
	 * Returning sign-in must preserve registration without exposing an ID token.
	 *
	 * @return void
	 */
	public function test_authorize_parameters_and_returning_login_hint_without_id_token() {
		$first   = $this->start();
		$pending = get_user_meta( $this->user_id, Plan::PENDING, true );
		$this->assertSame( array( 'client_id', 'ext_agent_host_id', 'response_type', 'redirect_uri', 'scope', 'resource', 'state', 'nonce', 'code_challenge_method', 'code_challenge', 'agent_name_hint' ), array_keys( $first ) );
		$this->assertSame( 'dynamic_agent_client', $first['client_id'] );
		$this->assertSame( 'MainWP Dashboard', $first['agent_name_hint'] );
		$this->assertSame( 'code', $first['response_type'] );
		$this->assertSame( Plan::REDIRECT_URI, $first['redirect_uri'] );
		$this->assertSame( Plan::RESOURCE, $first['resource'] );
		$this->assertSame( Plan::SCOPES, $first['scope'] );
		$this->assertSame( 'S256', $first['code_challenge_method'] );
		$this->assertSame( $this->b64( hash( 'sha256', $pending['verifier'], true ) ), $first['code_challenge'] );
		$this->assertMatchesRegularExpression( '/^urn:uuid:[a-f0-9-]{36}$/', $first['ext_agent_host_id'] );
		global $wpdb;
		$this->assertContains( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'mainwp_chatgpt_plan_host_id'" ), array( 'off', 'no' ) );
		$this->connect();
		$returning = $this->start();
		$this->assertSame( 'oaiapp_test', $returning['client_id'] );
		$this->assertSame( 'owner@example.com', $returning['login_hint'] );
		$this->assertSame( $first['ext_agent_host_id'], $returning['ext_agent_host_id'] );
		$this->assertArrayNotHasKey( 'id_token_hint', $returning );
		$this->assertArrayNotHasKey( 'agent_name_hint', $returning );
		$this->assertArrayNotHasKey( 'prompt', $returning );
		$this->assertNotSame( $first['state'], $returning['state'] );
		$this->assertNotSame( $first['nonce'], $returning['nonce'] );
		$this->assertNotSame( $first['code_challenge'], $returning['code_challenge'] );
	}

	/**
	 * Invalid callbacks must fail before any authorization code is exchanged.
	 *
	 * @return void
	 */
	public function test_callback_refusals_never_exchange() {
		$cases = array( 'wrong_state', 'expired', 'other_user', 'other_session', 'wrong_host', 'wrong_path', 'wrong_port', 'wrong_scheme', 'denied', 'missing_client', 'unissued_client', 'unsafe_client', 'different_client' );
		foreach ( $cases as $case ) {
			wp_set_current_user( $this->user_id );
			$this->session( 'session-one' );
			delete_user_meta( $this->user_id, Plan::META );
			if ( 'different_client' === $case ) {
				update_user_meta( $this->user_id, Plan::META, array( 'client_id' => 'oaiapp_saved' ) );
			}
			$this->start();
			$callback = $this->callback_url();
			if ( 'wrong_state' === $case ) {
				$callback = $this->callback_url( array( 'state' => 'wrong' ) );
			} elseif ( 'expired' === $case ) {
				$pending             = get_user_meta( $this->user_id, Plan::PENDING, true );
				$pending['created'] -= 15 * MINUTE_IN_SECONDS;
				update_user_meta( $this->user_id, Plan::PENDING, $pending );
			} elseif ( 'other_user' === $case ) {
				wp_set_current_user( $this->create_other_admin() );
			} elseif ( 'other_session' === $case ) {
				$this->session( 'session-two' );
			} elseif ( 'wrong_host' === $case ) {
				$callback = str_replace( '127.0.0.1', 'localhost', $callback );
			} elseif ( 'wrong_path' === $case ) {
				$callback = str_replace( '/auth/callback', '/callback', $callback );
			} elseif ( 'wrong_port' === $case ) {
				$callback = str_replace( ':1456', ':1455', $callback );
			} elseif ( 'wrong_scheme' === $case ) {
				$callback = str_replace( 'http:', 'https:', $callback );
			} elseif ( 'denied' === $case ) {
				$callback = $this->callback_url( array( 'error' => 'access_denied' ) );
			} elseif ( 'missing_client' === $case ) {
				$callback = $this->callback_url( array( 'client_id' => '' ) );
			} elseif ( 'unissued_client' === $case ) {
				$callback = $this->callback_url( array( 'client_id' => 'dynamic_agent_client' ) );
			} elseif ( 'unsafe_client' === $case ) {
				$callback = $this->callback_url( array( 'client_id' => 'app/../other' ) );
			}
			$result = Plan::complete_connect( get_current_user_id(), $callback );
			$this->assertWPError( $result, $case );
			$this->assertCount( 0, $this->calls( Plan::TOKEN ), $case );
			if ( 'denied' === $case ) {
				$this->assertSame( 'access_denied', $result->get_error_code() );
				$this->assertSame( '', get_user_meta( $this->user_id, Plan::PENDING, true ) );
			}
		}
	}

	/**
	 * Single-use attempts must accept both supported callback formats.
	 *
	 * @return void
	 */
	public function test_exchange_exact_body_bare_query_and_replay() {
		$params                = $this->start();
		$pending               = get_user_meta( $this->user_id, Plan::PENDING, true );
		$this->claims['nonce'] = $params['nonce'];
		$this->token_reply     = $this->response( $this->tokens() );
		$callback              = wp_parse_url( $this->callback_url(), PHP_URL_QUERY );
		$this->assertIsArray( Plan::complete_connect( $this->user_id, $callback ) );
		$call = $this->calls( Plan::TOKEN )[0];
		parse_str( $call['args']['body'], $body );
		$this->assertSame(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => 'oaiapp_test',
				'code'          => 'authorization-code',
				'code_verifier' => $pending['verifier'],
				'redirect_uri'  => Plan::REDIRECT_URI,
				'resource'      => Plan::RESOURCE,
			),
			$body
		);
		$this->assertSame( 'application/x-www-form-urlencoded', $call['args']['headers']['Content-Type'] );
		$this->assertTrue( $call['args']['sslverify'] );
		$this->assertSame( 0, $call['args']['redirection'] );
		$this->assertWPError( Plan::complete_connect( $this->user_id, $callback ) );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
		$this->start();
		$this->claims['nonce'] = get_user_meta( $this->user_id, Plan::PENDING, true )['nonce'];
		$this->token_reply     = $this->response( $this->tokens() );
		$this->assertIsArray( Plan::complete_connect( $this->user_id, $this->callback_url( array( 'client_id' => null ) ) ) );
	}

	/**
	 * A rejected code must not discard the issued registration or permit replay.
	 *
	 * @return void
	 */
	public function test_failed_initial_code_retains_issued_client_and_consumes_attempt() {
		$this->start();
		$callback          = $this->callback_url();
		$this->token_reply = $this->response( array( 'error' => 'invalid_grant' ), 400 );
		$this->assertSame( 'invalid_grant', Plan::complete_connect( $this->user_id, $callback )->get_error_code() );
		$this->assertSame( 'oaiapp_test', $this->record()['client_id'] );
		$this->assertArrayNotHasKey( 'tokens', $this->record() );
		$this->assertWPError( Plan::complete_connect( $this->user_id, $callback ) );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
		$this->assertSame( 'oaiapp_test', $this->start()['client_id'] );
	}

	/**
	 * Every failed first exchange must keep the client OpenAI already registered.
	 *
	 * @return void
	 */
	public function test_any_failed_first_exchange_retains_issued_client() {
		$replies = array(
			new \WP_Error( 'http_request_failed', 'offline' ),
			$this->response( array(), 503 ),
			$this->response( array( 'error' => 'invalid_client' ), 503 ),
			$this->response( $this->tokens( array( 'id_token' => 'not-a-jwt' ) ) ),
		);
		foreach ( $replies as $i => $reply ) {
			delete_user_meta( $this->user_id, Plan::META );
			$this->start();
			$issued            = 'app-' . $i . '.issued';
			$this->token_reply = $reply;
			$this->assertWPError( Plan::complete_connect( $this->user_id, $this->callback_url( array( 'client_id' => $issued ) ) ), (string) $i );
			$this->assertSame( $issued, $this->record()['client_id'], (string) $i );
			$this->assertArrayNotHasKey( 'tokens', $this->record(), (string) $i );
			$this->assertSame( $issued, $this->start()['client_id'], (string) $i );
		}
	}

	/**
	 * Signing out and back in to the same account must not repeat the welcome.
	 *
	 * @return void
	 */
	public function test_same_account_reconnect_keeps_welcome_acknowledgment() {
		$this->connect();
		Plan::mark_welcome_shown( $this->user_id );
		$this->assertIsArray( Plan::disconnect( $this->user_id ) );
		$this->assertFalse( $this->connect()['welcome_pending'] );
		$this->expire();
		$this->token_reply = $this->response( array( 'error' => 'refresh_token_expired' ), 400 );
		$this->assertWPError( Plan::get_access_token( $this->user_id ) );
		$this->assertSame( 'needs_reconnect', $this->record()['status'] );
		$this->assertFalse( $this->connect()['welcome_pending'] );
	}

	/**
	 * An unrelated denial must not consume a legitimate pending attempt.
	 *
	 * @return void
	 */
	public function test_denied_callback_requires_state_before_consumption() {
		$this->start();
		$pending = get_user_meta( $this->user_id, Plan::PENDING, true );
		$result  = Plan::complete_connect(
			$this->user_id,
			$this->callback_url(
				array(
					'state' => 'wrong',
					'error' => 'access_denied',
				)
			)
		);
		$this->assertSame( 'invalid_callback', $result->get_error_code() );
		$this->assertSame( $pending, get_user_meta( $this->user_id, Plan::PENDING, true ) );
		$this->assertCount( 0, $this->calls( Plan::TOKEN ) );
	}

	/**
	 * Untrusted claims must not create usable credentials.
	 *
	 * @return void
	 */
	public function test_invalid_id_tokens_are_refused() {
		$cases = array( 'signature', 'aud', 'iss', 'expired', 'nonce', 'future_iat', 'missing_iat', 'empty_sub', 'sid' );
		foreach ( $cases as $case ) {
			$params              = $this->start();
			$this->claims        = array( 'nonce' => $params['nonce'] );
			$this->bad_signature = 'signature' === $case;
			switch ( $case ) {
				case 'aud':
					$this->claims['aud'] = 'oaiapp_other';
					break;
				case 'iss':
					$this->claims['iss'] = 'https://example.com';
					break;
				case 'expired':
					$this->claims['exp'] = time() - 10;
					break;
				case 'nonce':
					$this->claims['nonce'] = 'wrong';
					break;
				case 'future_iat':
					$this->claims['iat'] = time() + 20;
					break;
				case 'missing_iat':
					$this->claims['iat'] = null;
					break;
				case 'empty_sub':
					$this->claims['sub'] = '';
					break;
				case 'sid':
					$this->claims['sid'] = '';
					break;
			}
			$this->token_reply = $this->response( $this->tokens() );
			$result            = Plan::complete_connect( $this->user_id, $this->callback_url() );
			$this->assertWPError( $result, $case );
			$this->assertSame( 'invalid_id_token', $result->get_error_code(), $case );
			$this->assertArrayNotHasKey( 'tokens', $this->record() );
		}
	}

	/**
	 * Key rotation needs a bounded refetch without bypassing validation.
	 *
	 * @return void
	 */
	public function test_jwks_cache_refetches_unknown_kid_once() {
		set_transient( Plan::JWKS_CACHE, array( 'keys' => array( array_merge( self::$jwk, array( 'kid' => 'old-key' ) ) ) ), HOUR_IN_SECONDS );
		$this->claims['aud'] = 'oaiapp_test';
		$this->connect();
		$this->assertCount( 1, $this->calls( Plan::JWKS ) );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, get_option( '_transient_timeout_' . Plan::JWKS_CACHE ), 2 );
		$this->requests = array();
		$this->connect();
		$this->assertCount( 0, $this->calls( Plan::JWKS ) );
		$params                = $this->start();
		$this->claims['nonce'] = $params['nonce'];
		$this->kid             = 'unknown';
		$this->token_reply     = $this->response( $this->tokens() );
		$this->assertSame( 'unknown_kid', Plan::complete_connect( $this->user_id, $this->callback_url() )->get_error_code() );
		$this->assertCount( 1, $this->calls( Plan::JWKS ) );
	}

	/**
	 * Status may expose granted scopes while credentials remain private.
	 *
	 * @return void
	 */
	public function test_tokens_are_encrypted_and_id_token_is_discarded() {
		$status = $this->connect();
		$record = $this->record();
		$this->assertSame(
			array(
				'access_token'  => 'access-secret-one',
				'refresh_token' => 'refresh-secret-one',
			),
			$this->secrets()
		);
		$this->assertStringNotContainsString( 'access-secret-one', wp_json_encode( $record ) );
		$this->assertStringNotContainsString( 'refresh-secret-one', wp_json_encode( $record ) );
		$this->assertStringNotContainsString( 'id_token', wp_json_encode( $record ) );
		foreach ( array( 'access-secret-one', 'refresh-secret-one', '"tokens"', 'file_key', 'encrypted_val', 'id_token', 'verifier' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, wp_json_encode( $status ) );
		}
		$this->assertTrue( $status['welcome_pending'] );
		$this->assertFalse( Plan::mark_welcome_shown( $this->user_id )['welcome_pending'] );
	}

	/**
	 * Identity alone must not enable plan usage without the granted scope.
	 *
	 * @return void
	 */
	public function test_missing_plan_scope_keeps_identity_and_requests_consent() {
		$status = $this->connect( array( 'scope' => 'openid profile email offline_access' ) );
		$this->assertSame( 'plan_disabled', $status['status'] );
		$this->assertSame( 'account-one', $status['sub'] );
		$this->assertSame( 'plan_disabled', Plan::get_access_token( $this->user_id )->get_error_code() );
		$this->assertCount( 0, $this->calls( Plan::RESOURCE . '/models' ) );
		$params = $this->start();
		$this->assertSame( 'consent', $params['prompt'] );
		$this->assertSame( Plan::SCOPES, $params['scope'] );
		$this->assertArrayNotHasKey( 'force_reconsent', $params );
	}

	/**
	 * Only visible models may be selected, in the provider's preference order.
	 *
	 * @return void
	 */
	public function test_models_preserve_order_validate_selection_and_refresh() {
		$status = $this->connect();
		$this->assertSame(
			array(
				array(
					'slug'         => 'first',
					'display_name' => 'First',
				),
				array(
					'slug'         => 'second',
					'display_name' => 'Second',
				),
			),
			Plan::list_models( $this->user_id )
		);
		$this->assertSame( 'first', $status['model'] );
		$this->assertSame( 'Bearer access-secret-one', $this->calls( Plan::RESOURCE . '/models' )[0]['args']['headers']['Authorization'] );
		$this->assertWPError( Plan::set_model( $this->user_id, 'hidden' ) );
		$this->assertSame( 'second', Plan::set_model( $this->user_id, 'second' )['model'] );
		$this->assertIsArray( Plan::refresh_models( $this->user_id ) );
		$this->assertSame( 'second', Plan::get_status( $this->user_id )['model'] );
		$this->connect();
		$this->assertSame( 'first', Plan::get_status( $this->user_id )['model'] );
	}

	/**
	 * A model list that failed to load at connect time must load later without a new sign-in.
	 *
	 * @return void
	 */
	public function test_failed_model_discovery_is_retried_without_new_exchange() {
		$models             = $this->models_reply;
		$this->models_reply = $this->response( array(), 503 );
		$status             = $this->connect();
		$this->assertTrue( $status['connected'] );
		$this->assertSame( array(), $status['models'] );
		$this->assertSame( 503, $status['last_error']['http_status'] );
		$this->assertWPError( Plan::list_models( $this->user_id ) );
		$this->models_reply = $models;
		$this->assertSame( array( 'first', 'second' ), array_column( Plan::list_models( $this->user_id ), 'slug' ) );
		$this->assertSame( 'first', Plan::get_status( $this->user_id )['model'] );
		$this->assertSame( array(), Plan::get_status( $this->user_id )['last_error'] );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
		$record           = $this->record();
		$record['models'] = array();
		$record['model']  = '';
		update_user_meta( $this->user_id, Plan::META, $record );
		$response = $this->ajax( 'status' );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'first', $response['data']['model'] );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
	}

	/**
	 * A refresh that leaves the grant unchanged may omit scope.
	 *
	 * @return void
	 */
	public function test_refresh_without_scope_keeps_granted_scopes() {
		$this->connect();
		$this->expire();
		$tokens = $this->tokens( array( 'access_token' => 'access-secret-two' ) );
		unset( $tokens['scope'], $tokens['id_token'] );
		$this->token_reply = $this->response( $tokens );
		$this->assertSame( 'access-secret-two', Plan::get_access_token( $this->user_id ) );
		$this->assertSame( 'connected', $this->record()['status'] );
		$this->assertContains( 'chatgpt.tokens.use.direct', $this->record()['scopes'] );
	}

	/**
	 * Rotating credentials must replace one complete record only when needed.
	 *
	 * @return void
	 */
	public function test_refresh_only_near_expiry_and_replaces_all_token_fields() {
		$this->connect();
		$this->requests = array();
		$this->assertSame( 'access-secret-one', Plan::get_access_token( $this->user_id ) );
		$this->assertCount( 0, $this->requests );
		$this->expire();
		$old               = $this->record();
		$this->token_reply = $this->response(
			$this->tokens(
				array(
					'access_token'  => 'access-secret-two',
					'refresh_token' => 'refresh-secret-two',
					'scope'         => 'openid chatgpt.tokens.use.direct',
					'expires_in'    => 1800,
				)
			)
		);
		$this->assertSame( 'access-secret-two', Plan::get_access_token( $this->user_id ) );
		parse_str( $this->calls( Plan::TOKEN )[0]['args']['body'], $body );
		$this->assertSame(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => 'oaiapp_test',
				'refresh_token' => 'refresh-secret-one',
				'resource'      => Plan::RESOURCE,
			),
			$body
		);
		$this->assertSame(
			array(
				'access_token'  => 'access-secret-two',
				'refresh_token' => 'refresh-secret-two',
			),
			$this->secrets()
		);
		$this->assertSame( array( 'openid', 'chatgpt.tokens.use.direct' ), $this->record()['scopes'] );
		$this->assertEqualsWithDelta( time() + 1800, $this->record()['expires_at'], 2 );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $old['tokens']['file_key'] );
		$this->assertStringNotContainsString( 'id_token', wp_json_encode( $this->secrets() ) );
	}

	/**
	 * A waiting request must use its predecessor's rotated credentials.
	 *
	 * @return void
	 */
	public function test_waiter_rereads_rotated_record_without_second_refresh() {
		global $wpdb;
		$this->connect();
		$this->expire();
		$record               = $this->record();
		$record['expires_at'] = time() + 3600;
		$record['tokens']     = MainWP_Keys_Manager::instance()->encrypt_keys_data(
			wp_json_encode(
				array(
					'access_token'  => 'winner-access',
					'refresh_token' => 'winner-refresh',
				)
			),
			'test_winner'
		);
		$name                 = Plan::LOCK_PREFIX . $this->user_id;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => time() . ':holder',
				'autoload'     => 'off',
			)
		);
		$attempts = 0;
		$queries  = function ( $sql ) use ( &$attempts, $record, $name, $wpdb ) {
			if ( str_contains( $sql, 'INSERT IGNORE' ) && str_contains( $sql, $name ) ) {
				++$attempts;
				if ( 2 === $attempts ) {
					update_user_meta( $this->user_id, Plan::META, $record );
					$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
				}
			}
			return $sql;
		};
		add_filter( 'query', $queries );
		try {
			$this->assertSame( 'winner-access', Plan::get_access_token( $this->user_id ) );
		} finally {
			remove_filter( 'query', $queries );
		}
		$this->assertGreaterThanOrEqual( 2, $attempts );
		$this->assertCount( 0, $this->calls( Plan::TOKEN ) );
		MainWP_Keys_Manager::instance()->delete_key_file( $record['tokens']['file_key'] );
	}

	/**
	 * An expired owner must not remove a successor's lock.
	 *
	 * @return void
	 */
	public function test_stale_lock_takeover_is_compare_and_swap_and_release_checks_owner() {
		global $wpdb;
		$this->connect();
		$this->expire();
		$name  = Plan::LOCK_PREFIX . $this->user_id;
		$stale = ( time() - 31 ) . ':dead';
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $stale,
				'autoload'     => 'off',
			)
		);
		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			$this->assertSame( 'access-secret-one', Plan::get_access_token( $this->user_id ) );
		} finally {
			remove_filter( 'query', $capture );
		}
		$lock_queries = implode(
			"\n",
			array_filter(
				$queries,
				static function ( $sql ) use ( $name ) {
					return str_contains( $sql, $name );
				}
			)
		);
		$this->assertStringContainsString( 'UPDATE', $lock_queries );
		$this->assertStringContainsString( "AND option_value = '" . $stale . "'", $lock_queries );
		$this->assertLessThan( strpos( $lock_queries, 'DELETE' ), strpos( $lock_queries, 'UPDATE' ) );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
		$successor = time() . ':successor';
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $successor,
				'autoload'     => 'off',
			)
		);
		$release = new \ReflectionMethod( Plan::class, 'release_lock' );
		$release->setAccessible( true );
		$release->invoke( null, $this->user_id, $stale );
		$this->assertSame( $successor, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
	}

	/**
	 * Failed persistence must preserve the previous readable envelope.
	 *
	 * @return void
	 */
	public function test_failed_refresh_save_leaves_one_complete_readable_record() {
		$this->connect();
		$this->expire();
		$before            = $this->record();
		$files             = glob( MainWP_Keys_Manager::get_keys_dir() . 'chatgpt_*' );
		$this->token_reply = $this->response(
			$this->tokens(
				array(
					'access_token'  => 'rotated-access',
					'refresh_token' => 'rotated-refresh',
				)
			)
		);
		$writes            = array();
		$fail_save         = function ( $check, $user_id, $key, $value ) use ( &$writes ) {
			if ( $user_id === $this->user_id && Plan::META === $key ) {
				$writes[] = array(
					'record'  => $value,
					'secrets' => isset( $value['tokens'] ) ? $this->secrets( $value ) : array(),
				);
				if ( isset( $value['tokens'] ) && $value['tokens'] !== $this->record()['tokens'] ) {
					return false;
				}
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $fail_save, 10, 4 );
		try {
			$this->assertSame( 'storage_failed', Plan::get_access_token( $this->user_id )->get_error_code() );
		} finally {
			remove_filter( 'update_user_metadata', $fail_save, 10 );
		}
		$after = $this->record();
		$this->assertSame( $before['tokens'], $after['tokens'] );
		$this->assertSame( $before['expires_at'], $after['expires_at'] );
		$this->assertSame( $before['scopes'], $after['scopes'] );
		$this->assertSame( 'refresh-secret-one', $this->secrets()['refresh_token'] );
		$this->assertSame( 'storage_failed', $after['last_error']['code'] );
		$this->assertSame( $files, glob( MainWP_Keys_Manager::get_keys_dir() . 'chatgpt_*' ) );
		$this->assertSame(
			array(
				'access_token'  => 'rotated-access',
				'refresh_token' => 'rotated-refresh',
			),
			$writes[0]['secrets']
		);
		foreach ( array( 'tokens', 'scopes', 'expires_at', 'status', 'client_id' ) as $field ) {
			$this->assertArrayHasKey( $field, $writes[0]['record'] );
		}
	}

	/**
	 * An interrupted write must not destroy the existing decryption key.
	 *
	 * @return void
	 */
	public function test_interrupted_refresh_save_keeps_the_previous_envelope_readable() {
		$this->connect();
		$this->expire();
		$before            = $this->record();
		$this->token_reply = $this->response(
			$this->tokens(
				array(
					'access_token'  => 'interrupted-access',
					'refresh_token' => 'interrupted-refresh',
				)
			)
		);
		$interrupted       = false;
		$interrupt         = function ( $check, $user_id, $key, $value ) use ( $before ) {
			if ( $user_id === $this->user_id && Plan::META === $key && isset( $value['tokens'] ) && $before['tokens'] !== $value['tokens'] ) {
				throw new \RuntimeException( 'Interrupted before the metadata write' );
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $interrupt, 10, 4 );
		try {
			Plan::get_access_token( $this->user_id );
		} catch ( \RuntimeException $exception ) {
			$interrupted = true;
		} finally {
			remove_filter( 'update_user_metadata', $interrupt, 10 );
		}
		$this->assertTrue( $interrupted );
		$this->assertSame( $before, $this->record() );
		$this->assertSame( 'refresh-secret-one', $this->secrets()['refresh_token'] );
		$this->assertCount( 1, $this->calls( Plan::TOKEN ) );
	}

	/**
	 * A malformed replacement must not revoke a usable connection.
	 *
	 * @return void
	 */
	public function test_malformed_replacement_does_not_revoke_a_working_account() {
		$this->connect();
		$before                = $this->record();
		$this->claims['sub']   = 'account-two';
		$params                = $this->start();
		$this->claims['nonce'] = $params['nonce'];
		$this->token_reply     = $this->response( $this->tokens( array( 'refresh_token' => '' ) ) );
		$this->assertWPError( Plan::complete_connect( $this->user_id, $this->callback_url() ) );
		$this->assertSame( $before['tokens'], $this->record()['tokens'] );
		$this->assertSame( $before['sub'], $this->record()['sub'] );
		$this->assertCount( 0, $this->calls( Plan::REVOKE ) );
	}

	/**
	 * Unusable refresh tokens require reconnection while retaining account identity.
	 *
	 * @return void
	 */
	public function test_unusable_refresh_codes_clear_tokens_keep_identity() {
		foreach ( array( 'invalid_grant', 'invalid_refresh_token', 'token_expired', 'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused' ) as $code ) {
			$this->connect();
			$this->expire();
			$before            = $this->record();
			$this->token_reply = $this->response( array( 'error' => $code ), 400 );
			$this->assertWPError( Plan::get_access_token( $this->user_id ) );
			$after = $this->record();
			$this->assertSame( 'needs_reconnect', $after['status'], $code );
			$this->assertArrayNotHasKey( 'tokens', $after );
			foreach ( array( 'client_id', 'sub', 'email' ) as $key ) {
				$this->assertSame( $before[ $key ], $after[ $key ] );
			}
			$this->assertSame( $code, $after['last_error']['code'] );
			$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $before['tokens']['file_key'] );
		}
	}

	/**
	 * Transient provider failures must not destroy credentials.
	 *
	 * @return void
	 */
	public function test_network_and_server_errors_keep_tokens_on_refresh_and_exchange() {
		$this->connect();
		$this->expire();
		$before = $this->record();
		foreach ( array( new \WP_Error( 'http_request_failed', 'secret upstream message' ), $this->response( array( 'error' => 'invalid_grant' ), 503 ) ) as $failure ) {
			$this->token_reply = $failure;
			$this->assertWPError( Plan::get_access_token( $this->user_id ) );
			$after = $this->record();
			$this->assertSame( $before['tokens'], $after['tokens'] );
			$this->assertSame( 'connected', $after['status'] );
			$this->assertNotEmpty( $after['last_error']['code'] );
			$this->assertStringNotContainsString( 'secret upstream message', wp_json_encode( $after ) );
			$this->start();
			$this->assertWPError( Plan::complete_connect( $this->user_id, $this->callback_url() ) );
			$this->assertSame( $before['tokens'], $this->record()['tokens'] );
			$this->assertSame( 'connected', $this->record()['status'] );
		}
		$this->assertSame( 503, $this->record()['last_error']['http_status'] );
		$this->assertSame( 'request-test', $this->record()['last_error']['request_id'] );
	}

	/**
	 * Local sign-out must succeed even when remote revocation is unconfirmed.
	 *
	 * @return void
	 */
	public function test_disconnect_revokes_and_clears_locally_even_on_remote_failure() {
		foreach ( array( $this->response( '' ), new \WP_Error( 'http_request_failed', 'offline' ), $this->response( array(), 503 ) ) as $reply ) {
			$this->connect();
			$before             = $this->record();
			$this->requests     = array();
			$this->revoke_reply = $reply;
			$result             = Plan::disconnect( $this->user_id );
			$this->assertIsArray( $result );
			$this->assertSame( 'disconnected', $result['status'] );
			$this->assertArrayNotHasKey( 'tokens', $this->record() );
			$this->assertSame( 'oaiapp_test', $this->record()['client_id'] );
			$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $before['tokens']['file_key'] );
			parse_str( $this->calls( Plan::REVOKE )[0]['args']['body'], $body );
			$this->assertSame(
				array(
					'token'           => 'refresh-secret-one',
					'token_type_hint' => 'refresh_token',
					'client_id'       => 'oaiapp_test',
				),
				$body
			);
			$ok = ! is_wp_error( $reply ) && 200 === $reply['response']['code'];
			$this->assertSame( $ok, $result['remote_revoke_confirmed'] );
			$this->assertSame( $ok, '' === $result['notice'] );
		}
	}

	/**
	 * Unreadable credentials cannot be revoked, so the user must be told to remove the app.
	 *
	 * @return void
	 */
	public function test_disconnect_with_unreadable_tokens_does_not_confirm_revocation() {
		$this->connect();
		$this->requests = array();
		wp_delete_file( MainWP_Keys_Manager::get_keys_dir() . $this->record()['tokens']['file_key'] );
		$result = Plan::disconnect( $this->user_id );
		$this->assertSame( 'disconnected', $result['status'] );
		$this->assertFalse( $result['remote_revoke_confirmed'] );
		$this->assertNotSame( '', $result['notice'] );
		$this->assertCount( 0, $this->calls( Plan::REVOKE ) );

		$this->connect();
		$this->expire();
		wp_delete_file( MainWP_Keys_Manager::get_keys_dir() . $this->record()['tokens']['file_key'] );
		$this->assertSame( 'needs_reconnect', Plan::get_access_token( $this->user_id )->get_error_code() );
		$this->assertSame( 'needs_reconnect', $this->record()['status'] );
		$this->assertCount( 0, $this->calls( Plan::TOKEN ) );
		$this->assertFalse( Plan::disconnect( $this->user_id )['remote_revoke_confirmed'] );
		$this->assertCount( 0, $this->calls( Plan::REVOKE ) );
	}

	/**
	 * Account replacement must retire the previous renewable session.
	 *
	 * @return void
	 */
	public function test_reconnect_different_subject_revokes_old_record_before_replacement() {
		$this->connect();
		$old = $this->record();
		Plan::set_model( $this->user_id, 'second' );
		Plan::mark_welcome_shown( $this->user_id );
		$this->claims = array(
			'sub'   => 'account-two',
			'email' => 'other@example.com',
		);
		$status       = $this->connect(
			array(
				'access_token'  => 'account-two-access',
				'refresh_token' => 'account-two-refresh',
			)
		);
		$this->assertSame( 'account-two', $status['sub'] );
		$this->assertSame( 'other@example.com', $status['email'] );
		$this->assertSame( 'first', $status['model'] );
		$this->assertTrue( $status['welcome_pending'] );
		$this->assertSame( 'account-two-refresh', $this->secrets()['refresh_token'] );
		parse_str( $this->calls( Plan::REVOKE )[0]['args']['body'], $body );
		$this->assertSame( $old['client_id'], $body['client_id'] );
		$this->assertSame( 'refresh-secret-one', $body['token'] );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $old['tokens']['file_key'] );
	}

	/**
	 * Deleted users must not leave credential records or key files behind.
	 *
	 * @return void
	 */
	public function test_delete_user_removes_meta_pending_and_key_file() {
		$this->connect();
		$old = $this->record();
		$this->start();
		do_action( 'delete_user', $this->user_id );
		$this->assertSame( '', get_user_meta( $this->user_id, Plan::META, true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, Plan::PENDING, true ) );
		$this->assertFileDoesNotExist( MainWP_Keys_Manager::get_keys_dir() . $old['tokens']['file_key'] );
	}

	/**
	 * Nonce failures and JSON responses must preserve the real termination payload.
	 *
	 * @param string $suffix Action suffix.
	 * @param array  $payload Request fields.
	 * @param bool   $nonce Whether to provide a valid nonce.
	 * @return array|int|string
	 */
	private function ajax( $suffix, $payload = array(), $nonce = true ) {
		$action   = 'mainwp_assistant_chatgpt_' . $suffix;
		$_POST    = $payload;
		$_REQUEST = $payload;
		if ( $nonce ) {
			$_REQUEST['security'] = wp_create_nonce( $action );
		}
		$handler_filter = static function () {
			return static function ( $message ) {
				throw new \WPAjaxDieContinueException( (string) $message );
			};
		};
		add_filter( 'wp_die_ajax_handler', $handler_filter );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$level = ob_get_level();
		ob_start();
		try {
			do_action( 'wp_ajax_' . $action );
			$this->fail( 'AJAX handler did not terminate.' );
		} catch ( \WPAjaxDieContinueException $exception ) {
			$output = ob_get_contents() . $exception->getMessage();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_filter( 'wp_die_ajax_handler', $handler_filter );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST    = array();
			$_REQUEST = array();
		}
		return json_decode( $output, true ) ?? $output;
	}

	/**
	 * Every connection action must enforce the same nonce and access boundaries.
	 *
	 * @return void
	 */
	public function test_all_ajax_actions_require_nonce_and_assistant_access() {
		foreach ( array( 'start', 'complete', 'disconnect', 'status', 'set_model', 'welcome_seen' ) as $suffix ) {
			$this->assertNotFalse( has_action( 'wp_ajax_mainwp_assistant_chatgpt_' . $suffix ) );
			$this->assertSame( -1, $this->ajax( $suffix, array(), false ) );
		}
		$this->set_current_user_as_subscriber();
		foreach ( array( 'start', 'complete', 'disconnect', 'status', 'set_model', 'welcome_seen' ) as $suffix ) {
			$this->assertSame( 'forbidden', $this->ajax( $suffix )['data']['error_code'] );
		}
		wp_set_current_user( $this->user_id );
		$deny = static function () {
			return array();
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $deny );
		try {
			$this->assertSame( 'forbidden', $this->ajax( 'status' )['data']['error_code'] );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $deny );
		}
		$this->assertTrue( $this->ajax( 'status' )['success'] );
	}

	/**
	 * Opaque authorization codes must survive parsing without exposing credentials.
	 *
	 * @return void
	 */
	public function test_ajax_connect_never_returns_credentials() {
		$start = $this->ajax( 'start' );
		$this->assertTrue( $start['success'] );
		$pending = get_user_meta( $this->user_id, Plan::PENDING, true );
		$this->assertStringNotContainsString( $pending['verifier'], wp_json_encode( $start ) );
		$this->claims['nonce'] = $pending['nonce'];
		$tokens                = $this->tokens();
		$this->token_reply     = $this->response( $tokens );
		$code                  = 'code/with+plus=and&query';
		$response              = $this->ajax( 'complete', array( 'callback' => $this->callback_url( array( 'code' => $code ) ) ) );
		parse_str( $this->calls( Plan::TOKEN )[0]['args']['body'], $body );
		$this->assertSame( $code, $body['code'] );
		$this->assertTrue( $response['success'] );
		foreach ( array( $tokens['access_token'], $tokens['refresh_token'], $tokens['id_token'], $pending['verifier'], 'file_key', 'encrypted_val' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, wp_json_encode( $response ) );
		}
		$this->assertStringNotContainsString( $tokens['id_token'], wp_json_encode( $this->record() ) );
	}

	/**
	 * Request parameters must not let one user operate another user's connection.
	 *
	 * @return void
	 */
	public function test_user_b_actions_cannot_see_or_change_user_a() {
		$this->connect();
		$before = $this->record();
		$this->start();
		$callback_a = $this->callback_url();
		$user_b     = $this->create_other_admin();
		wp_set_current_user( $user_b );
		$payload = array(
			'user_id'  => $this->user_id,
			'callback' => $callback_a,
			'model'    => 'second',
		);
		$this->assertFalse( $this->ajax( 'status', $payload )['data']['connected'] );
		$start = $this->ajax( 'start', $payload );
		$this->assertTrue( $start['success'] );
		$this->assertNotEmpty( get_user_meta( $user_b, Plan::PENDING, true ) );
		$this->assertFalse( $this->ajax( 'complete', $payload )['success'] );
		$this->assertFalse( $this->ajax( 'set_model', $payload )['success'] );
		$this->assertTrue( $this->ajax( 'welcome_seen', $payload )['success'] );
		$this->assertTrue( $this->ajax( 'disconnect', $payload )['success'] );
		$this->assertSame( $before, $this->record() );
		$this->assertWPError( Plan::get_access_token( $user_b ) );
		$this->assertWPError( Plan::get_access_token( $this->user_id ) );
		$this->assertFalse( Plan::get_status( $this->user_id )['connected'] );
		wp_set_current_user( $this->user_id );
		$this->assertSame( 'access-secret-one', Plan::get_access_token( $this->user_id ) );
		foreach ( array( 'status', 'set_model', 'welcome_seen', 'disconnect' ) as $suffix ) {
			$response = $this->ajax( $suffix, array( 'model' => 'second' ) );
			$this->assertTrue( $response['success'] );
			foreach ( array( 'access-secret', 'refresh-secret', 'encrypted_val', 'file_key', 'id_token' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, wp_json_encode( $response ) );
			}
		}
	}
}
