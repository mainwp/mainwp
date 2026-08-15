<?php
/**
 * MainWP authenticated multi-site transport log privacy tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Verify unexpected Child responses cannot expose response bytes or site URLs.
 */
class MainWP_Connect_Log_Redaction_Test extends WP_UnitTestCase {

	/**
	 * The diagnostic retains bounded troubleshooting metadata without private values.
	 *
	 * @return void
	 */
	public function test_unexpected_response_log_excludes_response_and_url(): void {
		$response = '<html>private-token=secret-value</html>';
		$website  = (object) array(
			'id'  => 17,
			'url' => 'https://private.example.test/path?token=secret-value',
		);

		$this->assertTrue(
			method_exists( MainWP_Connect::class, 'format_unexpected_response_log' ),
			'The transport must provide one shared redacted unexpected-response diagnostic.'
		);

		$method = new ReflectionMethod( MainWP_Connect::class, 'format_unexpected_response_log' );
		$method->setAccessible( true );
		$message = $method->invoke( null, $website, $response );

		$this->assertSame(
			'curl_multi_getcontent :: unexpected response :: [siteid=17] :: [response_bytes=' . strlen( $response ) . ']',
			$message
		);
		$this->assertStringNotContainsString( $response, $message );
		$this->assertStringNotContainsString( $website->url, $message );
		$this->assertStringNotContainsString( 'secret-value', $message );
	}
}
