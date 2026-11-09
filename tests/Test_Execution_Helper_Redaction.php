<?php
/**
 * Diagnostic-log secret redaction (branch-review S3).
 *
 * The execution-sync detail log JSON-encodes the http_api_debug $args for each
 * tracked remote call. Those args carry request headers (Authorization/Bearer,
 * cookies) and the request body, which must never reach the DB-backed action
 * log. redact_call_args() strips them at the capture point while preserving the
 * non-sensitive fields (url, method, timing).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Execution_Helper;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Execution_Helper_Redaction extends \WP_UnitTestCase {

	/**
	 * Invoke the protected static redactor.
	 *
	 * @param array $args Args to redact.
	 * @return array
	 */
	private function redact( array $args ) {
		$method = new \ReflectionMethod( MainWP_Execution_Helper::class, 'redact_call_args' );
		$method->setAccessible( true );
		return $method->invoke( null, $args );
	}

	/**
	 * Sensitive request fields are removed; benign fields survive.
	 */
	public function test_sensitive_fields_are_stripped() {
		$args = array(
			'method'        => 'POST',
			'timeout'       => 30,
			'headers'       => array( 'Authorization' => 'Bearer sk-secret-token-value' ),
			'body'          => 'user=admin&password=hunter2',
			'cookies'       => array( 'session' => 'abc123' ),
			'Authorization' => 'Bearer top-level-token',
		);

		$redacted = $this->redact( $args );

		$this->assertArrayNotHasKey( 'headers', $redacted );
		$this->assertArrayNotHasKey( 'body', $redacted );
		$this->assertArrayNotHasKey( 'cookies', $redacted );
		$this->assertArrayNotHasKey( 'Authorization', $redacted );

		// Non-sensitive fields are preserved.
		$this->assertSame( 'POST', $redacted['method'] );
		$this->assertSame( 30, $redacted['timeout'] );

		// No secret token survives anywhere in the serialized result.
		$this->assertStringNotContainsString( 'sk-secret-token-value', wp_json_encode( $redacted ) );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $redacted ) );
	}

	/**
	 * A non-array arg degrades to an empty array rather than leaking.
	 */
	public function test_non_array_returns_empty_array() {
		$method = new \ReflectionMethod( MainWP_Execution_Helper::class, 'redact_call_args' );
		$method->setAccessible( true );
		$this->assertSame( array(), $method->invoke( null, 'not-an-array' ) );
	}
}
