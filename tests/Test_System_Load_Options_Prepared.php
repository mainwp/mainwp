<?php
/**
 * load_all_options() query safety (self-audit A2).
 *
 * MainWP_System::load_all_options() passes a hardcoded option-name list through
 * the public `mainwp_init_load_all_options` filter before it builds a SQL IN()
 * clause. The list must reach the database as bound parameters, and a filter
 * callback that returns something other than a clean array must not be able to
 * inject SQL or fatal the request (this runs on every page load).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_System_Load_Options_Prepared
 */
class Test_System_Load_Options_Prepared extends \WP_UnitTestCase {

	/**
	 * Force load_all_options() down its query path by clearing the cache it
	 * short-circuits on, then reset the wpdb error state so each test measures
	 * only its own call.
	 */
	private function prime(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$GLOBALS['wpdb']->last_error = '';
	}

	/**
	 * A filter that injects an unbalanced single quote into an option name must
	 * not break the SQL. Raw-concatenated into the IN() clause this quote closes
	 * a string early and produces a syntax error; a prepared query binds it as a
	 * harmless literal.
	 */
	public function test_single_quote_option_name_does_not_break_sql(): void {
		$callback = function ( $options ) {
			$options[] = "abc'def";
			return $options;
		};
		add_filter( 'mainwp_init_load_all_options', $callback );

		$this->prime();
		$result = MainWP_System::instance()->load_all_options();

		remove_filter( 'mainwp_init_load_all_options', $callback );

		$this->assertIsArray( $result, 'load_all_options() must still return an array' );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error, 'the injected quote must not produce a SQL error' );
		// The legitimate options are still fetched; the query was not derailed.
		$this->assertArrayHasKey( 'mainwp_db_version', $result );
	}

	/**
	 * A filter that returns a non-array must not fatal. count() on a non-array
	 * throws a TypeError on PHP 8, so the query builder has to coerce first.
	 */
	public function test_non_array_filter_return_does_not_fatal(): void {
		$callback = function () {
			return false;
		};
		add_filter( 'mainwp_init_load_all_options', $callback );

		$this->prime();
		$result = MainWP_System::instance()->load_all_options();

		remove_filter( 'mainwp_init_load_all_options', $callback );

		$this->assertIsArray( $result, 'a non-array filter return must degrade to an empty option set, not a fatal' );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );
	}

	/**
	 * The normal path (no filter interference) still loads options.
	 */
	public function test_normal_load_returns_mainwp_options(): void {
		$this->prime();
		$result = MainWP_System::instance()->load_all_options();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'mainwp_db_version', $result );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );
	}
}
