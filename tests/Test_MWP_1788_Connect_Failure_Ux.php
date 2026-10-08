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
	 * Every failure surface built from a reflected child response body.
	 *
	 * @param string $body    Raw response body.
	 * @param string $headers Raw response headers.
	 *
	 * @return array<string,string>
	 */
	private function failure_surfaces( $body, $headers = '' ): array {
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

		$ux       = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::class;
		$context  = array(
			'http_status'      => 403,
			'headers'          => $headers,
			'body'             => $body,
			'skip_outbound_ip' => true,
		);
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
				$this->assertStringContainsString( 'json' === $form ? '"page":"sites"' : 'page=sites', html_entity_decode( $surface, ENT_QUOTES ), $label );
				$this->assertStringContainsString( 'json' === $form ? '"monkey":"1"' : 'monkey=1', html_entity_decode( $surface, ENT_QUOTES ), $label );
			}
			foreach ( array( 'response_body', 'failure_ux_text' ) as $surface_name ) {
				$name_prefix = 'json' === $form ? '"' : '';
				$this->assertStringContainsString( $name_prefix . 'userpwd' . $name_prefix . '=[redacted]', $surfaces[ $surface_name ], $form . ' / ' . $surface_name );
				$this->assertStringContainsString( $name_prefix . 'uniqueId' . $name_prefix . '=[redacted]', $surfaces[ $surface_name ], $form . ' / ' . $surface_name );
			}
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
				$this->assertStringContainsString( 'json' === $form ? '"page":"sites"' : 'page=sites', html_entity_decode( $surface, ENT_QUOTES ), $label );
				$this->assertStringContainsString( 'json' === $form ? '"monkey":"1"' : 'monkey=1', html_entity_decode( $surface, ENT_QUOTES ), $label );
			}
			$name_prefix = 'json' === $form ? '"' : '';
			$this->assertStringContainsString( $name_prefix . 'mainwpsignature_adv' . $name_prefix . '=[redacted]', $surfaces['failure_ux_text'], $form );
		}
	}

	/**
	 * print_r() and var_dump() dumps hide everything after a sensitive key; earlier keys stay.
	 */
	public function test_print_r_style_dumps_redact_sensitive_keys(): void {
		$dump = "<pre>Array\n(\n    [user] => admin\n    [function] => register\n    [page] => sites\n    [monkey] => 1\n    [userpwd] => qa-userpwd value\n    [password] => qa-password-value\n)\n</pre><p>Error establishing a connection</p>";

		$surfaces = $this->failure_surfaces( $dump );
		foreach ( $surfaces as $surface_name => $surface ) {
			$plain = html_entity_decode( $surface, ENT_QUOTES );
			foreach ( array( 'qa-userpwd', 'qa-password-value' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $plain, $surface_name );
			}
			$this->assertStringContainsString( '[user] => admin', $plain, $surface_name );
			$this->assertStringContainsString( '[page] => sites', $plain, $surface_name );
			$this->assertStringContainsString( '[monkey] => 1', $plain, $surface_name );
			// Text after the first sensitive key is dropped on purpose.
			$this->assertStringNotContainsString( 'Error establishing a connection', $plain, $surface_name );
		}
		$this->assertStringContainsString( '[userpwd] => [redacted]', $surfaces['response_body'] );
		$this->assertStringContainsString( '[userpwd] => [redacted]', $surfaces['failure_ux_text'] );

		foreach ( array( 'userpwd', 'password', 'mainwpsignature_adv' ) as $name ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'Array ( [page] => sites [' . $name . '] => qa-dump-secret )' );
			$this->assertStringNotContainsString( 'qa-dump-secret', $text, $name );
			$this->assertStringContainsString( '[page] => sites [' . $name . '] => [redacted]', $text, $name );
		}

		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( 'array(2) { ["page"]=> string(5) "sites" ["userpwd"]=> string(9) "qa-dumped" }' );
		$this->assertStringNotContainsString( 'qa-dumped', $text );
		$this->assertStringContainsString( '["page"]=> string(5) "sites"', $text );

		// Bracketed text that is not followed by => stays as written.
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '[password] reset link sent. [monkey] => banana' );
		$this->assertSame( '[password] reset link sent. [monkey] => banana', $text );
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
			$this->assertStringContainsString( '[redacted]', $text, $label );
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
			$this->assertStringContainsString( '[redacted]', $text, $label );
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
			$this->assertStringContainsString( '[redacted]', $text, $label );
			$this->assertStringContainsString( 'sites', $text, $label );
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
			$this->assertStringContainsString( '[redacted]', $surfaces['failure_ux_text'], $label );
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
		);
		foreach ( $cases as $label => $header ) {
			$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_headers( "HTTP/1.1 403 Forbidden\r\n" . $header . "\r\n" );
			$this->assertStringNotContainsString( 'qa-hdr-', $text, $label );
			$this->assertStringContainsString( 'x-cache: [redacted]', $text, $label );
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
	 * A huge benign tail after a dumped secret must not undo the redaction.
	 */
	public function test_large_dump_tail_keeps_secret_hidden(): void {
		$text = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '[userpwd] => qa-big-secret [page] => ' . str_repeat( 'a', 1048576 ) );

		$this->assertStringNotContainsString( 'qa-big-secret', $text );
		$this->assertStringContainsString( '[userpwd] => [redacted]', $text );
	}

	/**
	 * A PCRE failure hides the whole value instead of returning partly redacted text.
	 */
	public function test_redaction_fails_closed_when_pcre_fails(): void {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- forces a PCRE failure; restored below.
		try {
			$redacted = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::redact_secrets( 'Login failed password=qa-pcre-secret page=sites' );
			$body     = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::summarize_body( '<p>Login failed password=qa-pcre-secret page=sites</p>' );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restores the original limit.
		}

		$this->assertSame( '[response content hidden: it could not be safely redacted]', $redacted );
		foreach ( array( 'qa-pcre-secret', 'Login', 'page=sites' ) as $fragment ) {
			$this->assertStringNotContainsString( $fragment, $body, $fragment );
		}
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
		$this->assertStringContainsString( 'www-authenticate: Bearer [redacted]', $header_snip );
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
