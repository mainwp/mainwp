<?php
/**
 * Consent screen and decision tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase

/**
 * Class Test_MainWP_OAuth_Consent
 */
class Test_MainWP_OAuth_Consent extends OAuth_Authorize_Test_Case {

	/**
	 * Headline of a consent page: the app's identity.
	 *
	 * @param string $body Page body.
	 * @return string
	 */
	private function headline( string $body ): string {
		$this->assertSame( 1, preg_match( '#<h1 class="mainwp-oauth-headline">(.*?)</h1>#s', $body, $match ), 'headline present' );
		return html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES );
	}

	/**
	 * Visible text of a page, tags stripped and entities decoded.
	 *
	 * @param string $body Page body.
	 * @return string
	 */
	private function text( string $body ): string {
		return preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES ) );
	}

	/**
	 * Status of the only request row.
	 *
	 * @return string
	 */
	private function request_status(): string {
		$rows = $this->requests();
		$this->assertCount( 1, $rows );
		return $rows[0]['status'];
	}

	/**
	 * Stored client row with a descriptor for it in the double.
	 *
	 * @param string $kind   `registered` or `webapp`.
	 * @param array  $fields create_client() fields.
	 * @param array  $extra  Descriptor fields.
	 * @return string Client id.
	 */
	private function stored_client( string $kind, array $fields, array $extra = array() ): string {
		$created = $this->store->create_client( $kind, $fields );
		$this->assertSame( OAuth_Store::OK, $created['outcome'] );
		$this->clients->add(
			array_merge(
				array(
					'client_id'     => $created['client_id'],
					'kind'          => $kind,
					'client_name'   => $fields['client_name'] ?? null,
					'redirect_rule' => 'webapp' === $kind ? 'exact' : 'registered_loopback',
					'redirect_uris' => $fields['redirect_uris'],
				),
				$extra
			)
		);
		return $created['client_id'];
	}

	public function test_request_key_is_server_generated_not_client_state() {
		$first  = $this->consent();
		$second = $this->consent();
		$rows   = $this->requests();

		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9_-]{43}$/', $first['request_key'] ), '256 random bits, base64url' );
		$this->assertNotSame( 'client-state-123', $first['request_key'] );
		$this->assertNotSame( $first['request_key'], $second['request_key'], 'the same state gets a new key' );
		$this->assertSame( $first['request_key'], $rows[0]['request_key'] );
		$this->assertSame( 'client-state-123', $rows[0]['state'], 'state is kept only to echo back' );
		$this->assertStringNotContainsString( 'name="state"', $first['body'] );
	}

	public function test_approval_by_other_user_is_refused() {
		$consent = $this->consent();
		$other   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$response = $this->decide( $consent, array( '_wpnonce' => wp_create_nonce( 'mainwp_oauth_consent_' . $consent['request_key'] ) ) );

		$this->assert_error_page( $response, 'request_unavailable' );
		$this->assertSame( 'pending', $this->request_status() );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_approve_requires_nonce_bound_to_request_key() {
		$consent = $this->consent();
		foreach ( array( wp_create_nonce( 'mainwp_oauth_consent_' . str_repeat( 'x', 43 ) ), wp_create_nonce( 'mainwp_oauth_consent' ), 'deadbeef00', null ) as $nonce ) {
			$this->assert_error_page( $this->decide( $consent, array( '_wpnonce' => $nonce ) ), 'invalid_nonce', (string) $nonce );
		}
		$this->assertSame( 'pending', $this->request_status() );
		$this->assertSame( array(), $this->codes() );

		$this->assertSame( 302, $this->decide( $consent )->status(), 'the bound nonce works' );
	}

	public function test_approve_rereads_parameters_from_stored_request() {
		$consent  = $this->consent();
		$response = $this->post_raw(
			$this->query(
				array(
					'request_key'    => $consent['request_key'],
					'decision'       => 'approve',
					'access'         => 'write',
					'policy'         => 'standard',
					'_wpnonce'       => $consent['nonce'],
					'redirect_uri'   => 'https://attacker.example/cb',
					'state'          => 'forged',
					'client_id'      => self::CLAUDE_AI,
					'scope'          => 'offline_access',
					'code_challenge' => str_repeat( 'A', 43 ),
				)
			)
		);
		$query    = $this->assert_client_redirect( $response, self::REDIRECT );
		$this->assertSame( 'client-state-123', ( $query['state'] ?? '' ) );
		$codes = $this->codes();
		$this->assertSame( self::REDIRECT, $codes[0]['redirect_uri'] );
		$this->assertSame( self::CLIENT_ID, $codes[0]['client_id'] );
		$this->assertSame( $this->challenge( self::VERIFIER ), $codes[0]['code_challenge'] );
		$this->assertSame( 'mainwp:read mainwp:write offline_access', $codes[0]['scope'] );
	}

	public function test_plain_permalink_consent_posts_to_query_authorize_endpoint() {
		$this->assertStringContainsString( 'action="' . self::HOME . '/mainwp-oauth/authorize"', $this->consent()['body'] );

		$this->set_site_option( 'permalink_structure', '' );
		$consent = $this->consent( array( 'resource' => self::HOME . '/?rest_route=/mainwp-remote/v1/mcp' ) );
		$this->assertStringContainsString( 'action="' . self::HOME . '/?mainwp_oauth=authorize"', $consent['body'] );
		$this->assertStringContainsString( 'method="post"', $consent['body'] );
	}

	public function test_expired_consent_request_is_refused() {
		$consent = $this->consent();
		$this->tick( OAuth_Store::REQUEST_LIFETIME + 1 );

		$this->assert_error_page( $this->decide( $consent ), 'request_unavailable' );
		$this->assert_error_page( $this->decide( $consent, array( 'decision' => 'deny' ) ), 'request_unavailable' );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_completed_consent_request_cannot_be_reused() {
		$consent = $this->consent();
		$this->assertSame( 302, $this->decide( $consent )->status() );

		$this->assert_error_page( $this->decide( $consent ), 'request_unavailable', 'replayed form' );
		$this->assert_error_page( $this->decide( $consent, array( 'decision' => 'deny' ) ), 'request_unavailable', 'deny after approve' );
		$this->assertCount( 1, $this->codes() );
		$this->assertSame( 'approved', $this->request_status() );
	}

	public function test_parallel_consent_approval_issues_one_code() {
		$consent = $this->consent();
		$inner   = null;
		// The second submission arrives after the first read the row and before it claimed it.
		$this->store->on_request_read = function () use ( $consent, &$inner ) {
			$inner = $this->decide( $consent );
		};

		$outer = $this->decide( $consent );

		$this->assertInstanceOf( Remote_MCP_Response::class, $inner );
		$this->assertSame( 302, $inner->status(), 'one submission wins' );
		$this->assert_error_page( $outer, 'already_answered', 'the other gets the error page' );
		$this->assertCount( 1, $this->codes() );
	}

	public function test_consent_approval_after_password_change_is_refused() {
		$consent = $this->consent();
		wp_set_password( 'a-new-password-1234', $this->user_id );
		// What the password-change trigger does.
		$this->assertNotNull( $this->store->bump_user_generation( $this->user_id, 'password_changed' ) );

		$response = $this->decide( $consent );

		$this->assertSame( 400, $response->status() );
		$this->assertNull( $response->header( 'Location' ) );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_generation_bump_between_consent_read_and_claim_refuses_decision() {
		$consent                      = $this->consent();
		$this->store->on_request_read = function () {
			$this->store->bump_site_generation( 'home_changed' );
		};

		$this->assert_error_page( $this->decide( $consent ), 'already_answered' );
		$this->assertSame( 'pending', $this->request_status(), 'the claim compared generations in its own statement' );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_approval_racing_denial_has_one_terminal_outcome() {
		$consent                      = $this->consent();
		$inner                        = null;
		$this->store->on_request_read = function () use ( $consent, &$inner ) {
			$inner = $this->decide( $consent, array( 'decision' => 'deny' ) );
		};
		$outer                        = $this->decide( $consent );

		$this->assertSame( 'access_denied', $this->assert_client_redirect( $inner, self::REDIRECT )['error'] );
		$this->assert_error_page( $outer, 'already_answered' );
		$this->assertSame( 'denied', $this->request_status() );
		$this->assertSame( array(), $this->codes() );

		// The reverse order: the approval wins and the denial gets the error page.
		$consent                      = $this->consent();
		$this->store->on_request_read = function () use ( $consent, &$inner ) {
			$inner = $this->decide( $consent );
		};
		$outer                        = $this->decide( $consent, array( 'decision' => 'deny' ) );
		$this->assertSame( 302, $inner->status() );
		$this->assert_error_page( $outer, 'already_answered' );
		$this->assertCount( 1, $this->codes() );
	}

	public function test_code_insert_failure_marks_request_failed_without_redirect() {
		$consent           = $this->consent();
		$control           = $this->fail_queries( 'INSERT INTO `' . Remote_MCP_Install::table( 'codes' ) . '`' );
		$control->failures = 1;

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		try {
			$response = $this->decide( $consent );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$this->assert_error_page( $response, 'code_failed' );
		$this->assertStringContainsString( 'Start the connection again from your AI app', $this->text( $response->body() ) );
		$this->assertSame( 'failed', $this->request_status() );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_approve_or_deny_for_client_removed_from_bundle_issues_no_code_and_no_redirect() {
		foreach ( array( 'approve', 'deny' ) as $decision ) {
			$consent = $this->consent();
			$saved   = $this->clients->clients[ self::CLIENT_ID ];
			// A plugin update dropped the client while the request was pending.
			unset( $this->clients->clients[ self::CLIENT_ID ] );

			$response = $this->decide( $consent, array( 'decision' => $decision ) );

			$this->assert_error_page( $response, 'client_unavailable', $decision );
			$rows = $this->requests();
			$this->assertSame( 'failed', end( $rows )['status'], $decision );
			$this->assertSame( array(), $this->codes(), $decision );
			$this->clients->clients[ self::CLIENT_ID ] = $saved;
		}

		// A redirect the client no longer accepts is the same failure.
		$consent                        = $this->consent();
		$this->clients->allow_redirects = false;
		$this->assert_error_page( $this->decide( $consent ), 'client_unavailable', 'redirect no longer allowed' );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_http_consent_post_is_refused_without_state_change() {
		$consent                = $this->consent();
		$_SERVER['HTTPS']       = '';
		$_SERVER['SERVER_PORT'] = '80';
		$this->clients->lookups = array();

		$this->assert_error_page( $this->decide( $consent ), 'https_required' );
		$this->assert_error_page( $this->decide( $consent, array( 'decision' => 'deny' ) ), 'https_required' );
		$this->assertSame( 'pending', $this->request_status() );
		$this->assertSame( array(), $this->codes() );
		$this->assertSame( array(), $this->clients->lookups );
	}

	public function test_user_without_manage_options_is_refused() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assert_error_page( $this->authorize(), 'insufficient_rights' );
		$this->assertSame( array(), $this->requests() );

		// A user who lost the capability after the consent screen was rendered.
		wp_set_current_user( $this->user_id );
		$consent = $this->consent();
		get_userdata( $this->user_id )->set_role( 'editor' );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user_id );

		$this->assert_error_page( $this->decide( $consent ), 'insufficient_rights' );
		$this->assertSame( 'pending', $this->request_status() );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_consent_sends_frame_deny_and_frame_ancestors_none() {
		$response = $this->consent()['response'];
		$this->assertSame( 'DENY', $response->header( 'X-Frame-Options' ) );
		$csp = (string) $response->header( 'Content-Security-Policy' );
		$this->assertStringContainsString( "frame-ancestors 'none'", $csp );
		$this->assertStringContainsString( "default-src 'self'", $csp );
		// form-action would block the hand-off to the client's redirect URI in Chrome.
		$this->assertStringNotContainsString( 'form-action', $csp );
	}

	/**
	 * Error responses disable page caching and framing.
	 */
	public function test_error_page_sends_frame_deny_and_no_store() {
		$this->reset_page_cache_flag();
		$this->assertFalse( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'not requested before a handler runs' );
		$response = $this->authorize( array( 'client_id' => 'https://evil.example/client.json' ) );
		$this->assert_error_page( $response, 'unsupported_client' );
		$this->assertStringContainsString( "frame-ancestors 'none'", (string) $response->header( 'Content-Security-Policy' ) );
		$this->assertStringContainsString( 'no-store', (string) $response->header( 'Cache-Control' ) );
		$this->assertTrue( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'requested by the handler' );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	public function test_consent_shows_client_host_redirect_host_user_and_role() {
		wp_update_user(
			array(
				'ID'           => $this->user_id,
				'display_name' => 'Grace Hopper',
			)
		);
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user_id );
		$user = get_userdata( $this->user_id );
		$text = $this->text( $this->consent()['body'] );

		$this->assertStringContainsString( 'Claude Code', $text );
		$this->assertStringContainsString( 'claude.ai', $text, 'client_id host' );
		$this->assertStringContainsString( "You'll go back to the app on this computer after you decide.", $text, 'a loopback return is named in words' );
		$this->assertStringContainsString( 'Signed in as Grace Hopper (' . $user->user_login . '), Administrator', $text );
		$this->assertStringContainsString( 'Not you? Log out', $text );
		$this->assertStringContainsString( "Only approve if you started this yourself, just now, in Claude Code. If you didn't, choose Deny.", $text );

		// The login is left out when it repeats the display name.
		wp_update_user(
			array(
				'ID'           => $this->user_id,
				'display_name' => $user->user_login,
			)
		);
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user_id );
		$this->assertStringContainsString( 'Signed in as ' . $user->user_login . ', Administrator', $this->text( $this->consent()['body'] ) );
	}

	public function test_client_name_never_rendered_as_identity() {
		$this->clients->clients[ self::CLIENT_ID ]['client_name'] = 'MainWP Official Support';
		$body = $this->consent()['body'];
		$this->assertStringNotContainsString( 'MainWP Official Support', $this->headline( $body ) );
		$this->assertStringContainsString( 'Claude Code', $this->headline( $body ) );
		$this->assertStringContainsString( 'It calls itself "MainWP Official Support".', $this->text( $body ), 'shown only as secondary text' );

		// A name that matches the label adds nothing.
		$this->clients->clients[ self::CLIENT_ID ]['client_name'] = 'Claude Code';
		$this->assertStringNotContainsString( 'calls itself', $this->text( $this->consent()['body'] ) );

		// A document name over 80 characters is cut for display.
		$this->clients->clients[ self::CLIENT_ID ]['client_name'] = str_repeat( 'N', 120 );
		$text = $this->text( $this->consent()['body'] );
		$this->assertStringContainsString( str_repeat( 'N', 80 ), $text );
		$this->assertStringNotContainsString( str_repeat( 'N', 81 ), $text );

		// Static clients have no name of their own; their label is ours.
		$this->clients->clients[ self::CURSOR ]['client_name'] = 'Not Cursor';
		$body = $this->consent(
			array(
				'client_id'    => self::CURSOR,
				'redirect_uri' => 'http://localhost:8787/callback',
			)
		)['body'];
		$this->assertStringNotContainsString( 'Not Cursor', $body );
	}

	public function test_loopback_only_client_shows_warning() {
		$warning = "You'll go back to the app on this computer after you decide.";
		$this->assertStringContainsString( $warning, $this->text( $this->consent()['body'] ), 'Claude Code: loopback only' );
		$this->assertStringContainsString(
			$warning,
			$this->text(
				$this->consent(
					array(
						'client_id'    => self::GEMINI,
						'redirect_uri' => 'http://localhost:7777/oauth/callback',
					)
				)['body']
			),
			'Gemini CLI'
		);
		$this->assertStringNotContainsString(
			$warning,
			$this->text(
				$this->consent(
					array(
						'client_id'    => self::CLAUDE_AI,
						'redirect_uri' => self::CLAUDE_AI_CB,
					)
				)['body']
			),
			'hosted callback'
		);
		$this->assertStringNotContainsString(
			$warning,
			$this->text(
				$this->consent(
					array(
						'client_id'    => self::CURSOR,
						'redirect_uri' => 'http://localhost:8787/callback',
					)
				)['body']
			),
			'Cursor can also call back to its cloud'
		);
	}

	/**
	 * Consent responses disable page caching.
	 */
	public function test_consent_response_is_no_store_and_donotcachepage() {
		$this->reset_page_cache_flag();
		$this->assertFalse( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'not requested before a handler runs' );
		$response = $this->consent()['response'];
		$this->assertStringContainsString( 'no-store', (string) $response->header( 'Cache-Control' ) );
		$this->assertTrue( \MainWP\Dashboard\Remote_MCP\OAuth_Metadata::page_cache_disabled(), 'requested by the handler' );
		$this->assertTrue( DONOTCACHEPAGE );
		$this->assertStringNotContainsString( 'wp-content/themes', $response->body(), 'no theme on a page that issues codes' );
	}

	public function test_bearer_token_does_not_authenticate_consent_screen() {
		$connection = $this->connect();
		$consent    = $this->consent();
		wp_set_current_user( 0 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $connection['access_token'];

		$response = $this->authorize();
		$this->assertSame( 302, $response->status() );
		$this->assertStringStartsWith( self::HOME . '/wp-login.php?', (string) $response->header( 'Location' ), 'a bearer token is nobody here' );

		$this->assert_error_page( $this->decide( $consent ), 'login_required' );
		$this->assertSame( 0, get_current_user_id() );
		$rows = $this->requests();
		$this->assertSame( 'pending', end( $rows )['status'] );
		$this->assertCount( 1, $this->codes(), 'only the code connect() redeemed' );
	}

	public function test_deny_redirects_access_denied_with_iss() {
		$consent = $this->consent();
		$query   = $this->assert_client_redirect( $this->decide( $consent, array( 'decision' => 'deny' ) ), self::REDIRECT );

		$this->assertSame( 'access_denied', ( $query['error'] ?? '' ) );
		$this->assertSame( 'client-state-123', ( $query['state'] ?? '' ) );
		$this->assertSame( self::HOME, ( $query['iss'] ?? '' ) );
		$this->assertArrayNotHasKey( 'code', $query );
		$this->assertSame( 'denied', $this->request_status() );
		$this->assertSame( array(), $this->codes() );
	}

	public function test_approve_redirects_with_code_state_and_iss() {
		$consent = $this->consent();
		$query   = $this->assert_client_redirect( $this->decide( $consent ), self::REDIRECT );

		$this->assertStringStartsWith( 'mwpac_', ( $query['code'] ?? '' ) );
		$this->assertSame( 'client-state-123', ( $query['state'] ?? '' ) );
		$this->assertSame( self::HOME, ( $query['iss'] ?? '' ) );

		$redeemed = $this->store->redeem_code( ( $query['code'] ?? '' ), self::CLIENT_ID, self::VERIFIER );
		$this->assertSame( OAuth_Store::OK, $redeemed['outcome'] );
		$grant = $this->grant( $redeemed['grant_id'] );
		$this->assertSame( 'mainwp:read mainwp:write offline_access', $grant['scope'] );
		$this->assertSame( 'standard', $grant['approval_policy'] );
	}

	public function test_hands_off_policy_hidden_for_team_control_limited_user() {
		$this->assertStringContainsString( 'value="hands_off"', $this->consent()['body'], 'a full administrator sees it' );

		$limit = function () {
			return array( 1 );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $limit );
		$consent = $this->consent();
		$this->assertStringNotContainsString( 'value="hands_off"', $consent['body'] );
		$this->assertStringContainsString( 'value="standard"', $consent['body'] );

		// Rechecked on the server, whatever the form showed.
		$this->assert_error_page( $this->decide( $consent, array( 'policy' => 'hands_off' ) ), 'invalid_choice' );
		$this->assertSame( array(), $this->codes() );

		remove_filter( 'mainwp_currentuserallowedaccesssites', $limit );
		$consent = $this->consent();
		$this->assertSame( 302, $this->decide( $consent, array( 'policy' => 'hands_off' ) )->status() );
		$this->assertSame( 'hands_off', $this->codes()[0]['approval_policy'] );
	}

	public function test_policy_post_value_outside_allowed_set_refused() {
		$consent = $this->consent();
		foreach ( array(
			array( 'policy' => 'yolo' ),
			array( 'policy' => 'STANDARD' ),
			array( 'policy' => null ),
			array( 'access' => 'admin' ),
			array( 'access' => null ),
			array( 'decision' => 'maybe' ),
		) as $fields ) {
			$this->assert_error_page( $this->decide( $consent, $fields ), 'invalid_choice', wp_json_encode( $fields ) );
		}
		$this->assertSame( 'pending', $this->request_status() );

		$this->assertSame(
			302,
			$this->decide(
				$consent,
				array(
					'access' => 'read',
					'policy' => 'strict',
				)
			)->status()
		);
		$this->assertSame( 'mainwp:read offline_access', $this->codes()[0]['scope'], 'View only drops write' );
		$this->assertSame( 'strict', $this->codes()[0]['approval_policy'] );

		// Write can't be chosen when the app didn't ask for it.
		$consent = $this->consent( array( 'scope' => 'mainwp:read offline_access' ) );
		$this->assert_error_page( $this->decide( $consent, array( 'policy' => null ) ), 'invalid_choice', 'write not requested' );
	}

	public function test_static_client_consent_shows_honest_label() {
		$body = $this->consent(
			array(
				'client_id'    => self::CURSOR,
				'redirect_uri' => 'http://localhost:8787/callback',
			)
		)['body'];
		$this->assertSame( 'Cursor wants to connect', $this->headline( $body ) );
		$this->assertStringContainsString( "Or another program using Cursor's settings. You'll go back to localhost after you decide.", $this->text( $body ) );

		$body = $this->consent(
			array(
				'client_id'    => self::GEMINI,
				'redirect_uri' => 'http://localhost:7777/oauth/callback',
			)
		)['body'];
		$this->assertSame( 'Gemini CLI wants to connect', $this->headline( $body ) );
		$this->assertStringContainsString( "Or another program using Gemini CLI's settings. You'll go back to the app on this computer after you decide.", $this->text( $body ) );
	}

	public function test_registered_client_consent_label_marks_name_unchecked() {
		$client_id = $this->stored_client(
			'registered',
			array(
				'client_name'   => 'Desk Helper',
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
			)
		);
		$consent   = $this->consent(
			array(
				'client_id'    => $client_id,
				'redirect_uri' => 'http://127.0.0.1:4567/cb',
			)
		);
		$this->assertSame( 'Desk Helper wants to connect', $this->headline( $consent['body'] ) );
		$this->assertStringContainsString( '<bdi>Desk Helper</bdi>', $consent['body'], 'a right-to-left name can\'t reorder the words after it' );
		$this->assertStringContainsString( "An app on this computer. The name is the app's own; MainWP hasn't checked it.", $this->text( $consent['body'] ) );
		$this->assertStringContainsString( "Only approve if you started this yourself, just now, in Desk Helper. If you didn't, choose Deny.", $this->text( $consent['body'] ) );

		// The first consent marks the registration consented, so it is kept.
		$this->assertNull( $this->store->get_client( $client_id )['first_consented_at'] );
		$this->assertSame( 302, $this->decide( $consent )->status() );
		$this->assertNotNull( $this->store->get_client( $client_id )['first_consented_at'] );

		$this->clients->clients[ $client_id ]['client_name'] = null;
		$body = $this->consent(
			array(
				'client_id'    => $client_id,
				'redirect_uri' => 'http://127.0.0.1/cb',
			)
		)['body'];
		$this->assertSame( 'An app on this computer wants to connect', $this->headline( $body ) );
		$this->assertStringContainsString( "MainWP hasn't checked this app.", $this->text( $body ) );
	}

	public function test_webapp_consent_label_names_admin_and_redirect_host() {
		$admin     = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Admin',
			)
		);
		$client_id = $this->stored_client(
			'webapp',
			array(
				'client_name'   => 'Team Chat',
				'redirect_uris' => array( self::WEBAPP_CB ),
				'created_by'    => $admin,
			)
		);
		$body      = $this->consent(
			array(
				'client_id'    => $client_id,
				'redirect_uri' => self::WEBAPP_CB,
			)
		)['body'];

		$date = wp_date( get_option( 'date_format' ), $this->clock->now );
		$this->assertSame( 'Team Chat wants to connect', $this->headline( $body ) );
		$this->assertStringContainsString( "Added to this Dashboard by Ada Admin on {$date}. You'll go back to chat.example.net after you decide.", $this->text( $body ) );
	}

	public function test_registered_client_name_with_markup_is_escaped_on_consent() {
		$name      = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'';
		$client_id = $this->stored_client(
			'registered',
			array(
				'client_name'   => $name,
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
			)
		);
		$body      = $this->consent(
			array(
				'client_id'    => $client_id,
				'redirect_uri' => 'http://127.0.0.1/cb',
			)
		)['body'];

		$this->assertStringNotContainsString( '<script>alert', $body );
		$this->assertStringNotContainsString( '<img src=x', $body );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $body );
		$this->assertStringContainsString( $name . ' wants to connect', $this->headline( $body ) );
	}

	public function test_consent_decisions_fire_audit_events() {
		$consent = $this->consent();
		$query   = $this->assert_client_redirect( $this->decide( $consent ), self::REDIRECT );
		$this->assertSame( array( 'consent_approved', 'code_issued' ), $this->audit_names() );
		foreach ( $this->audits as list( , $fields ) ) {
			$this->assertSame( $this->user_id, $fields['user_id'] );
			$this->assertSame( 'claude.ai', $fields['client_host'] );
			$this->assertSame( 'bundled', $fields['client_kind'] );
			$encoded = wp_json_encode( $fields );
			$this->assertStringNotContainsString( ( $query['code'] ?? '' ), $encoded );
			$this->assertStringNotContainsString( 'client-state-123', $encoded );
			$this->assertStringNotContainsString( $consent['request_key'], $encoded );
		}
		$this->assertSame( 'standard', $this->audits[0][1]['approval_policy'] );

		$this->audits = array();
		$this->decide( $this->consent(), array( 'decision' => 'deny' ) );
		$this->assertSame( array( 'consent_denied' ), $this->audit_names() );

		// A refused submission decides nothing and logs no decision.
		$this->audits = array();
		$this->decide( $consent );
		$this->assertSame( array(), $this->audit_names() );
	}

	public function test_consent_access_text_built_from_catalog_categories() {
		$this->catalog->rows = array(
			$this->catalog_row( 'list_reports_v1', 'acme-reports', 'read' ),
			$this->catalog_row( 'list_widgets_v1', 'mainwp-acme-widgets', 'read' ),
			$this->catalog_row( 'delete_widget_v1', 'mainwp-acme-widgets', 'dashboard' ),
			$this->catalog_row( 'send_invoice_v1', 'acme-billing', 'confirm' ),
			$this->catalog_row( 'list_secrets_v1', 'acme-secrets', null, 'not_available' ),
			$this->catalog_row( 'list_vault_v1', 'acme-vault', 'read', 'not_available_yet' ),
		);
		$text                = $this->text( $this->consent()['body'] );

		$this->assertStringContainsString( 'acme reports', $text );
		$this->assertStringContainsString( 'acme widgets', $text );
		$this->assertStringContainsString( 'acme billing', $text, 'changes name the write categories' );
		$this->assertStringNotContainsString( 'acme secrets', $text, 'unavailable tools grant nothing' );
		$this->assertStringNotContainsString( 'acme vault', $text );
		$this->assertMatchesRegularExpression( '/See and change your (?:(?!acme reports)[^.])*data, and see your acme reports data\./', $text, 'a read-only category is never described as changeable' );

		// View-only requests describe the view categories only.
		$text = $this->text( $this->consent( array( 'scope' => 'mainwp:read' ) )['body'] );
		$this->assertStringContainsString( 'acme reports', $text );
		$this->assertStringNotContainsString( 'acme billing', $text );
	}

	public function test_consent_categories_put_core_before_extensions() {
		$this->catalog->rows = array(
			$this->catalog_row( 'matomo', 'mainwp-matomo', 'read' ),
			$this->catalog_row( 'updates', 'mainwp-updates', 'read' ),
			$this->catalog_row( 'sites', 'mainwp-sites', 'read' ),
			$this->catalog_row( 'alpha', 'acme-alpha', 'read' ),
		);
		$text                = $this->text( $this->consent()['body'] );
		$this->assertMatchesRegularExpression( '/See your Sites, Updates, acme alpha(?:,? and|,) matomo data\. Change nothing\./', $text );
	}

	/**
	 * Consent text for one kind of app, by the client and redirect the request names.
	 *
	 * @return array Kind => [ client_id, redirect_uri, headline, facts the page must say ].
	 */
	private function kinds(): array {
		$this->clients->add(
			array(
				'client_id'     => 'https://app.example.org/mcp-client.json',
				'fetched'       => true,
				'client_name'   => 'Helper App',
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
			)
		);
		$admin    = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Admin',
			)
		);
		$webapp   = $this->stored_client(
			'webapp',
			array(
				'client_name'   => 'Team Chat',
				'redirect_uris' => array( self::WEBAPP_CB ),
				'created_by'    => $admin,
			)
		);
		$register = $this->stored_client(
			'registered',
			array(
				'client_name'   => 'Desk Helper',
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
			)
		);
		return array(
			'bundled hosted'   => array( self::CLAUDE_AI, self::CLAUDE_AI_CB, 'Claude wants to connect', array( 'From claude.ai.', "You'll go back to claude.ai after you decide.", 'in Claude.' ) ),
			'bundled loopback' => array( self::CLIENT_ID, self::REDIRECT, 'Claude Code wants to connect', array( 'From claude.ai.', "You'll go back to the app on this computer after you decide.", 'in Claude Code.' ) ),
			'registered'       => array( $register, 'http://127.0.0.1:4567/cb', 'Desk Helper wants to connect', array( "An app on this computer. The name is the app's own; MainWP hasn't checked it.", "You'll go back to it after you decide.", 'in Desk Helper.' ) ),
			'web app'          => array( $webapp, self::WEBAPP_CB, 'Team Chat wants to connect', array( 'Added to this Dashboard by Ada Admin on ', "You'll go back to chat.example.net after you decide.", 'in Team Chat.' ) ),
			'static'           => array( self::CURSOR, 'https://www.cursor.com/agents/mcp/oauth/callback', 'Cursor wants to connect', array( "Or another program using Cursor's settings.", "You'll go back to www.cursor.com after you decide.", 'in Cursor.' ) ),
			'fetched'          => array( 'https://app.example.org/mcp-client.json', 'http://127.0.0.1:4567/cb', "An app MainWP doesn't know wants to connect", array( 'It\'s at app.example.org and calls itself "Helper App". MainWP hasn\'t checked this app.', "You'll go back to the app on this computer after you decide.", 'in the app.' ) ),
		);
	}

	public function test_every_kind_of_app_gets_every_protective_fact() {
		$access   = array(
			'View and make changes See and change your Sites and Updates data. When it has to ask you first is set below.',
			'View only See your Sites and Updates data. Change nothing.',
		);
		$policies = array(
			'Standard Ordinary changes run right away. Some are confirmed with you in the app, and the riskiest wait for your approval on this Dashboard.',
			'Strict Every change waits for your approval on this Dashboard.',
			"MainWP can't tell whether a person approved in the chat, and instructions hidden in a site's content could get the app to make changes.",
		);
		foreach ( $this->kinds() as $kind => list( $client_id, $redirect, $headline, $facts ) ) {
			$body = $this->consent(
				array(
					'client_id'    => $client_id,
					'redirect_uri' => $redirect,
				)
			)['body'];
			$text = $this->text( $body );
			$this->assertSame( $headline, $this->headline( $body ), $kind );
			$this->assertStringContainsString( 'MainWP Dashboard · dash.example.com', $text, $kind . ': whose Dashboard this is' );
			foreach ( array_merge( $facts, $access, $policies ) as $fact ) {
				$this->assertStringContainsString( $fact, $text, $kind );
			}
			$this->assertStringContainsString( 'Only approve if you started this yourself, just now, ' . end( $facts ) . " If you didn't, choose Deny.", $text, $kind . ': the caution' );
			$this->assertSame( 1, substr_count( $body, 'class="mainwp-oauth-warning"' ), $kind . ': one caution' );
			$this->assertStringNotContainsString( 'class="mainwp-oauth-note"', $body, $kind . ': no second box about where the approval goes' );
		}
	}

	public function test_policy_descriptions_match_the_tier_rules() {
		$body = $this->consent()['body'];
		$this->assertSame( 1, preg_match( '#<fieldset class="mainwp-oauth-policies">\s*<legend>([^<]*)</legend>(.*?)</fieldset>#s', $body, $fieldset ) );
		$this->assertSame( 'When should MainWP ask you before the app changes something?', $fieldset[1] );
		preg_match_all( '#value="([a-z_]+)"[^>]*> <strong>([^<]*)</strong> <span([^>]*)>([^<]*)</span>#', html_entity_decode( $fieldset[2], ENT_QUOTES ), $options, PREG_SET_ORDER );
		$this->assertSame( array( 'standard', 'strict', 'hands_off' ), array_column( $options, 1 ) );
		list( $standard, $strict, $hands_off ) = array_column( $options, 4 );
		$this->assertStringContainsString( 'Ordinary changes run right away', $standard, 'write tier runs directly' );
		$this->assertStringContainsString( 'the riskiest wait for your approval on this Dashboard', $standard, 'dashboard tier waits' );
		$this->assertSame( 'Every change waits for your approval on this Dashboard.', $strict );
		$this->assertStringContainsString( 'confirmed with you in the chat instead', $hands_off );
		foreach ( array( 'Adding sites', 'restoring or deleting backups', 'changing backup retention', "changing a site's URL or SSL settings", 'replacing the custom dashboard snippet', 'still wait for your approval here' ) as $floor ) {
			$this->assertStringContainsString( $floor, $hands_off, 'the HANDS_OFF_FLOOR exceptions are named' );
		}
		$this->assertStringContainsString( "MainWP can't tell whether a person approved", $hands_off );
		$this->assertStringContainsString( "instructions hidden in a site's content", $hands_off );
		$this->assertStringNotContainsString( 'never', $hands_off );
		$this->assertSame( ' class="mainwp-oauth-hands-off"', $options[2][3], 'Hands-off carries the warning edge' );
		$this->assertSame( '', $options[0][3] );
	}

	public function test_caution_sits_after_the_choices_and_right_above_the_buttons() {
		foreach ( array( 'mainwp:read mainwp:write offline_access', 'mainwp:read offline_access' ) as $scope ) {
			$body = $this->consent( array( 'scope' => $scope ) )['body'];
			$this->assertMatchesRegularExpression( '#</(?:fieldset|p)>\s*<p class="mainwp-oauth-warning">[^<]*</p>\s*<div class="mainwp-oauth-actions">\s*<button type="submit" name="decision" value="approve"#', $body, $scope );
			$this->assertLessThan( strpos( $body, 'mainwp-oauth-warning' ), strpos( $body, 'What the app can do' ) ?: strpos( $body, 'mainwp-oauth-access' ), $scope . ': the facts come first' );
			$this->assertLessThan( strpos( $body, 'mainwp-oauth-warning' ), (int) strpos( $body, 'mainwp-oauth-user' ) );
		}
	}

	public function test_pages_link_the_shared_stylesheet_the_csp_allows() {
		foreach ( array(
			'consent' => $this->consent()['response'],
			'error'   => $this->authorize( array( 'client_id' => 'https://evil.example/client.json' ) ),
		) as $page => $response ) {
			$this->assertSame( 1, preg_match_all( '/<link /', $response->body() ), $page );
			$this->assertSame( 1, preg_match( '#<link rel="\w+" href="([^"]+)">#', $response->body(), $link ), $page );
			$this->assertStringEndsWith( '/modules/remote-mcp/assets/remote-mcp-pages.css', $link[1], $page );
			$this->assertFileExists( dirname( __DIR__, 2 ) . '/modules/remote-mcp/assets/remote-mcp-pages.css' );
			$this->assertStringContainsString( "style-src 'self'", (string) $response->header( 'Content-Security-Policy' ), $page );
			$this->assertStringNotContainsString( '<style', $response->body(), $page );
			$this->assertStringNotContainsString( 'style="', $response->body(), $page );
		}
	}

	public function test_error_page_names_the_switch_and_links_home() {
		$response = $this->authorize( array( 'client_id' => 'https://evil.example/client.json' ) );
		$this->assert_error_page( $response, 'unsupported_client' );
		$text = $this->text( $response->body() );
		$this->assertStringContainsString( "This app can't connect", $text );
		$this->assertStringContainsString( "An administrator can allow it under AI > MainWP MCP > Connect an app > Advanced > Which apps may connect: turn on the app's own switch, or Let other apps connect for apps that aren't listed.", $text );
		$this->assertStringContainsString( 'connection token', $text );
		$this->assertStringContainsString( 'MainWP Dashboard · dash.example.com', $text );
		$this->assertStringContainsString( '<a href="' . esc_url( admin_url() ) . '">Go to your Dashboard</a>', $response->body() );
	}
}
