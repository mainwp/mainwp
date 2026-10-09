<?php
/**
 * MWP-1789 Add Site / Connect Unique Security ID copy.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1789_Unique_Security_ID_Copy
 */
class Test_MWP_1789_Unique_Security_ID_Copy extends \WP_UnitTestCase {

	/**
	 * The Add Site / Connect note must answer password vs Unique Security ID vs both.
	 */
	public function test_connection_unique_security_id_note_explains_password_vs_id(): void {
		ob_start();
		\MainWP\Dashboard\MainWP_Manage_Sites::render_connection_unique_security_id_note();
		$html = ob_get_clean();
		$text = wp_specialchars_decode( $html, ENT_QUOTES );

		$this->assertStringContainsString( 'ui blue message', $html );
		$this->assertStringContainsString( 'Settings &gt; MainWP Child', $html, 'The note must stay HTML-escaped on output.' );
		$this->assertStringContainsString( 'Administrator password only, Unique Security ID only, or both are valid, depending on how MainWP Child is configured. Generate or create a new Unique Security ID on the child site under Settings > MainWP Child: enable Require Unique Security ID and click Save Settings.', $text );
	}

	/**
	 * Multiple-site import instructions must include the same password vs ID guidance.
	 */
	public function test_import_sites_information_explains_password_vs_unique_security_id(): void {
		ob_start();
		\MainWP\Dashboard\MainWP_Manage_Sites::mainwp_managesites_information_import_sites();
		$html = ob_get_clean();
		$text = wp_specialchars_decode( $html, ENT_QUOTES );

		$this->assertStringContainsString( '(Settings &gt; MainWP Child)', $html, 'The import instructions must stay HTML-escaped on output.' );
		$this->assertStringContainsString( 'Administrator password only, Unique Security ID only, or both are valid, depending on how MainWP Child is configured. If Require Unique Security ID is enabled on the child site (Settings > MainWP Child), enter the Unique Security ID. Generate or create a new ID there by enabling Require Unique Security ID and clicking Save Settings.', $text );
	}
}
