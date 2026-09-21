<?php
/**
 * MWP-1790 Add Site / Connect 2FA note.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Test classes follow the existing Test_* convention.

/**
 * Class Test_MWP_1790_Add_Site_2FA_Note
 */
class Test_MWP_1790_Add_Site_2FA_Note extends \WP_UnitTestCase {

	/**
	 * The Add Site / Connect note must state that WP login 2FA does not apply.
	 */
	public function test_connection_2fa_note_explains_login_2fa_does_not_block_mainwp(): void {
		ob_start();
		\MainWP\Dashboard\MainWP_Manage_Sites::render_connection_2fa_note();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ui info message', $html );
		$this->assertStringContainsString( 'two-factor authentication (2FA) does not apply to MainWP connections', $html );
		$this->assertStringContainsString( 'administrator username and password', $html );
		$this->assertStringContainsString( 'Unique Security ID', $html );
		$this->assertStringContainsString( 'not the WordPress login 2FA challenge', $html );
	}
}
