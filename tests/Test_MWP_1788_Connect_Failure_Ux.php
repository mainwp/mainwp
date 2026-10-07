<?php
/**
 * MWP-1788 connect/sync failure UX.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1788_Connect_Failure_Ux
 */
class Test_MWP_1788_Connect_Failure_Ux extends \WP_UnitTestCase {

	/**
	 * 403 is always treated as WAF-likely.
	 */
	public function test_403_is_waf_likely(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_waf_likely( 403 ) );
	}

	/**
	 * Cloudflare markers without 403 still count as WAF-likely.
	 */
	public function test_cloudflare_body_is_waf_likely(): void {
		$this->assertTrue(
			\MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_waf_likely(
				200,
				'cf-ray: 123',
				'Attention Required! cloudflare'
			)
		);
		$this->assertFalse(
			\MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_waf_likely(
				200,
				'content-type: text/html',
				'Hello from WordPress'
			)
		);
	}

	/**
	 * Hide filter: empty body still keeps status, headers, IP, and User-Agent.
	 */
	public function test_hidden_body_is_not_leaked_into_failure_ux(): void {
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '203.0.113.10',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$_SERVER['SERVER_ADDR'] = '10.0.0.1';

		$secret  = '<html><title>leaked child html</title><p>uniqueId=should-not-appear</p></html>';
		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 403,
				'headers'     => "HTTP/1.1 403 Forbidden\r\ncf-ray: abc\r\nserver: cloudflare\r\n",
				'body'        => '',
				'resolved_ip' => '104.21.12.34',
			)
		);
		$html = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );

		$this->assertSame( '', $details['response']['body'] );
		$this->assertStringContainsString( 'Status: 403', $details['response']['text'] );
		$this->assertStringContainsString( 'cf-ray: abc', $details['response']['headers'] );
		$this->assertStringContainsString( 'User-Agent MainWP sends', $html );
		$this->assertStringContainsString( 'Dashboard outbound IP (allowlist this)', $html );
		$this->assertStringNotContainsString( 'leaked child html', $html );
		$this->assertStringNotContainsString( $secret, $html );
		$this->assertStringNotContainsString( 'should-not-appear', $html );
	}

	/**
	 * Readable response never presents hidden placeholder as the body.
	 */
	public function test_readable_response_skips_hidden_placeholder(): void {
		$summary = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_response(
			403,
			"HTTP/1.1 403 Forbidden\r\ncf-ray: abc\r\nserver: cloudflare\r\n",
			'[hidden response data]'
		);

		$this->assertStringContainsString( '403', $summary['status'] );
		$this->assertStringContainsString( 'cf-ray: abc', $summary['headers'] );
		$this->assertStringContainsString( 'server: cloudflare', $summary['headers'] );
		$this->assertSame( '', $summary['body'] );
		$this->assertStringContainsString( 'Status: 403', $summary['text'] );
		$this->assertStringNotContainsString( '[hidden response data]', $summary['text'] );
	}

	/**
	 * Body excerpt is stripped, truncated, and redacts secrets.
	 */
	public function test_body_excerpt_is_safe(): void {
		$body = '<html><title>Attention Required</title><p>password=supersecret uniqueId=abc123</p></html>';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );

		$this->assertStringContainsString( 'Attention Required', $text );
		$this->assertStringContainsString( 'password=[redacted]', $text );
		$this->assertStringContainsString( 'uniqueId=[redacted]', $text );
		$this->assertStringNotContainsString( 'supersecret', $text );
		$this->assertStringNotContainsString( '<title>', $text );
	}

	/**
	 * A title glued to the next paragraph must not swallow the password name.
	 */
	public function test_adjacent_title_and_paragraph_keep_password_name(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body(
			'<html><title>Attention Required</title><p>password=supersecret uniqueId=abc123</p></html>'
		);

		$this->assertStringContainsString( 'Attention Required', $text );
		$this->assertStringContainsString( 'password=[redacted]', $text );
		$this->assertStringContainsString( 'uniqueId=[redacted]', $text );
		$this->assertStringNotContainsString( 'supersecret', $text );
		$this->assertStringNotContainsString( 'Requiredpassword', $text );
	}

	/**
	 * Table cells must not glue the label onto password=.
	 */
	public function test_table_cells_do_not_glue_password(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<td>Login</td><td>password=hunter2</td>' );

		$this->assertStringContainsString( 'password=[redacted]', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
		$this->assertStringNotContainsString( 'Loginpassword', $text );
	}

	/**
	 * Div cells must redact a pwd: label instead of gluing it to the previous word.
	 */
	public function test_div_cells_redact_pwd_label(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<div>Error</div><div>pwd: hunter2</div>' );

		$this->assertStringContainsString( 'pwd=[redacted]', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
		$this->assertStringNotContainsString( 'Errorpwd', $text );
	}

	/**
	 * Opening and inline tags must not glue text onto a name that only matches exactly.
	 */
	public function test_opening_and_inline_tags_do_not_glue_exact_names(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body(
			'Login<br>signature=sig111 <b>Note</b>nonce=non222 <span>x</span>token=tok333 Error<!-- c -->uniqueId=uid444'
		);

		$this->assertStringContainsString( 'signature=[redacted]', $text );
		$this->assertStringContainsString( 'nonce=[redacted]', $text );
		$this->assertStringContainsString( 'token=[redacted]', $text );
		$this->assertStringContainsString( 'uniqueId=[redacted]', $text );
		foreach ( array( 'sig111', 'non222', 'tok333', 'uid444' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	/**
	 * Names that end in password with no underscore are still secrets.
	 */
	public function test_unsuffixed_password_names_are_redacted(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'adminpassword' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'dbpassword' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'dbpasswd' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'mypwd' ) );

		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'adminpassword=hunter2 dbpassword=x' );

		$this->assertStringContainsString( 'adminpassword=[redacted]', $text );
		$this->assertStringContainsString( 'dbpassword=[redacted]', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
		$this->assertStringNotContainsString( 'dbpassword=x', $text );
	}

	/**
	 * WordPress password field names (user_pass, pass1, http_pass) are secrets too.
	 */
	public function test_wordpress_pass_field_names_are_redacted(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '{"user_pass":"hash111","pass1":"p222","pass2":"q444","pass":"r555","http_pass":"h333"} bypass=visible' );

		foreach ( array( 'hash111', 'p222', 'q444', 'r555', 'h333' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
		$this->assertStringContainsString( 'bypass=visible', $text );

		// A bare underscore suffix used as the whole name is still a secret.
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '_pass=u666 _token=t777' );
		$this->assertStringNotContainsString( 'u666', $text );
		$this->assertStringNotContainsString( 't777', $text );
	}

	/**
	 * Truncation cuts on a character boundary so the escaped response is not blanked.
	 */
	public function test_truncation_keeps_valid_utf8(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( str_repeat( 'a', 1199 ) . "\u{00E9}tail" );

		$this->assertNotSame( '', esc_html( $text ) );
		$this->assertSame( 1, preg_match( '//u', $text ) );
		$this->assertSame( str_repeat( 'a', 1199 ) . "\u{00E9}...", $text );

		// The limit counts characters: exactly 1200, or 700 two-byte characters, stays whole.
		$exact = str_repeat( 'b', 1200 );
		$this->assertSame( $exact, \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $exact ) );
		$wide = str_repeat( "\u{00E9}", 700 );
		$this->assertSame( $wide, \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $wide ) );
	}

	/**
	 * A tag inside a parameter name must not hide the name from redaction.
	 */
	public function test_tag_inside_name_is_still_redacted(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<p>pass<strong>word=s111</strong></p><span>pass</span><span>word</span>=s222 <i>to</i>ken=s333 sig<b>nature</b>: s444 pa<b>ss</b>wo<i>rd</i>=s777' );

		foreach ( array( 's111', 's222', 's333', 's444', 's777' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}

		// Words that are not a sensitive name keep their spacing and values.
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<td>Login</td><td>password=s555</td>' );
		$this->assertStringNotContainsString( 's555', $text );
		$this->assertStringContainsString( 'Login password=', $text );
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<p>Site name: Example Blog</p>' );
		$this->assertSame( 'Site name: Example Blog', $text );
	}

	/**
	 * A tag between every character of a sensitive name, or a quoted split name, is still redacted.
	 */
	public function test_name_split_into_many_fragments_is_still_redacted(): void {
		$bodies = array(
			's888' => '<p>pa<b>s</b>s<b>w</b>ord=s888</p>',
			's999' => '{"pa<b>ss</b>word": "s999"}',
			's000' => "'to<b>k</b>en'='s000'",
			's123' => '{"pa<b>ss</b>wo<b>rd</b>": "s123"}',
			's456' => '{"pa<b>ss</b>word" : "s456"}',
		);
		foreach ( $bodies as $secret => $body ) {
			$this->assertStringNotContainsString( $secret, \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body ) );
		}

		$names = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::SENSITIVE_PARAM_NAMES;
		foreach ( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::SENSITIVE_PARAM_SUFFIXES as $suffix ) {
			$names[] = 'x_' . $suffix;
		}
		foreach ( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::SENSITIVE_PARAM_BARE_SUFFIXES as $suffix ) {
			$names[] = 'db' . $suffix;
		}
		foreach ( $names as $name ) {
			$split = implode( '<span></span>', str_split( $name ) );
			$text  = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<p>Login ' . $split . '=q7q7</p>' );
			$this->assertStringNotContainsString( 'q7q7', $text, $name );
		}
	}

	/**
	 * Rejoining split names stays linear on large bodies and still redacts at the end.
	 */
	public function test_rejoin_is_fast_on_large_bodies(): void {
		$words = str_repeat( 'Lorem ipsum dolor sit amet ', 8000 );

		$start = microtime( true );
		\MainWP\Dashboard\MainWP_Connect_Failure_Ux::redact_secrets( $words );
		$this->assertLessThan( 2.0, microtime( true ) - $start );

		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::redact_secrets( $words . 'pass word=s666' );
		$this->assertStringNotContainsString( 's666', $text );

		$start = microtime( true );
		\MainWP\Dashboard\MainWP_Connect_Failure_Ux::redact_secrets( str_repeat( 'a b c d e f g h i j k l m n o ": ', 3000 ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	/**
	 * key and code stay underscore-only so ordinary words remain visible.
	 */
	public function test_ordinary_words_ending_in_key_or_code_stay_visible(): void {
		$this->assertFalse( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'monkey' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'keycode' ) );

		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'monkey=banana keycode=visible page=sites' );

		$this->assertStringContainsString( 'monkey=banana', $text );
		$this->assertStringContainsString( 'keycode=visible', $text );
		$this->assertStringContainsString( 'page=sites', $text );
	}

	/**
	 * Failure HTML labels IPs and always shows outbound IP + User-Agent.
	 */
	public function test_failure_html_labels_edge_vs_dashboard_ip_and_user_agent(): void {
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '203.0.113.10',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$_SERVER['SERVER_ADDR'] = '10.0.0.1';

		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 403,
				'headers'     => "HTTP/1.1 403 Forbidden\r\ncf-ray: abc\r\n",
				'body'        => 'Attention Required! Cloudflare',
				'resolved_ip' => '104.21.12.34',
			)
		);

		$html = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_text( $details );

		$this->assertTrue( $details['waf_likely'] );
		$this->assertSame( '203.0.113.10', $details['dashboard_outbound_ip'] );
		$this->assertStringContainsString( 'Mozilla/5.0 (compatible; MainWP/', $details['user_agent'] );
		$this->assertStringContainsString( 'Cloudflare / WAF / User-Agent allow', $html );
		$this->assertStringContainsString( 'Dashboard outbound IP (allowlist this)', $html );
		$this->assertStringContainsString( '203.0.113.10', $html );
		$this->assertStringContainsString( 'User-Agent MainWP sends', $html );
		$this->assertStringContainsString( 'Cloudflare/proxy edge IP (do not allowlist this as the Dashboard IP)', $html );
		$this->assertStringContainsString( '104.21.12.34', $html );
		$this->assertStringContainsString( 'Child / HTTP response', $html );
		$this->assertStringContainsString( 'Reinstall MainWP Child only if those steps do not resolve it', $html );
		$this->assertLessThan(
			strpos( $html, 'Reinstall MainWP Child only if those steps do not resolve it' ),
			strpos( $html, 'Cloudflare / WAF / User-Agent allow' )
		);
		$this->assertStringContainsString( 'Cloudflare / WAF / User-Agent allow', $text );
		$this->assertStringNotContainsString( 'IP: 104.21.12.34', $html );
	}

	/**
	 * Non-WAF failures still expose outbound IP, User-Agent, and a readable response.
	 */
	public function test_non_waf_failure_still_shows_outbound_ip_and_response(): void {
		set_transient(
			'mainwp_public_dashboard_ip_data',
			array(
				'value'           => '198.51.100.7',
				'status'          => 'pass',
				'community_value' => 'Detected',
			),
			HOUR_IN_SECONDS
		);
		$_SERVER['SERVER_ADDR'] = '10.0.0.1';

		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 500,
				'headers'     => "HTTP/1.1 500 Internal Server Error\r\ncontent-type: text/html\r\n",
				'body'        => '<p>Internal error</p>',
			)
		);
		$html = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );

		$this->assertFalse( $details['waf_likely'] );
		$this->assertStringContainsString( '198.51.100.7', $html );
		$this->assertStringContainsString( 'User-Agent MainWP sends', $html );
		$this->assertStringContainsString( 'Status: 500', $html );
		$this->assertStringContainsString( 'Internal error', $html );
		$this->assertStringNotContainsString( 'Cloudflare / WAF / User-Agent allow', $html );
	}

	/**
	 * Error helper leads with CF/WAF/UA on 403 instead of generic Child missing.
	 */
	public function test_error_helper_403_leads_with_waf_guidance(): void {
		$exception = new \MainWP\Dashboard\MainWP_Exception( 'NOMAINWP', 'https://example.com/' );
		$exception->set_data(
			array(
				'text'        => 'Status: 403 (Forbidden)',
				'http_status' => 403,
				'headers'     => 'server: cloudflare',
				'body'        => 'Attention Required! Cloudflare',
			)
		);
		$html = \MainWP\Dashboard\MainWP_Error_Helper::get_connect_failure_message( $exception );

		$this->assertStringContainsString( 'Cloudflare / WAF / User-Agent allow', $html );
		$this->assertStringNotContainsString( 'MainWP Child plugin not detected or could not be reached', $html );
	}

	/**
	 * Location diagnostics keep scheme, host, and path; query and fragment are dropped.
	 */
	public function test_location_header_redacts_sensitive_query_params(): void {
		$headers = "HTTP/1.1 302 Found\r\nLocation: https://sso.example.test/callback?token=qa-secret&code=qa-code&page=sites&foo=bar\r\nserver: cloudflare\r\n";
		$summary = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );

		$this->assertStringContainsString( 'location: https://sso.example.test/callback', $summary );
		$this->assertStringNotContainsString( '?', $summary );
		$this->assertStringNotContainsString( 'page=sites', $summary );
		$this->assertStringNotContainsString( 'foo=bar', $summary );
		$this->assertStringNotContainsString( 'token=', $summary );
		$this->assertStringNotContainsString( 'qa-secret', $summary );
		$this->assertStringNotContainsString( 'qa-code', $summary );
	}

	/**
	 * Location drops mixed-case, URL-encoded, unknown, and fragment-only secrets.
	 */
	public function test_location_header_redacts_mixed_case_and_urlencoded_values(): void {
		$headers = "HTTP/1.1 302 Found\r\nLocation: https://idp.example.test/cb?TOKEN=qa-secret&Key=qa-code&Nonce=qa-nonce&State=qa-state&Signature=qa-sig&rp_key=qa-reset\r\n";
		$summary = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );

		$this->assertSame( 'https://idp.example.test/cb', \MainWP\Dashboard\MainWP_Connect_Failure_Ux::redact_url( 'https://idp.example.test/cb?TOKEN=qa-secret&Key=qa-code' ) );
		$this->assertStringContainsString( 'location: https://idp.example.test/cb', $summary );
		$this->assertStringNotContainsString( 'TOKEN=', $summary );
		$this->assertStringNotContainsString( 'qa-secret', $summary );
		$this->assertStringNotContainsString( 'qa-code', $summary );
		$this->assertStringNotContainsString( 'qa-nonce', $summary );
		$this->assertStringNotContainsString( 'qa-state', $summary );
		$this->assertStringNotContainsString( 'qa-sig', $summary );
		$this->assertStringNotContainsString( 'qa-reset', $summary );

		$encoded = "Location: https://idp.example.test/cb?t%6Fken=qa%2Dsecret&code=qa%2Dcode\r\n";
		$enc_sum = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $encoded );
		$this->assertStringContainsString( 'location: https://idp.example.test/cb', $enc_sum );
		$this->assertStringNotContainsString( 't%6Fken', $enc_sum );
		$this->assertStringNotContainsString( 'qa-secret', $enc_sum );
		$this->assertStringNotContainsString( 'qa%2Dsecret', $enc_sum );
		$this->assertStringNotContainsString( 'qa-code', $enc_sum );
		$this->assertStringNotContainsString( 'qa%2Dcode', $enc_sum );
	}

	/**
	 * Unknown Location params and fragment-only secrets are stripped, not denylisted.
	 */
	public function test_location_strips_unknown_query_and_fragment_secrets(): void {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;

		$this->assertSame( 'https://sso.example.test/authorize', $ux::redact_url( 'https://sso.example.test/authorize?ticket=secret#token' ) );
		$this->assertSame( 'https://idp.example.test/saml', $ux::redact_url( 'https://idp.example.test/saml?SAMLResponse=qa-saml-blob' ) );
		$this->assertSame( 'https://idp.example.test/cb', $ux::redact_url( 'https://idp.example.test/cb#SAMLResponse=qa-fragment' ) );
		$this->assertSame( 'https://sso.example.test:8443/authorize', $ux::redact_url( 'https://user:pass@sso.example.test:8443/authorize?ticket=secret' ) );
		$this->assertSame( '/authorize', $ux::redact_url( '/authorize?ticket=secret#token' ) );
		$this->assertSame( '', $ux::redact_url( 'https://user:password@example.test:99999/path' ) );

		$headers = "HTTP/1.1 302 Found\r\nLocation: https://sso.example.test/authorize?ticket=secret#token\r\n";
		$details = $ux::build_details(
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => '',
			)
		);
		$html     = $ux::render_html( $details );
		$text     = $ux::format_text( $details );
		$enriched = $ux::enrich_output(
			array(),
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => '',
			)
		);

		foreach ( array( $details['response']['text'], $html, $text, $enriched['response_readable'], $enriched['failure_ux'], $enriched['failure_ux_text'], $enriched['response_headers'] ) as $surface ) {
			$this->assertStringContainsString( 'https://sso.example.test/authorize', $surface );
			$this->assertStringNotContainsString( 'ticket=', $surface );
			$this->assertStringNotContainsString( 'secret', $surface );
			$this->assertStringNotContainsString( '#token', $surface );
			$this->assertStringNotContainsString( '?', $surface );
		}

		$fragment_headers = "HTTP/1.1 302 Found\r\nLocation: https://idp.example.test/cb#SAMLResponse=qa-fragment\r\n";
		$fragment_snip    = $ux::summarize_headers( $fragment_headers );
		$this->assertStringContainsString( 'location: https://idp.example.test/cb', $fragment_snip );
		$this->assertStringNotContainsString( 'SAMLResponse', $fragment_snip );
		$this->assertStringNotContainsString( 'qa-fragment', $fragment_snip );
		$this->assertStringNotContainsString( '#', $fragment_snip );

		$unparsed = $ux::summarize_headers( "HTTP/1.1 302 Found\r\nLocation: https://user:password@example.test:99999/path\r\n" );
		$this->assertStringNotContainsString( 'password', $unparsed );
		$this->assertStringNotContainsString( 'user:', $unparsed );
	}

	/**
	 * Hidden body still keeps Location host/path, with query/fragment removed.
	 */
	public function test_hidden_body_still_redacts_location_in_failure_surfaces(): void {
		$headers = "HTTP/1.1 302 Found\r\nLocation: https://login.example.test/wp-login.php?action=rp&key=qa-secret&rp_key=qa-code&redirect_to=/wp-admin/\r\n";
		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => '',
			)
		);
		$html     = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );
		$text     = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_text( $details );
		$enriched = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::enrich_output(
			array(),
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => '',
			)
		);

		foreach ( array( $details['response']['text'], $html, $text, $enriched['response_readable'], $enriched['failure_ux'], $enriched['failure_ux_text'], $enriched['response_headers'] ) as $surface ) {
			$this->assertStringContainsString( 'https://login.example.test/wp-login.php', $surface );
			$this->assertStringNotContainsString( 'action=rp', $surface );
			$this->assertStringNotContainsString( 'redirect_to=', $surface );
			$this->assertStringNotContainsString( 'qa-secret', $surface );
			$this->assertStringNotContainsString( 'qa-code', $surface );
		}

		$rest_sync_errors = $enriched['failure_ux'];
		$this->assertStringNotContainsString( 'qa-secret', $rest_sync_errors );
		$this->assertStringNotContainsString( 'qa-code', $rest_sync_errors );
	}

	/**
	 * Suffix-named secrets match is_sensitive_param() on body, Location, and stored UX.
	 */
	public function test_suffix_param_names_are_redacted_like_is_sensitive_param(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'csrf_token' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'site_api_key' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'user_password' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'page' ) );

		$body = 'Welcome csrf_token=qa-csrf-secret site_api_key=qa-api-key plugin_password=qa-plugin-secret page=sites';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
		$this->assertStringContainsString( 'csrf_token=[redacted]', $text );
		$this->assertStringContainsString( 'site_api_key=[redacted]', $text );
		$this->assertStringContainsString( 'plugin_password=[redacted]', $text );
		$this->assertStringContainsString( 'page=sites', $text );
		$this->assertStringNotContainsString( 'qa-csrf-secret', $text );
		$this->assertStringNotContainsString( 'qa-api-key', $text );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $text );

		$json = '{"csrf_token":"qa-csrf-secret","plugin_password":"qa-plugin-secret","page":"sites"}';
		$json_text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $json );
		$this->assertStringContainsString( '"csrf_token"=[redacted]', $json_text );
		$this->assertStringContainsString( '"plugin_password"=[redacted]', $json_text );
		$this->assertStringContainsString( '"page":"sites"', $json_text );
		$this->assertStringNotContainsString( 'qa-csrf-secret', $json_text );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $json_text );

		$headers = "HTTP/1.1 302 Found\r\nLocation: https://sso.example.test/cb?csrf_token=qa-csrf-secret&plugin_password=qa-plugin-secret&page=sites\r\nwww-authenticate: Bearer plugin_password=qa-plugin-secret\r\n";
		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => $body,
			)
		);
		$html     = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );
		$text_ux  = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_text( $details );
		$enriched = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::enrich_output(
			array(),
			array(
				'http_status' => 302,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		foreach ( array( $details['response']['text'], $html, $text_ux, $enriched['response_readable'], $enriched['failure_ux'], $enriched['failure_ux_text'] ) as $surface ) {
			$this->assertStringContainsString( 'page=sites', $surface );
			$this->assertStringNotContainsString( 'qa-csrf-secret', $surface );
			$this->assertStringNotContainsString( 'qa-api-key', $surface );
			$this->assertStringNotContainsString( 'qa-plugin-secret', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate:', $header_snip );
		$this->assertStringContainsString( 'plugin_password=[redacted]', $header_snip );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $header_snip );
	}

	/**
	 * Comma- and space-containing secrets stay fully redacted in body and headers.
	 */
	public function test_delimiter_containing_secrets_are_fully_redacted(): void {
		$body = 'Welcome plugin_password=qa,secret csrf_token=qa space-token page=sites';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
		$this->assertStringContainsString( 'plugin_password=[redacted]', $text );
		$this->assertStringContainsString( 'csrf_token=[redacted]', $text );
		$this->assertStringContainsString( 'page=sites', $text );
		$this->assertStringNotContainsString( 'qa,secret', $text );
		$this->assertStringNotContainsString( ',secret', $text );
		$this->assertStringNotContainsString( 'qa space-token', $text );

		$json = '{"plugin_password":"qa, secret","csrf_token":"qa space-token","page":"sites"}';
		$json_text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $json );
		$this->assertStringContainsString( '"plugin_password"=[redacted]', $json_text );
		$this->assertStringContainsString( '"csrf_token"=[redacted]', $json_text );
		$this->assertStringContainsString( '"page":"sites"', $json_text );
		$this->assertStringNotContainsString( 'qa, secret', $json_text );
		$this->assertStringNotContainsString( 'qa space-token', $json_text );

		$headers = "HTTP/1.1 401 Unauthorized\r\nwww-authenticate: Bearer plugin_password=\"qa,secret\"\r\nLocation: https://sso.example.test/cb?plugin_password=qa,secret&page=sites\r\n";
		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status' => 401,
				'headers'     => $headers,
				'body'        => $body,
			)
		);
		$html     = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html( $details );
		$text_ux  = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_text( $details );
		$enriched = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::enrich_output(
			array(),
			array(
				'http_status' => 401,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		foreach ( array( $details['response']['text'], $html, $text_ux, $enriched['response_readable'], $enriched['failure_ux'], $enriched['failure_ux_text'] ) as $surface ) {
			$this->assertStringContainsString( 'page=sites', $surface );
			$this->assertStringNotContainsString( 'qa,secret', $surface );
			$this->assertStringNotContainsString( ',secret', $surface );
			$this->assertStringNotContainsString( 'qa space-token', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate:', $header_snip );
		$this->assertStringContainsString( 'plugin_password=[redacted]', $header_snip );
		$this->assertStringNotContainsString( 'qa,secret', $header_snip );
		$this->assertStringNotContainsString( ',secret', $header_snip );
	}

	/**
	 * Failed DNS hostnames are not shown as the Cloudflare/proxy edge IP.
	 */
	public function test_unresolved_hostname_is_not_labeled_as_edge_ip(): void {
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_ip_list( 'child.example.test' ) );
		$this->assertSame( '104.21.12.34', \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_ip_list( '104.21.12.34' ) );
		$this->assertSame( '104.21.12.34', \MainWP\Dashboard\MainWP_Connect_Failure_Ux::format_ip_list( array( 'child.example.test', '104.21.12.34' ) ) );

		$html = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html(
			\MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
				array(
					'http_status' => 403,
					'resolved_ip' => 'child.example.test',
				)
			)
		);
		$this->assertStringNotContainsString( 'child.example.test', $html );
		$this->assertStringNotContainsString( 'Cloudflare/proxy edge IP (do not allowlist this as the Dashboard IP)', $html );
	}

	/**
	 * Empty outbound IP uses the failure-UX fallback, not Status warning copy.
	 */
	public function test_empty_outbound_ip_uses_unavailable_copy(): void {
		$html = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::render_html(
			\MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
				array(
					'http_status'      => 403,
					'skip_outbound_ip' => true,
				)
			)
		);
		$this->assertStringContainsString( 'Unavailable. Check Info → Server for Public Dashboard IP, or ask your host.', $html );
		$this->assertStringNotContainsString( 'Unavailable. Contact your host if the Server IP is local or private.', $html );
	}

	/**
	 * Successful Test Connection context can skip the outbound-IP lookup.
	 */
	public function test_skip_outbound_ip_avoids_lookup_on_success_context(): void {
		$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
			array(
				'http_status'      => 200,
				'skip_outbound_ip' => true,
			)
		);
		$this->assertSame( '', $details['dashboard_outbound_ip'] );
	}

	/**
	 * JavaScript translations include the new failure-UX strings.
	 */
	public function test_js_translations_include_failure_ux_labels(): void {
		$translations = \MainWP\Dashboard\MainWP_System_View::get_mainwp_translations();
		$this->assertContains( 'Cloudflare/proxy edge IP', $translations );
		$this->assertContains(
			'No child response body was returned. HTTP status, headers, Dashboard outbound IP, and User-Agent are listed above.',
			$translations
		);
	}
}
