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
	 * Readout line that replaces the response body.
	 */
	const BODY_NOT_SHOWN = 'The response body is not shown because it can contain private data from the child site.';

	/**
	 * Assert that a body naming a secret is suppressed as a unit.
	 *
	 * @param string $text    Summarized body.
	 * @param string $message Optional assertion message.
	 */
	private function assert_sensitive_body_hidden( $text, $message = '' ): void {
		$this->assertSame( '[response content hidden: it named a sensitive field]', $text, $message );
	}

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
	 * Response body text never reaches the readout, even when redaction would miss it.
	 */
	public function test_response_body_text_is_never_shown_in_readout(): void {
		$bodies = array(
			'fullwidth colon'  => "password\u{FF1A}supersecret",
			'fullwidth equals' => "token\u{FF1D}supersecret",
			'table cells'      => '<td>mainwpsignature</td><td>supersecret</td>',
			'definition list'  => '<dt>password</dt><dd>supersecret</dd>',
			'markdown bold'    => '**password**: supersecret',
			'dot leader'       => 'password.....: supersecret',
			'hangul filler'    => "pass\u{3164}word=supersecret",
			'entity userinfo'  => 'https://user:<b>supersecret</b>&#64;example.test/',
		);
		foreach ( $bodies as $label => $body ) {
			$summary = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_response( 403, "HTTP/1.1 403 Forbidden\r\nserver: nginx\r\n", $body );
			$details = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::build_details(
				array(
					'http_status'      => 403,
					'headers'          => "HTTP/1.1 403 Forbidden\r\nserver: nginx\r\n",
					'body'             => $body,
					'skip_outbound_ip' => true,
				)
			);

			foreach ( array( $summary, $details['response'] ) as $response ) {
				$this->assertSame( '', $response['body'], $label );
				$this->assertStringNotContainsString( 'supersecret', $response['text'], $label );
				$this->assertStringContainsString( self::BODY_NOT_SHOWN, $response['text'], $label );
				$this->assertStringContainsString( 'Status: 403', $response['text'], $label );
				$this->assertStringContainsString( 'server: nginx', $response['text'], $label );
			}
			$this->assertStringNotContainsString( 'supersecret', (string) wp_json_encode( $details ), $label );
		}
	}

	/**
	 * A body excerpt that names secrets is suppressed as a unit.
	 */
	public function test_body_excerpt_is_safe(): void {
		$body = '<html><title>Attention Required</title><p>password=supersecret uniqueId=abc123</p></html>';
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );

		$this->assert_sensitive_body_hidden( $text );
		$this->assertStringNotContainsString( 'supersecret', $text );
		$this->assertStringNotContainsString( '<title>', $text );
	}

	/**
	 * A received body is reported as hidden, so it is not mistaken for an empty response.
	 */
	public function test_received_body_is_reported_as_hidden(): void {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;

		$received = $ux::summarize_response( 403, '', '<html>Attention Required! Cloudflare</html>' );
		$this->assertSame( '', $received['body'] );
		$this->assertStringContainsString( 'Body: received but not shown, because it may contain sensitive data.', $received['text'] );
		$this->assertStringNotContainsString( 'no readable child response', $received['text'] );
		$this->assertStringNotContainsString( 'Attention Required', $received['text'] );

		$empty = $ux::summarize_response( 403, '', '' );
		$this->assertStringContainsString( 'Body: no readable child response was returned.', $empty['text'] );
		$this->assertStringNotContainsString( 'received but not shown', $empty['text'] );
	}

	/**
	 * Child bodies never become diagnostics, regardless of their content or size.
	 *
	 * @dataProvider child_response_bodies
	 * @param string $body Raw child response.
	 * @param array  $fragments Body-only text that must not be displayed.
	 */
	public function test_body_is_omitted_from_all_failure_details( $body, $fragments ): array {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;
		set_transient( 'mainwp_public_dashboard_ip_data', array( 'value' => '203.0.113.10', 'status' => 'pass', 'community_value' => 'Detected' ), HOUR_IN_SECONDS );
		$start = microtime( true );
		$context = array( 'http_status' => 500, 'headers' => "HTTP/1.1 500 Internal Server Error\r\nserver: nginx\r\n", 'body' => $body, 'skip_outbound_ip' => true );
		$details = array( $ux::build_details( $context ) );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'Initial detail construction must finish in under one second.' );
		$this->assertSame( '', $details[0]['response']['body'] );
		foreach ( array( array( 'http_status' => 500, 'body' => $body ), array( 'http_status' => 500, 'text' => $body ), $body ) as $data ) {
			$exception = new \MainWP\Dashboard\MainWP_Exception( 'Remote failure' );
			$exception->set_data( $data );
			$details[] = $ux::from_exception( $exception );
		}
		$surfaces = array();
		foreach ( array( 'response_body', 'fetch_data', 'response_readable' ) as $field ) {
			$output = array( 'http_status' => 500, $field => $body );
			$details[] = $ux::from_exception( null, $output );
			$surfaces[] = $ux::readable_response_from_output( $output );
			$surfaces[] = \MainWP\Dashboard\MainWP_Error_Helper::get_connect_failure_message( null, $output );
		}
		$enriched = $ux::enrich_output( array(), $context );
		$raw      = array(
			'http_status'      => 403,
			'response_headers' => $headers,
			'response_body'    => $body,
		);

		return array(
			'response_body'     => $enriched['response_body'],
			'response_readable' => $enriched['response_readable'],
			'failure_ux'        => $enriched['failure_ux'],
			'failure_ux_text'   => $enriched['failure_ux_text'],
			'readable_raw'      => $ux::readable_response_from_output( $raw ),
			'error_helper'      => \MainWP\Dashboard\MainWP_Error_Helper::get_connect_failure_message( null, $raw ),
		);
	}

	/**
	 * Every surface drops the body and says so.
	 *
	 * @param array<string,string> $surfaces Surfaces from failure_surfaces().
	 * @param string               $label    Assertion label.
	 */
	private function assert_body_not_shown_on_surfaces( array $surfaces, $label ): void {
		$this->assertSame( '', $surfaces['response_body'], $label );
		foreach ( array( 'response_readable', 'failure_ux', 'failure_ux_text', 'readable_raw', 'error_helper' ) as $surface_name ) {
			$this->assertStringContainsString( self::BODY_NOT_SHOWN, $surfaces[ $surface_name ], $label . ' / ' . $surface_name );
		}
	}

	/**
	 * A reflected register request (get_post_data_not_authed) never shows userpwd or uniqueId.
	 */
	public function test_reflected_register_params_are_redacted_on_every_surface(): void {
		$bodies = array(
			'query'  => 'user=admin&function=register&mainwpver=6.3&pubkey=qa-pubkey-value&server=https://dash.example.test&uniqueId=qa-uniqueid-value&userpwd=qa-userpwd-value&page=sites&monkey=1',
			'spaced' => 'user=admin function=register pubkey=qa-pubkey-value uniqueId=qa-uniqueid-value userpwd=qa-userpwd-value page=sites monkey=1',
			'json'   => '{"user":"admin","function":"register","pubkey":"qa-pubkey-value","uniqueId":"qa-uniqueid-value","userpwd":"qa-userpwd-value","page":"sites","monkey":"1"}',
			'glued'  => '<td>x</td><td>pubkey=qa-pubkey-value</td><td>userpwd=qa-userpwd-value</td><td>uniqueId=qa-uniqueid-value</td><td>page=sites</td><td>monkey=1</td>',
		);

		foreach ( $bodies as $form => $body ) {
			$surfaces = $this->failure_surfaces( $body );
			foreach ( $surfaces as $surface_name => $surface ) {
				$label = $form . ' / ' . $surface_name;
				$this->assertStringNotContainsString( 'qa-userpwd-value', $surface, $label );
				$this->assertStringNotContainsString( 'qa-uniqueid-value', $surface, $label );
				$this->assertStringNotContainsString( 'qa-pubkey-value', $surface, $label );
				$this->assertStringNotContainsString( 'json' === $form ? '"page":"sites"' : 'page=sites', html_entity_decode( $surface, ENT_QUOTES ), $label );
				$this->assertStringNotContainsString( 'json' === $form ? '"monkey":"1"' : 'monkey=1', html_entity_decode( $surface, ENT_QUOTES ), $label );
			}
			$this->assert_body_not_shown_on_surfaces( $surfaces, $form );
		}
	}

	/**
	 * Authed requests send mainwpsignature_adv next to mainwpsignature; both are secrets.
	 */
	public function test_mainwpsignature_adv_is_redacted_on_every_surface(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'mainwpsignature_adv' ) );

		$bodies = array(
			'query'  => 'function=sync&mainwpsignature=qa-legacy-sig&nonce=qa-nonce-value&data_signature=qa-data-sig&mainwpsignature_adv=qa-adv-sig&page=sites&monkey=1',
			'spaced' => 'mainwpsignature=qa-legacy-sig nonce=qa-nonce-value data_signature=qa-data-sig mainwpsignature_adv=qa-adv-sig page=sites monkey=1',
			'json'   => '{"mainwpsignature":"qa-legacy-sig","nonce":"qa-nonce-value","data_signature":"qa-data-sig","mainwpsignature_adv":"qa-adv-sig","page":"sites","monkey":"1"}',
			'glued'  => '<td>x</td><td>mainwpsignature=qa-legacy-sig</td><td>nonce=qa-nonce-value</td><td>data_signature=qa-data-sig</td><td>mainwpsignature_adv=qa-adv-sig</td><td>page=sites</td><td>monkey=1</td>',
		);

		foreach ( $bodies as $form => $body ) {
			$surfaces = $this->failure_surfaces( $body );
			foreach ( $surfaces as $surface_name => $surface ) {
				$label = $form . ' / ' . $surface_name;
				foreach ( array( 'qa-legacy-sig', 'qa-nonce-value', 'qa-data-sig', 'qa-adv-sig' ) as $secret ) {
					$this->assertStringNotContainsString( $secret, $surface, $label );
				}
				$this->assertStringNotContainsString( 'json' === $form ? '"page":"sites"' : 'page=sites', html_entity_decode( $surface, ENT_QUOTES ), $label );
				$this->assertStringNotContainsString( 'json' === $form ? '"monkey":"1"' : 'monkey=1', html_entity_decode( $surface, ENT_QUOTES ), $label );
			}
			$this->assert_body_not_shown_on_surfaces( $surfaces, $form );
		}
	}

	/**
	 * print_r() and var_dump() bodies naming a sensitive key are hidden whole.
	 */
	public function test_print_r_style_dumps_redact_sensitive_keys(): void {
		$dump = "<pre>Array\n(\n    [user] => admin\n    [function] => register\n    [page] => sites\n    [monkey] => 1\n    [userpwd] => qa-userpwd value\n    [password] => qa-password-value\n)\n</pre><p>Error establishing a connection</p>";

		$surfaces = $this->failure_surfaces( $dump );
		foreach ( $surfaces as $surface_name => $surface ) {
			$plain = html_entity_decode( $surface, ENT_QUOTES );
			foreach ( array( 'qa-userpwd', 'qa-password-value' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $plain, $surface_name );
			}
			$this->assertStringNotContainsString( '[user] => admin', $plain, $surface_name );
			$this->assertStringNotContainsString( '[page] => sites', $plain, $surface_name );
			$this->assertStringNotContainsString( '[monkey] => 1', $plain, $surface_name );
			$this->assertStringNotContainsString( 'Error establishing a connection', $plain, $surface_name );
		}
		$this->assert_body_not_shown_on_surfaces( $surfaces, 'dump' );

		foreach ( array( 'userpwd', 'password', 'mainwpsignature_adv' ) as $name ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'Array ( [page] => sites [' . $name . '] => qa-dump-secret )' );
			$this->assertStringNotContainsString( 'qa-dump-secret', $text, $name );
			$this->assert_sensitive_body_hidden( $text, $name );
		}

		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'array(2) { ["page"]=> string(5) "sites" ["userpwd"]=> string(9) "qa-dumped" }' );
		$this->assertStringNotContainsString( 'qa-dumped', $text );
		$this->assert_sensitive_body_hidden( $text );

		// A sensitive name is enough to suppress arbitrary body text, even without =>.
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '[password] reset link sent. [monkey] => banana' );
		$this->assert_sensitive_body_hidden( $text );
	}

	/**
	 * Delimiters inside a dumped secret must not end the redaction early.
	 */
	public function test_dump_secret_containing_delimiters_is_fully_hidden(): void {
		$bodies = array(
			'print_r bracket' => "<pre>Array\n(\n    [userpwd] => P [x] => qa-tail-one\n    [page] => sites\n)\n</pre>",
			'print_r paren'   => "<pre>Array\n(\n    [userpwd] => P ) qa-tail-two\n    [page] => sites\n)\n</pre>",
			'var_dump bracket' => "<pre>array(2) {\n  [\"userpwd\"]=>\n  string(22) \"P [x] => qa-tail-three\"\n  [\"page\"]=>\n  string(5) \"sites\"\n}\n</pre>",
			'var_dump paren'  => "<pre>array(2) {\n  [\"userpwd\"]=>\n  string(16) \"P ) qa-tail-four\"\n  [\"page\"]=>\n  string(5) \"sites\"\n}\n</pre>",
		);
		foreach ( $bodies as $label => $body ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
			$this->assertStringNotContainsString( 'qa-tail-', $text, $label );
			$this->assert_sensitive_body_hidden( $text, $label );
		}
	}

	/**
	 * Bracket keys with spaces, quotes, tag splits, or no spaces around => are still classified.
	 */
	public function test_unusual_dump_keys_are_redacted(): void {
		$split = implode( '<span></span>', str_split( 'mainwpsignature_adv' ) );
		$bodies = array(
			'spaced key'    => 'Array ( [user password] => qa-key-one [page] => sites )',
			'quote in key'  => "Array ( [db'password] => qa-key-two [page] => sites )",
			'tag-split key' => '<p>[' . $split . '] => qa-key-three [page] => sites</p>',
			'no spaces'     => 'Array ( [userpwd]=>qa-key-four [page] => sites )',
		);
		foreach ( $bodies as $label => $body ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
			$this->assertStringNotContainsString( 'qa-key-', $text, $label );
			$this->assert_sensitive_body_hidden( $text, $label );
		}
	}

	/**
	 * Object property keys with a visibility suffix are classified by the property name.
	 */
	public function test_object_dump_keys_with_visibility_are_redacted(): void {
		$bodies = array(
			'print_r protected'  => 'Some\Class Object ( [page] => sites [userpwd:protected] => qa-obj-one )',
			'print_r private'    => 'Some\Class Object ( [page] => sites [userpwd:Some\Class:private] => qa-obj-two )',
			'var_dump protected' => 'object(Some\Class)#1 (2) { ["page"]=> string(5) "sites" ["userpwd":protected]=> string(12) "qa-obj-three" }',
			'var_dump private'   => 'object(Some\Class)#1 (2) { ["page"]=> string(5) "sites" ["userpwd":"Some\Class":private]=> string(11) "qa-obj-four" }',
		);
		foreach ( $bodies as $label => $body ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( $body );
			$this->assertStringNotContainsString( 'qa-obj-', $text, $label );
			$this->assert_sensitive_body_hidden( $text, $label );
		}

		// A visibility suffix on an ordinary property keeps its value.
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'Some\Class Object ( [monkey:protected] => banana )' );
		$this->assertSame( 'Some\Class Object ( [monkey:protected] => banana )', $text );
	}

	/**
	 * Tag-split arrows, spaced visibility, invisible characters, and entity-encoded headers still redact.
	 */
	public function test_split_and_encoded_dump_syntax_is_redacted_on_every_surface(): void {
		$cases = array(
			'arrow split'       => array( '<p>[userpwd] =<b></b>> qa-split-one</p>', '' ),
			'spaced visibility' => array( '<p>["userpwd":<b>protected</b>] => qa-split-two</p>', '' ),
			'zwsp key'          => array( "<p>[userpwd\u{200B}] => qa-split-three</p>", '' ),
			'nbsp header key'   => array( '', "HTTP/1.1 403 Forbidden\r\nx-cache: [userpwd\u{00A0}] => qa-split-four\r\n" ),
			'encoded header'    => array( '', "HTTP/1.1 403 Forbidden\r\nx-cache: &#91;userpwd&#93; =&gt; qa-split-five\r\n" ),
			'encoded quote'     => array( '', "HTTP/1.1 403 Forbidden\r\nx-cache: password=\"abc&#34; page=qa-split-six\"\r\n" ),
		);
		foreach ( $cases as $label => $case ) {
			$surfaces = $this->failure_surfaces( $case[0], $case[1] );
			foreach ( $surfaces as $surface_name => $surface ) {
				$this->assertStringNotContainsString( 'qa-split-', $surface, $label . ' / ' . $surface_name );
			}
			$this->assert_body_not_shown_on_surfaces( $surfaces, $label );
			if ( '' !== $case[1] ) {
				$this->assertStringContainsString( '[redacted]', $surfaces['failure_ux_text'], $label );
			}
		}
	}

	/**
	 * A header value that names a secret in any encoding is hidden whole, so quote games cannot leak part of it.
	 */
	public function test_header_values_naming_a_secret_are_hidden_whole(): void {
		$cases = array(
			'encoded inner quote'     => 'x-cache: password="abc&#34; page=qa-hdr-one"',
			'encoded enclosing quote' => 'x-cache: password=&quot;abc page=qa-hdr-two&quot;',
			'hex quote'               => 'x-cache: password=&#x22;abc page=qa-hdr-three&#x22;',
			'single quotes'           => 'x-cache: password=&#39;abc page=qa-hdr-four&#39;',
			'double encoded'          => 'x-cache: password=&amp;#34;abc page=qa-hdr-five&amp;#34;',
			'percent encoded name'    => 'x-cache: %75serpwd=qa-hdr-six',
			'nbsp inside name'        => "x-cache: [userp\u{00A0}wd] => qa-hdr-seven",
			'entity nbsp inside name' => 'x-cache: [userp&nbsp;wd] => qa-hdr-eight',
			'percent zwsp inside name' => 'x-cache: userp%E2%80%8Bwd=qa-hdr-nine',
			'percent nbsp inside name' => 'x-cache: userp%C2%A0wd=qa-hdr-ten',
			'split privkey'           => 'x-cache: [pri&nbsp;vkey] => qa-hdr-eleven',
			'split pass1'             => "x-cache: pa\u{00A0}ss1=qa-hdr-twelve",
			'split signature'         => 'x-cache: signa%C2%A0ture=qa-hdr-thirteen',
			'split authorization'     => "x-cache: author\u{00A0}ization=qa-hdr-fourteen",
			'split suffix'            => 'x-cache: custom_k&nbsp;ey=qa-hdr-fifteen',
		);
		foreach ( $cases as $label => $header ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( "HTTP/1.1 403 Forbidden\r\n" . $header . "\r\n" );
			$this->assertStringNotContainsString( 'qa-hdr-', $text, $label );
			$this->assertStringContainsString( 'x-cache: [redacted]', $text, $label );
		}
	}

	/**
	 * URLs in any kept header drop userinfo, query strings, and fragments.
	 */
	public function test_non_location_header_urls_drop_unknown_secrets(): void {
		$headers = "HTTP/1.1 502 Bad Gateway\r\nx-cache: https://alice:harbor47@example.test/cache/path?ticket=opaque42#trace\r\nserver: https://bob%3Ariver88%40origin.example.test/failure%3Ftrace%3Dopaque99%23tail\r\ncf-cache-status: https%3A%2F%2Fcarol%3Aforest77%40encoded.example.test%2Fedge%3Fopaque%3Dvalue%23tail\r\ncontent-type: https&colon;&sol;&sol;dave&colon;ridge66&commat;entity.example.test&sol;mime&quest;opaque&equals;value\r\nx-cache-status: //erin:stone55@relative.example.test/cache#tail\r\nserver: %2F%2Ffrank%3Acreek44%40encoded-relative.example.test%2Forigin%23tail\r\nx-sucuri-id: ftp://grace:field33@files.example.test/archive?opaque=value#tail\r\n";
		$text    = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );

		$this->assertStringContainsString( 'x-cache: https://example.test/cache/path', $text );
		$this->assertStringContainsString( 'server: https://origin.example.test/failure', $text );
		$this->assertStringContainsString( 'cf-cache-status: https://encoded.example.test/edge', $text );
		$this->assertStringContainsString( 'content-type: https://entity.example.test/mime', $text );
		$this->assertStringContainsString( 'x-cache-status: //relative.example.test/cache', $text );
		$this->assertStringContainsString( 'server: //encoded-relative.example.test/origin', $text );
		$this->assertStringContainsString( 'x-sucuri-id: ftp://files.example.test/archive', $text );
		foreach ( array( 'alice', 'harbor47', 'ticket=', 'opaque42', '#trace', 'bob', 'river88', 'trace=', 'opaque99', 'carol', 'forest77', 'dave', 'ridge66', 'erin', 'stone55', 'frank', 'creek44', 'grace', 'field33', 'opaque=', '%3F', '%40', '&colon;' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	/**
	 * URLs in a kept header value, alone or embedded, lose userinfo, and absolute URLs also lose query and fragment.
	 *
	 * @dataProvider header_url_values
	 *
	 * @param string $value    Raw x-cache header value.
	 * @param string $expected Displayed value.
	 */
	public function test_header_url_redaction_matrix( $value, $expected ): void {
		$this->assertSame( 'x-cache: ' . $expected, \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( 'x-cache: ' . $value ) );
	}

	/**
	 * Header values and their displayed form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function header_url_values(): array {
		return array(
			'absolute'                          => array( 'https://alice:harbor47@example.test/p?ticket=x#frag', 'https://example.test/p' ),
			'protocol-relative'                 => array( '//erin:stone55@example.test/cache#tail', '//example.test/cache' ),
			'absolute embedded after a path'    => array( 'proxy/https://alice:harbor47@example.test/path?ticket=x', 'proxy/https://example.test/path' ),
			'protocol-relative after a path'    => array( 'proxy/cache(//alice:harbor47@example.test/path?ticket=x)', 'proxy/cache(//example.test/path' ),
			'protocol-relative inside relative' => array( '/a//bob:river88@example.test?x', '/a//example.test' ),
			'encoded absolute after a path'     => array( 'proxy/https%3A%2F%2Falice:harbor47@example.test/path?ticket=x', 'proxy/https://example.test/path' ),
			'encoded slashes after a path'      => array( '/proxy/https:%2F%2Falice:harbor47@example.test/path?ticket=x', '/proxy/https://example.test/path' ),
			// Relative-URL query strings are not redacted yet; this pins the current output.
			'encoded delimiter in a query'      => array( '/p?next=//example.test/%3E&ticket=opaquevalue', '/p?next=//example.test/>&ticket=opaquevalue' ),
			'relative without query'            => array( '/authorize', '/authorize' ),
			'question without a value'          => array( 'Is /wp-json/ reachable? Check the server.', 'Is /wp-json/ reachable? Check the server.' ),
			'product version'                   => array( 'nginx/1.24.0', 'nginx/1.24.0' ),
			'media type'                        => array( 'text/html; charset=UTF-8', 'text/html; charset=UTF-8' ),
		);
	}

	/**
	 * Free-form diagnostics preserve benign text, strip URLs, and hide secret names.
	 */
	public function test_free_form_diagnostic_text_is_sanitized(): void {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;

		$this->assertSame( 'Connection failed at https://example.test/path', $ux::sanitize_diagnostic_text( 'Connection failed at https://alice:harbor47@example.test/path?ticket=opaque42#trace' ) );
		$this->assertSame( 'Plain connection failure', $ux::sanitize_diagnostic_text( 'Plain connection failure' ) );

		foreach ( array( 'userpwd=qa-free-one', 'pass<b></b>word=qa-free-two', '%2575serpwd=qa-free-three' ) as $text ) {
			$sanitized = $ux::sanitize_diagnostic_text( $text );
			$this->assertStringContainsString( 'response content hidden: it named a sensitive field', $sanitized );
			$this->assertStringNotContainsString( 'qa-free-', $sanitized );
		}
	}

	/**
	 * The status line, Digest challenges and ordinary Location paths stay readable.
	 */
	public function test_header_summary_keeps_ordinary_diagnostics(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers(
			"HTTP/1.1 403 userpwd=qa-status-secret\r\nwww-authenticate: Digest realm=\"x\", nonce=\"qa-digest-nonce\"\r\nlocation: https://example.test/price&#36;5\r\nserver: nginx\r\n"
		);

		$this->assertStringNotContainsString( 'qa-status-secret', $text );
		$this->assertStringContainsString( 'HTTP/1.1 403 [redacted]', $text );

		$encoded_status = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( "HTTP/1.1 403 p&#97;ssword=qa-status-two\r\n" );
		$this->assertStringNotContainsString( 'qa-status-two', $encoded_status );
		$this->assertStringContainsString( 'HTTP/1.1 403 [redacted]', $encoded_status );
		$this->assertStringNotContainsString( 'qa-digest-nonce', $text );
		$this->assertStringContainsString( 'www-authenticate: Digest [redacted]', $text );
		$this->assertStringContainsString( 'location: https://example.test/price$5', $text );
		$this->assertStringContainsString( 'server: nginx', $text );
	}

	/**
	 * An encoded dump with a long tail is summarized in linear time.
	 */
	public function test_encoded_header_dump_with_long_tail_is_fast(): void {
		$start = microtime( true );
		$text  = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( 'x-cache: &#91;userpwd&#93; =&gt; qa-tail-secret ' . str_repeat( 'x', 65536 ) );

		$this->assertLessThan( 0.5, microtime( true ) - $start );
		$this->assertStringNotContainsString( 'qa-tail-secret', $text );
	}

	/**
	 * Long whitespace runs in a header value do not make redaction quadratic.
	 */
	public function test_header_whitespace_runs_stay_fast(): void {
		$spaces  = str_repeat( ' ', 65536 );
		$headers = "HTTP/1.1 401 Unauthorized\r\nwww-authenticate: Bearer [userpwd] => qa-hdr-one" . $spaces . "x\r\nx-cache: password=qa-hdr-two" . $spaces . "x\r\n";

		$start   = microtime( true );
		$snippet = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertLessThan( 0.2, microtime( true ) - $start );

		$this->assertStringNotContainsString( 'qa-hdr-one', $snippet );
		$this->assertStringNotContainsString( 'qa-hdr-two', $snippet );
		$this->assertStringContainsString( 'www-authenticate:', $snippet );
	}

	/**
	 * Legacy response_readable and exception text fields cannot bypass body suppression.
	 */
	public function test_legacy_readable_response_fields_are_sanitized(): void {
		$ux = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;

		$exception = new \MainWP\Dashboard\MainWP_Exception( 'Remote failure' );
		$exception->set_data( array( 'text' => 'Status: 500 userpwd=qa-legacy-one page=sites' ) );
		$from_array = $ux::readable_response_from_output( array(), $exception );

		$string_exception = new \MainWP\Dashboard\MainWP_Exception( 'Remote failure' );
		$string_exception->set_data( 'userpwd=qa-legacy-two page=sites' );
		$from_string = $ux::readable_response_from_output( array(), $string_exception );

		$from_output = $ux::readable_response_from_output(
			array(
				'http_status'       => 500,
				'response_readable' => 'Status: 500 userpwd=qa-legacy-three page=sites',
			)
		);

		foreach ( array( $from_array, $from_string, $from_output ) as $surface ) {
			$this->assertStringContainsString( self::BODY_NOT_SHOWN, $surface );
			$this->assertStringNotContainsString( 'qa-legacy-', $surface );
			$this->assertStringNotContainsString( 'page=sites', $surface );
		}
	}

	/**
	 * Structured empty response fields win over legacy readable text without nesting it.
	 */
	public function test_pre_sanitized_empty_response_is_not_nested(): void {
		$ux       = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;
		$enriched = $ux::enrich_output(
			array( 'http_status' => 500 ),
			array(
				'http_status' => 500,
				'headers'     => '',
				'body'        => '',
			)
		);
		$readable = $ux::readable_response_from_output( $enriched );

		$this->assertSame( $enriched['response_readable'], $readable );
		$this->assertSame( 1, substr_count( $readable, 'Status: 500' ) );
		$this->assertSame( 1, substr_count( $readable, self::BODY_NOT_SHOWN ) );

		$exception = new \MainWP\Dashboard\MainWP_Exception( 'Remote failure' );
		$exception->set_data(
			array(
				'text'        => 'Status: 500 userpwd=qa-ignored-legacy',
				'http_status' => 500,
				'headers'     => '',
				'body'        => '',
			)
		);
		$readable = $ux::readable_response_from_output( array(), $exception );
		$this->assertSame( 1, substr_count( $readable, 'Status: 500' ) );
		$this->assertStringContainsString( self::BODY_NOT_SHOWN, $readable );
		$this->assertStringNotContainsString( 'qa-ignored-legacy', $readable );
	}

	/**
	 * An arbitrary exception message cannot inject a secret ahead of the failure UX.
	 */
	public function test_error_helper_sanitizes_arbitrary_exception_message(): void {
		$exception = new \MainWP\Dashboard\MainWP_Exception( 'Remote failure userpwd=qa-message-secret page=sites' );
		$html      = \MainWP\Dashboard\MainWP_Error_Helper::get_connect_failure_message(
			$exception,
			array( 'http_status' => 500 )
		);

		$this->assertStringContainsString( 'response content hidden: it named a sensitive field', $html );
		$this->assertStringContainsString( 'Status: 500', $html );
		$this->assertStringNotContainsString( 'qa-message-secret', $html );
		$this->assertStringNotContainsString( 'page=sites', $html );
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
	 * Non-WAF failures still expose outbound IP, User-Agent, status, and headers.
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
		$this->assertStringContainsString( self::BODY_NOT_SHOWN, $html );
		$this->assertStringNotContainsString( 'Internal error', $html );
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
	 * Suffix-named secrets remain hidden in headers and stored UX.
	 */
	public function test_suffix_param_names_are_redacted_like_is_sensitive_param(): void {
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'csrf_token' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'site_api_key' ) );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'user_password' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Connect_Failure_Ux::is_sensitive_param( 'page' ) );

		$body = 'Welcome csrf_token=qa-csrf-secret site_api_key=qa-api-key plugin_password=qa-plugin-secret page=sites';

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
			$this->assertStringContainsString( self::BODY_NOT_SHOWN, $surface );
			$this->assertStringNotContainsString( 'page=sites', $surface );
			$this->assertStringNotContainsString( 'qa-csrf-secret', $surface );
			$this->assertStringNotContainsString( 'qa-api-key', $surface );
			$this->assertStringNotContainsString( 'qa-plugin-secret', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate: Bearer [redacted]', $header_snip );
		$this->assertStringNotContainsString( 'qa-plugin-secret', $header_snip );
	}

	/**
	 * Comma- and space-containing header secrets stay fully redacted.
	 */
	public function test_delimiter_containing_secrets_are_fully_redacted(): void {
		$body = 'Welcome plugin_password=qa,secret csrf_token=qa space-token page=sites';

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
			$this->assertStringContainsString( self::BODY_NOT_SHOWN, $surface );
			$this->assertStringNotContainsString( 'page=sites', $surface );
			$this->assertStringNotContainsString( 'qa,secret', $surface );
			$this->assertStringNotContainsString( ',secret', $surface );
			$this->assertStringNotContainsString( 'qa space-token', $surface );
		}

		$header_snip = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( $headers );
		$this->assertStringContainsString( 'www-authenticate: Bearer [redacted]', $header_snip );
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
