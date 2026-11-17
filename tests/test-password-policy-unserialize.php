<?php
/**
 * Password-policy child-response deserialization safety (branch-review M3).
 *
 * posting_handler() unserializes a base64 blob returned by the child site.
 * That call must pass allowed_classes=>false so a malicious/compromised child
 * cannot trigger PHP object injection. These tests assert an object payload is
 * rejected (recorded as an error), while a legitimate array payload still
 * parses.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Password_Policy_Settings;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Password_Policy_Unserialize extends \WP_UnitTestCase {

	/**
	 * Wrap a serialized string in the child response envelope posting_handler expects.
	 *
	 * @param string $serialized Serialized payload.
	 * @return string
	 */
	private function envelope( string $serialized ): string {
		return '<mainwp>' . base64_encode( $serialized ) . '</mainwp>';
	}

	/**
	 * A serialized object must not be instantiated; it is rejected as an error.
	 */
	public function test_object_payload_is_rejected() {
		$data    = $this->envelope( serialize( new \stdClass() ) );
		$website = (object) array( 'id' => 123 );
		$output  = (object) array(
			'ok'     => array(),
			'errors' => array(),
		);

		MainWP_Password_Policy_Settings::posting_handler( $data, $website, $output );

		// allowed_classes=>false turns the object into __PHP_Incomplete_Class,
		// which is not the success/result array the handler expects, so it is
		// recorded as an error rather than acted upon.
		$this->assertArrayHasKey( 123, $output->errors );
		$this->assertArrayNotHasKey( 123, $output->ok );
	}

	/**
	 * A legitimate array payload still parses and is treated as success.
	 */
	public function test_array_success_payload_still_parses() {
		$data    = $this->envelope( serialize( array( 'result' => 'SUCCESS' ) ) );
		$website = (object) array( 'id' => 456 );
		$output  = (object) array(
			'ok'     => array(),
			'errors' => array(),
		);

		MainWP_Password_Policy_Settings::posting_handler( $data, $website, $output );

		$this->assertArrayHasKey( 456, $output->ok );
		$this->assertArrayNotHasKey( 456, $output->errors );
	}
}
