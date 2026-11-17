<?php
/**
 * Tests for the tool-result cap applied to in-loop history.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;

/**
 * Test class for cap_tool_result_for_history().
 *
 * Tests pass-through, structural list truncation, summary fallback, and the
 * cap filter.
 */
class Test_Assistant_Tool_Result_Cap extends MainWP_Assistant_Test_Case {

	/**
	 * Invoke the private cap_tool_result_for_history() via reflection.
	 *
	 * @param mixed  $result       Tool result.
	 * @param string $ability_name Ability name.
	 *
	 * @return mixed Capped result.
	 */
	protected function cap_result( $result, string $ability_name = '' ) {
		$assistant = MainWP_Assistant::instance();
		$method    = new \ReflectionMethod( MainWP_Assistant::class, 'cap_tool_result_for_history' );
		$method->setAccessible( true );

		return $method->invoke( $assistant, $result, $ability_name );
	}

	/**
	 * Build a list-shaped result whose encoded size exceeds the default cap.
	 *
	 * @param int $count Number of list entries.
	 *
	 * @return array Result array with an items list.
	 */
	protected function make_large_list_result( int $count ): array {
		$items = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = array(
				'id'   => $i,
				'name' => 'site-' . $i . '.example.com',
				'note' => str_repeat( 'x', 120 ),
			);
		}

		return array(
			'items' => $items,
			'total' => $count,
		);
	}

	/**
	 * Test a small result passes through unchanged.
	 */
	public function test_small_result_passes_through_unchanged() {
		$result = array(
			'items' => array( array( 'id' => 1 ) ),
			'total' => 1,
		);

		$this->assertSame( $result, $this->cap_result( $result ) );
	}

	/**
	 * Test a large list result is truncated structurally and stays valid JSON.
	 */
	public function test_large_list_result_is_truncated_and_stays_valid_json() {
		$result = $this->make_large_list_result( 200 );

		$capped = $this->cap_result( $result, 'mainwp/list-sites-v1' );

		$this->assertTrue( $capped['_truncated'] );
		$this->assertLessThan( 200, count( $capped['items'] ) );
		$this->assertSame( 200 - count( $capped['items'] ), $capped['_truncated_count'] );
		$this->assertStringContainsString( 'items', $capped['_truncated_note'] );
		// Untouched sibling keys survive.
		$this->assertSame( 200, $capped['total'] );

		// The encoded payload honors the budget and round-trips as JSON.
		$json = wp_json_encode( $capped );
		$this->assertLessThanOrEqual( MainWP_Assistant::MAX_TOOL_RESULT_BYTES, strlen( $json ) );
		$this->assertSame( $capped, json_decode( $json, true ) );
	}

	/**
	 * Test a large non-list result degrades to the summary wrapper.
	 */
	public function test_non_list_large_result_falls_back_to_summary() {
		$result = array(
			'blob' => str_repeat( 'y', 10000 ),
		);

		$capped = $this->cap_result( $result );

		$this->assertTrue( $capped['_truncated'] );
		$this->assertArrayHasKey( '_summary', $capped );
		$this->assertLessThanOrEqual( 253, strlen( $capped['_summary'] ) );
		$this->assertLessThan( 1000, strlen( wp_json_encode( $capped ) ) );
	}

	/**
	 * Test the cap is filterable per ability.
	 */
	public function test_cap_is_filterable() {
		$result = array(
			'items' => array(
				array( 'name' => str_repeat( 'a', 100 ) ),
				array( 'name' => str_repeat( 'b', 100 ) ),
			),
		);

		// Under the default cap the result passes through untouched.
		$this->assertSame( $result, $this->cap_result( $result ) );

		$tighten = static function ( $cap, $ability_name ) {
			return 'mainwp/list-sites-v1' === $ability_name ? 150 : $cap;
		};
		add_filter( 'mainwp_assistant_tool_result_cap', $tighten, 10, 2 );

		try {
			$capped = $this->cap_result( $result, 'mainwp/list-sites-v1' );
			$this->assertTrue( $capped['_truncated'] );

			// Other abilities keep the default cap.
			$this->assertSame( $result, $this->cap_result( $result, 'mainwp/get-site-v1' ) );
		} finally {
			remove_filter( 'mainwp_assistant_tool_result_cap', $tighten );
		}
	}
}
