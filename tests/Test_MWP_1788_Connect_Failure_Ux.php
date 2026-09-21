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
