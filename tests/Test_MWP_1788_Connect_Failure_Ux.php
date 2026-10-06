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
	 * A body with credentials shows the withheld-excerpt placeholder.
	 */
	public function test_body_excerpt_is_safe(): void {
		$body = '<html><title>Attention Required</title><p>password=supersecret uniqueId=abc123</p></html>';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );

		$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $text );
		$this->assertStringNotContainsString( 'supersecret', $text );
		$this->assertStringNotContainsString( '<title>', $text );
	}

	/**
	 * Excerpts hide credentials through markup and remain readable without sensitive names.
	 *
	 * @dataProvider data_body_excerpt_matrix
	 *
	 * @param string      $body      Raw response body.
	 * @param array       $forbidden Secret values or fragments that must be absent.
	 * @param string|null $expected  Exact excerpt for a readable or deliberately withheld body.
	 */
	public function test_body_excerpt_matrix( string $body, array $forbidden = array( 'supersecret', 'super secret' ), ?string $expected = null ): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );

		foreach ( $forbidden as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
		$this->assertNotSame( '', $text, 'Withheld excerpts need an explanation.' );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		$this->assertLessThanOrEqual( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::BODY_LIMIT + 3, mb_strlen( $text, 'UTF-8' ) );
		if ( null !== $expected ) {
			$this->assertSame( $expected, $text );
		}
	}

	/**
	 * Original markup cases, round-three triggers, and readable error bodies.
	 *
	 * @return array<string,array>
	 */
	public function data_body_excerpt_matrix(): array {
		$hidden = 'Placeholder: the response body was hidden because it may contain credentials.';

		return array(
			'adjacent title and paragraph'     => array( '<html><title>Attention Required</title><p>password=supersecret</p></html>' ),
			'adjacent divs'                    => array( '<div>Access Required</div><div>password=supersecret</div>' ),
			'adjacent obsolete center'         => array( '<center>Access Required</center><center>password=supersecret</center>' ),
			'details and summary'              => array( '<details><summary>Access Required</summary>password=supersecret</details>' ),
			'adjacent inline bold'             => array( '<b>Required</b>password=supersecret' ),
			'adjacent spans'                   => array( '<span>Required</span><span>password=supersecret</span>' ),
			'adjacent custom element'          => array( '<x-note>Required</x-note>password=supersecret' ),
			'strong inside the name'           => array( '<p>pass<strong>word</strong>=supersecret</p>' ),
			'label inside the name'            => array( '<p>pass<label>word</label>=supersecret</p>' ),
			'script inside the name'           => array( '<p>pass<script>void(0)</script>word=supersecret</p>' ),
			'comment inside the name'          => array( '<p>pass<!-- x -->word=supersecret</p>' ),
			'markup inside name and value'     => array( '<p>pass<b>word</b>=super<b>secret</b></p>' ),
			'quoted value, name split'         => array( '<p>pass<i>word</i>: "supersecret"</p>' ),
			'suffix name split by markup'      => array( '<p>api<em>_key</em>=supersecret</p>' ),
			'uppercase tags and name'          => array( '<DIV>Access Required</DIV><DIV>PASSWORD=supersecret</DIV>' ),
			'line break before the name'       => array( 'Access Required<br>password=supersecret' ),
			'split name, glued to prior block' => array( '<p>Access Required</p><p>pass<b>word</b>=supersecret</p>' ),
			'split name, then another pair'    => array( '<p>pass<b>word</b>=supersecret</p><p>uniqueId=abc</p>' ),
			'short value beside line number'   => array( '<p>Error at line 12</p><p>co<b>de</b>=1</p>', array( '=1' ) ),
			'multibyte truncation'             => array( 'a' . str_repeat( 'é', 1300 ), array(), 'a' . str_repeat( 'é', 1199 ) . '...' ),
			'S57-R3-1 swallowed split name'    => array( '<p>user=bob</p>pass<b>word</b>=supersecret' ),
			'S57-R3-2 overlapping values'      => array( '<p>to<b>ken</b>="super"</p><p>pass<b>word</b>="super-secret"</p>', array( 'super', '-secret' ) ),
			'S57-R3-3 split entity'            => array( 'pass<b>word</b>="supersecret&#3<b>3</b>;"' ),
			'S57-R3-4 quoted JIT exhaustion'   => array( '{"token":"' . str_repeat( 's', 9000 ) . '"}', array( str_repeat( 's', 64 ) ) ),
			'S57-R3-5 5K value pattern'        => array( '<p>to<b>ken</b>="' . str_repeat( 's', 5000 ) . '"</p>', array( str_repeat( 's', 64 ) ) ),
			'S57-R3-5 11K value pattern'       => array( '<p>to<b>ken</b>="' . str_repeat( 's', 11000 ) . '"</p>', array( str_repeat( 's', 64 ) ) ),
			'S57-R3-5 long WAF error code'     => array( '<html><h1>Cloudflare: Access denied</h1><p>Error code: 1020</p><p>' . str_repeat( 'Request blocked. ', 950 ) . '</p></html>', array( '1020' ), $hidden ),
			'S57-R3-6 malformed tag and name'  => array( '<p>token=password</p>pass<<b>word</b>=supersecret', array( 'supersecret', 'super secret' ) ),
			'malformed split name alone'       => array( 'pass<<b>word</b>=supersecret', array( 'supersecret' ), $hidden ),
			'malformed name after safe block'  => array( '<p>Access denied</p>pass<<b>word</b>=supersecret', array( 'supersecret' ), $hidden ),
			'malformed tag before delimiter'   => array( 'password<<b>=</b>secret', array( 'secret' ), $hidden ),
			'print_r password entry'           => array( '<pre>Array ( [user] => bob [password] => supersecret )</pre>', array( 'supersecret' ), $hidden ),
			'var_dump password entry'          => array( '["password"]=> string(11) "supersecret"', array( 'supersecret' ), $hidden ),
			'var_export password entry'        => array( "array ( 'password' => 'supersecret', )", array( 'supersecret' ), $hidden ),
			'HTML5 colon entity'               => array( 'password&colon; secret', array( 'secret' ), $hidden ),
			'Cloudflare block page'            => array( '<!DOCTYPE html><html><head><title>Attention Required! | Cloudflare</title><style>body { color: #333; }</style></head><body><h1>Sorry, you have been blocked</h1><p>You are unable to access example.com</p><p>Cloudflare Ray ID: 8ab1234567890123</p><p>Your IP: 203.0.113.10</p><script>void(0)</script></body></html>', array(), 'Attention Required! | Cloudflare Sorry, you have been blocked You are unable to access example.com Cloudflare Ray ID: 8ab1234567890123 Your IP: 203.0.113.10' ),
			'PHP warning and fatal page'       => array( '<br /><b>Warning</b>: Undefined array index "site" in <b>/var/www/html/wp-content/plugins/example/plugin.php</b> on line <b>42</b><br /><b>Fatal error</b>: Uncaught TypeError: count(): Argument #1 must be of type Countable|array, null given in /var/www/html/wp-content/plugins/example/plugin.php:43<br />Stack trace:<br />#0 {main}<br />thrown on line <b>43</b>', array(), 'Warning : Undefined array index "site" in /var/www/html/wp-content/plugins/example/plugin.php on line 42 Fatal error : Uncaught TypeError: count(): Argument #1 must be of type Countable|array, null given in /var/www/html/wp-content/plugins/example/plugin.php:43 Stack trace: #0 {main} thrown on line 43' ),
			'wp_die page'                      => array( '<!DOCTYPE html><html><head><title>WordPress &rsaquo; Error</title><style>body { background: #fff; }</style></head><body id="error-page"><div class="wp-die-message"><p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/documentation/article/faq-troubleshooting/">Learn more about troubleshooting WordPress.</a></p></div></body></html>', array(), 'WordPress › Error There has been a critical error on this website. Learn more about troubleshooting WordPress.' ),
			'JSON error without secrets'       => array( '{"error":"service_unavailable","message":"Please try again later.","status":503}', array(), '{"error":"service_unavailable","message":"Please try again later.","status":503}' ),
			'JSON error with code label'       => array( '{"code":"service_unavailable","message":"Please try again later.","status":503}', array( 'service_unavailable' ), $hidden ),
			'sensitive name inside a word'     => array( '12RequiredPASSWORD = supersecret' ),
			'sensitive pair in prior value'    => array( 'user=bobpassword=supersecret' ),
			'quoted suffix inside prior value' => array( 'message="user=bob api-key: supersecret"' ),
			'sensitive name past body limit'   => array( str_repeat( 'a', 1300 ) . ' token=supersecret', array( 'supersecret' ), $hidden ),
			'regex metacharacters in value'    => array( 'pass<b>word</b>="supersecret/.*[a-z]+$"' ),
			'URL query without sensitive name' => array( 'See https://example.test/help?ticket=private#fragment', array( 'private', 'fragment', '?' ), 'See https://example.test/help' ),
			'name without a pair delimiter'    => array( '<p>Your password could not be verified.</p>', array(), 'Your password could not be verified.' ),
			'invalid UTF-8 fails closed'       => array( "Failure \xff details", array( 'Failure', 'details' ), $hidden ),
		);
	}

	/**
	 * PCRE limits and invalid UTF-8 never return unredacted diagnostic text.
	 */
	public function test_regex_failures_withhold_diagnostic_text(): void {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;
		$this->assertSame( '', $ux::redact_secrets( "Failure \xff details" ) );
		$this->assertSame( PREG_BAD_UTF8_ERROR, preg_last_error() );

		$limit = ini_get( 'pcre.backtrack_limit' );
		$jit   = ini_get( 'pcre.jit' );
		// phpcs:disable WordPress.PHP.IniSet.Risky -- Force regex failures; restore both settings in finally.
		try {
			ini_set( 'pcre.jit', '0' );
			ini_set( 'pcre.backtrack_limit', '1' );
			$this->assertSame( '', $ux::redact_secrets( 'passwordx=public response text' ) );
			$this->assertSame( PREG_BACKTRACK_LIMIT_ERROR, preg_last_error() );
			$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $ux::summarize_body( '<script>var value = 1;</script><p>Ordinary response text</p>' ) );
			$this->assertSame( PREG_BACKTRACK_LIMIT_ERROR, preg_last_error() );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
			ini_set( 'pcre.jit', $jit );
		}
		// phpcs:enable WordPress.PHP.IniSet.Risky
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
	 * Suffix-named credentials are withheld in bodies, headers, and stored UX.
	 */
	public function test_suffix_param_names_are_redacted_like_is_sensitive_param(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'csrf_token' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'site_api_key' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'user_password' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'page' ) );

		$body = 'Welcome csrf_token=qa-csrf-secret site_api_key=qa-api-key plugin_password=qa-plugin-secret page=sites';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
		$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $text );
		$this->assertStringNotContainsString( 'qa-csrf-secret', $text );
		$this->assertStringNotContainsString( 'qa-api-key', $text );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $text );

		$json = '{"csrf_token":"qa-csrf-secret","plugin_password":"qa-plugin-secret","page":"sites"}';
		$json_text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $json );
		$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $json_text );
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
			$this->assertStringContainsString( 'Placeholder: the response body was hidden because it may contain credentials.', $surface );
			$this->assertStringNotContainsString( 'qa-csrf-secret', $surface );
			$this->assertStringNotContainsString( 'qa-api-key', $surface );
			$this->assertStringNotContainsString( 'qa-plugin-secret', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate:', $header_snip );
		$this->assertStringNotContainsString( 'plugin_password=', $header_snip );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $header_snip );
	}

	/**
	 * Comma- and space-containing credentials are withheld in bodies and headers.
	 */
	public function test_delimiter_containing_secrets_are_fully_redacted(): void {
		$body = 'Welcome plugin_password=qa,secret csrf_token=qa space-token page=sites';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
		$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $text );
		$this->assertStringNotContainsString( 'qa,secret', $text );
		$this->assertStringNotContainsString( ',secret', $text );
		$this->assertStringNotContainsString( 'qa space-token', $text );

		$json = '{"plugin_password":"qa, secret","csrf_token":"qa space-token","page":"sites"}';
		$json_text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $json );
		$this->assertSame( 'Placeholder: the response body was hidden because it may contain credentials.', $json_text );
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
			$this->assertStringContainsString( 'Placeholder: the response body was hidden because it may contain credentials.', $surface );
			$this->assertStringNotContainsString( 'qa,secret', $surface );
			$this->assertStringNotContainsString( ',secret', $surface );
			$this->assertStringNotContainsString( 'qa space-token', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate:', $header_snip );
		$this->assertStringNotContainsString( 'plugin_password=', $header_snip );
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
