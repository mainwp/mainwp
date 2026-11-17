<?php
/**
 * SQL-identifier safety for the wp_options view builders (branch-review M1).
 *
 * get_wp_options_join() / get_option_view_by_join() emit a field name inside
 * a backtick-quoted SQL identifier. esc_sql() does not escape backticks, so a
 * field name containing one could break out of the identifier and inject into
 * the outer SELECT. These tests assert that unsafe field names never reach
 * the SELECT, while ordinary field names stay intact.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_DB_Options_Join_Identifier extends \WP_UnitTestCase {

	/**
	 * A field name outside [A-Za-z0-9_] is dropped before it reaches the
	 * SELECT, so a backtick can never break out of the quoted identifier.
	 */
	public function test_backtick_in_field_name_is_dropped_not_broken_out() {
		$payload = 'evil`,(SELECT 1) AS `x';

		$view = MainWP_DB::instance()->get_wp_options_join( array( $payload ), 'custom_view' );

		$this->assertIsArray( $view );
		$this->assertArrayHasKey( 'selects', $view );
		$this->assertStringNotContainsString( 'evil', $view['selects'] );
		$this->assertStringNotContainsString( 'SELECT 1', $view['selects'] );
	}

	/**
	 * An ordinary option name is emitted unchanged (no mangling), so legitimate
	 * custom_fields usage keeps working.
	 */
	public function test_plain_field_name_is_unchanged() {
		$view = MainWP_DB::instance()->get_wp_options_join( array( 'blogname' ), 'custom_view' );

		$this->assertStringContainsString( '`blogname`', $view['selects'] );
	}
}
