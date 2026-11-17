<?php
/**
 * Tests for MainWP_Abilities_Util::resolve_date_range().
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

/**
 * Tests for the shared ability date-range helper.
 *
 * @group abilities
 */
class Test_Abilities_Util_Date_Range extends MainWP_Abilities_Test_Case {

	/**
	 * Defaults to a 30-day window ending now.
	 */
	public function test_defaults_to_30_day_window() {
		$range = MainWP_Abilities_Util::resolve_date_range( array() );
		$this->assertEqualsWithDelta( time(), $range['to'], 5 );
		$this->assertEqualsWithDelta( time() - 30 * DAY_IN_SECONDS, $range['from'], 5 );
	}

	/**
	 * Honors explicit bounds.
	 */
	public function test_honors_explicit_bounds() {
		$range = MainWP_Abilities_Util::resolve_date_range(
			array(
				'date_from' => 1000,
				'date_to'   => 2000,
			)
		);
		$this->assertSame( 1000, $range['from'] );
		$this->assertSame( 2000, $range['to'] );
	}

	/**
	 * Swaps inverted bounds.
	 */
	public function test_swaps_inverted_bounds() {
		$range = MainWP_Abilities_Util::resolve_date_range(
			array(
				'date_from' => 2000,
				'date_to'   => 1000,
			)
		);
		$this->assertSame( 1000, $range['from'] );
		$this->assertSame( 2000, $range['to'] );
	}

	/**
	 * Caps the span at max_days.
	 */
	public function test_caps_span_at_max_days() {
		$to    = time();
		$range = MainWP_Abilities_Util::resolve_date_range(
			array(
				'date_from' => $to - 400 * DAY_IN_SECONDS,
				'date_to'   => $to,
			)
		);
		$this->assertSame( $to - 90 * DAY_IN_SECONDS, $range['from'] );
	}
}
