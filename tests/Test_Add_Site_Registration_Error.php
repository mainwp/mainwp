<?php
/**
 * Add Site registration error presentation tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_View;

/**
 * Decoded Child errors remain visible in the Add Site result.
 */
class Test_Add_Site_Registration_Error extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * URL redaction must not restore executable markup after the HTML filters.
	 */
	public function test_encoded_registration_error_cannot_restore_html(): void {
		$payload = '<img src=x onerror=alert(1)>https://example.test/';
		$errors  = array(
			rawurlencode( rawurlencode( $payload ) ),
			rawurlencode( rawurlencode( rawurlencode( $payload ) ) ),
			htmlspecialchars( $payload, ENT_QUOTES ),
			htmlspecialchars( htmlspecialchars( $payload, ENT_QUOTES ), ENT_QUOTES ),
		);

		foreach ( $errors as $child_error ) {
			$information = \MainWP\Dashboard\MainWP_System_Utility::get_child_response( wp_json_encode( array( 'error' => $child_error ) ) );
			$message     = \MainWP\Dashboard\MainWP_Utility::esc_content( urldecode( $information['error'] ) );
			$html        = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::sanitize_registration_error( $message );

			$this->assertStringNotContainsString( '<', $html, 'The error prefix must remain text when failure_ux is parsed as HTML.' );
			$this->assertStringContainsString( 'https://example.test/', $html, 'Keep the non-sensitive diagnostic URL.' );
		}
	}

	/**
	 * Keep help links clickable while filtering markup decoded inside their URLs.
	 */
	public function test_registration_error_preserves_safe_links_without_injected_markup(): void {
		$links = array(
			'<a href="https://example.test/help?ticket=diagnostic" title="Support">Help document</a>',
			'<a href="https://example.test/%22%20onmouseover%3D%22alert(1)">Help document</a>',
			'<a href="https://example.test/%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E">Help document</a>',
			'<a href="https://example.test/%22%3E%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E">Help document</a>',
			'<a href="https://example.test/&quot;&#32;onmouseover=&quot;alert(1)">Help document</a>',
			'<a href="https://example.test/&lt;img&#32;src=x&#32;onerror=alert(1)&gt;">Help document</a>',
		);

		foreach ( $links as $child_error ) {
			$information = \MainWP\Dashboard\MainWP_System_Utility::get_child_response( wp_json_encode( array( 'error' => rawurlencode( $child_error ) ) ) );
			$message     = \MainWP\Dashboard\MainWP_Utility::esc_content( urldecode( $information['error'] ) );
			$html        = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::sanitize_registration_error( $message );
			$document    = new \DOMDocument();
			$previous    = libxml_use_internal_errors( true );
			try {
				$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
			}
			$xpath   = new \DOMXPath( $document );
			$anchors = $xpath->query( '//a' );

			$this->assertSame( 1, $anchors->length, 'The help link must remain an anchor.' );
			$this->assertStringContainsString( 'Help document', $anchors->item( 0 )->textContent );
			$this->assertStringStartsWith( 'https://example.test/', $anchors->item( 0 )->getAttribute( 'href' ) );
			$this->assertStringNotContainsString( 'ticket=', $html );
			$this->assertSame( 0, $xpath->query( '//img | //script | //svg | //iframe | //object | //embed' )->length );
			foreach ( $xpath->query( '//@*' ) as $attribute ) {
				$this->assertContains( $attribute->nodeName, array( 'href', 'title' ), 'Only allowlisted link attributes may survive.' );
			}
		}
	}

	/**
	 * The UI must retain the Child error when transport diagnostics are present.
	 *
	 * @dataProvider registration_errors
	 * @param string      $child_error Child registration message.
	 * @param string      $error_code Child registration code.
	 * @param string      $headers Response headers.
	 * @param string|null $expected_message Expected text after redaction.
	 */
	public function test_specific_registration_error_is_kept_in_visible_result( $child_error, $error_code, $headers, $expected_message = null ): void {
		$original_post = $_POST;
		$original_user = get_current_user_id();
		$expected      = null === $expected_message ? $child_error : $expected_message;
		$fetch         = static function () use ( $child_error, $error_code ) {
			return array(
				'error'      => rawurlencode( $child_error ),
				'error_code' => $error_code,
			);
		};
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			set_transient(
				'mainwp_public_dashboard_ip_data',
				array(
					'value'           => '203.0.113.10',
					'status'          => 'pass',
					'community_value' => 'Detected',
				),
				HOUR_IN_SECONDS
			);
			$_POST  = array(
				'managesites_add_wpurl'   => 'https://registration-error-' . wp_generate_uuid4() . '.example.com/',
				'managesites_add_wpname'  => 'Registration error',
				'managesites_add_wpadmin' => 'admin',
			);
			$output = array(
				'http_status'      => 200,
				'response_headers' => $headers,
				'failure_ux'       => '<p>Generic transport diagnostic</p>',
			);

			list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site( false, $output );

			$this->assertSame( '', $message );
			$this->assertEmpty( $site_id );
			$this->assertStringContainsString( $expected, html_entity_decode( $error, ENT_QUOTES ) );
			$this->assertSame( 1, substr_count( html_entity_decode( $error, ENT_QUOTES ), $expected ), 'The specific error appears once.' );
			$this->assertSame( $error, $output['failure_ux'], 'Both Add Site screens prefer failure_ux over the response error.' );
			$this->assertStringNotContainsString( 'Generic transport diagnostic', $output['failure_ux'] );
			$this->assertStringNotContainsString( 'OBVIOUS_FAKE_PASSWORD', wp_json_encode( $output ) );
			$this->assertStringNotContainsString( '<img', $output['failure_ux'] );
		} finally {
			remove_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
			$_POST = $original_post;
			wp_set_current_user( $original_user );
		}
	}

	/**
	 * Reconnect keeps a decoded error even when its response has Cloudflare headers.
	 *
	 * @dataProvider reconnect_errors
	 * @param string $child_error Fixed Child message.
	 * @param string $error_code Child registration code.
	 */
	public function test_reconnect_keeps_specific_error_behind_cloudflare( $child_error, $error_code ): void {
		$original_user = get_current_user_id();
		$capture       = (object) array( 'output' => null );
		$fetch         = static function ( $pre, $url, $admin, $what, $params, $force, $verify, $http_user, $http_pass, $ssl, $others, &$output ) use ( $child_error, $error_code, $capture ) {
			$output          = \MainWP\Dashboard\MainWP_Connect_Failure_Ux::enrich_output(
				$output,
				array(
					'http_status'         => 200,
					'headers'             => "HTTP/1.1 200 OK\r\ncf-ray: fixture-ray\r\nserver: cloudflare\r\n",
					'response_diagnostic' => 'Body: the site returned a MainWP error response.',
					'skip_outbound_ip'    => true,
				)
			);
			$capture->output =& $output;
			return array(
				'error'      => rawurlencode( $child_error ),
				'error_code' => $error_code,
			);
		};
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch, 10, 12 );
		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$site_id = $this->create_test_site();
			$website = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id );
			try {
				MainWP_Manage_Sites_View::m_reconnect_site( $website, false );
				$this->fail( 'A rejected registration must not report a successful reconnect.' );
			} catch ( \MainWP\Dashboard\MainWP_Exception $exception ) {
				$message = html_entity_decode( $exception->getMessage(), ENT_QUOTES );
				$this->assertStringContainsString( $child_error, $message );
				$this->assertSame( 1, substr_count( $message, $child_error ) );
				$this->assertSame( $error_code, $exception->get_message_error_code() );
				$this->assertSame( $exception->getMessage(), $capture->output['failure_ux'] );
				$this->assertStringContainsString( 'cf-ray: fixture-ray', $message );
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch, 10 );
			wp_set_current_user( $original_user );
		}
	}

	/**
	 * Two decoded errors that must precede generic firewall guidance.
	 *
	 * @return array
	 */
	public static function reconnect_errors(): array {
		return array(
			array( 'Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again.', 'REG_ERROR7' ),
			array( 'The unique security ID mismatch! Please correct it before the connection can be established.', 'REG_ERROR4' ),
		);
	}

	/**
	 * Messages and codes emitted by Child's register_site().
	 *
	 * @return array
	 */
	public static function registration_errors(): array {
		return array(
			'linked help document'                     => array(
				'Review the <a href="https://docs.mainwp.com/troubleshooting/potential-issues/">help document</a>.',
				'REG_ERROR8',
				'',
			),
			'encoded markup'                           => array(
				rawurlencode( '<img src=x onerror=alert(1)>https://example.test/' ),
				'REG_ERROR7',
				'',
				'https://example.test/',
			),
			'wrong administrator'                      => array(
				'Administrator user does not exist. Please verify that the user is an existing administrator.',
				'REG_ERROR8',
				'',
			),
			'wrong unique ID'                          => array(
				'The unique security ID mismatch! Please correct it before the connection can be established.',
				'REG_ERROR4',
				'',
			),
			'wrong administrator behind Cloudflare'    => array(
				'Administrator user does not exist. Please verify that the user is an existing administrator.',
				'REG_ERROR8',
				"HTTP/1.1 200 OK\r\ncf-ray: fixture-ray\r\nserver: cloudflare\r\n",
			),
			'wrong credentials'                        => array(
				'Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again.',
				'REG_ERROR7',
				'',
			),
			'password authentication disabled'         => array(
				'Unable to connect to the site. Password Authentication is disabled, so please verify that the Unique Security ID is correct and try again.',
				'REG_ERROR7',
				'',
			),
			'reflected password with known error code' => array(
				'Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again. password=OBVIOUS_FAKE_PASSWORD',
				'REG_ERROR7',
				'',
				'[response content hidden: it named a sensitive field]',
			),
		);
	}
}
