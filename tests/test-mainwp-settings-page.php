<?php
/**
 * Tests for MainWP settings page helpers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Settings;

/**
 * Test class for MainWP settings page helpers.
 */
class Test_MainWP_Settings_Page extends MainWP_Assistant_Test_Case {

	/**
	 * Test get_all_settings_values normalizes assistant enabled state.
	 */
	public function test_get_all_settings_values_normalizes_assistant_enabled() {
		delete_option( 'mainwp_assistant_enabled' );
		update_option( 'mainwp_enable_guided_chatbase', 1 );

		$values = MainWP_Settings::get_all_settings_values();

		$this->assertSame( 1, $values['mainwp_assistant_enabled'] );
	}
}
