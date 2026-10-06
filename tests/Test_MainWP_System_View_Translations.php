<?php
/**
 * Repeated translation reads must work within one request.
 *
 * @package MainWP\Tests
 */

use MainWP\Dashboard\MainWP_System_View;

/**
 * Class Test_MainWP_System_View_Translations
 */
class Test_MainWP_System_View_Translations extends WP_UnitTestCase {

	/**
	 * Translation consumers may request the map more than once.
	 */
	public function test_get_mainwp_translations_can_be_called_twice() {
		$first = MainWP_System_View::get_mainwp_translations();
		$second = MainWP_System_View::get_mainwp_translations();

		$this->assertIsArray( $first );
		$this->assertNotEmpty( $first );
		$this->assertSame( $first, $second );
	}
}
