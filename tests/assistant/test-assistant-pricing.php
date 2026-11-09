<?php
/**
 * Tests for MainWP_Assistant_Pricing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant_Pricing;

/**
 * Test class for MainWP_Assistant_Pricing.
 *
 * Tests cost estimation, unknown-model degradation, cost summing, and the
 * price-table filter.
 */
class Test_Assistant_Pricing extends MainWP_Assistant_Test_Case {

	/**
	 * Test a known model produces the expected estimate.
	 */
	public function test_estimate_cost_known_model() {
		$usage = array(
			'prompt_tokens'     => 1000000,
			'completion_tokens' => 1000000,
			'total_tokens'      => 2000000,
		);

		$cost = MainWP_Assistant_Pricing::estimate_cost( $usage, 'gpt-5-mini', 'openai' );

		$this->assertTrue( $cost['known'] );
		$this->assertTrue( $cost['estimated'] );
		// 1M prompt at $0.25 + 1M completion at $2.00.
		$this->assertEqualsWithDelta( 2.25, $cost['usd'], 0.000001 );
		$this->assertSame( 'gpt-5-mini', $cost['model'] );
		$this->assertSame( 'openai', $cost['provider'] );
	}

	/**
	 * Test thought tokens are priced at the output rate.
	 */
	public function test_estimate_cost_prices_thought_tokens_as_output() {
		$usage = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 500000,
			'thought_tokens'    => 500000,
			'total_tokens'      => 1000000,
		);

		$cost = MainWP_Assistant_Pricing::estimate_cost( $usage, 'claude-haiku-4-5', 'anthropic' );

		$this->assertTrue( $cost['known'] );
		// 1M output-priced tokens at $5.00.
		$this->assertEqualsWithDelta( 5.0, $cost['usd'], 0.000001 );
	}

	/**
	 * Test an unknown model degrades to known:false with a null figure.
	 */
	public function test_estimate_cost_unknown_model_degrades() {
		$usage = array(
			'prompt_tokens'     => 1000,
			'completion_tokens' => 1000,
			'total_tokens'      => 2000,
		);

		$cost = MainWP_Assistant_Pricing::estimate_cost( $usage, 'some-future-model', 'openai' );

		$this->assertFalse( $cost['known'] );
		$this->assertNull( $cost['usd'] );
		$this->assertTrue( $cost['estimated'] );
	}

	/**
	 * Test empty usage degrades to known:false.
	 */
	public function test_estimate_cost_empty_usage_degrades() {
		$cost = MainWP_Assistant_Pricing::estimate_cost( array(), 'gpt-5-mini', 'openai' );

		$this->assertFalse( $cost['known'] );
		$this->assertNull( $cost['usd'] );
	}

	/**
	 * Test dated snapshot ids resolve to their base model entry.
	 */
	public function test_estimate_cost_resolves_dated_snapshot_ids() {
		$usage = array(
			'prompt_tokens'     => 1000000,
			'completion_tokens' => 0,
			'total_tokens'      => 1000000,
		);

		$dashed   = MainWP_Assistant_Pricing::estimate_cost( $usage, 'gpt-4o-2024-08-06', 'openai' );
		$compact  = MainWP_Assistant_Pricing::estimate_cost( $usage, 'claude-haiku-4-5-20251001', 'anthropic' );

		$this->assertTrue( $dashed['known'] );
		$this->assertEqualsWithDelta( 2.5, $dashed['usd'], 0.000001 );
		$this->assertTrue( $compact['known'] );
		$this->assertEqualsWithDelta( 1.0, $compact['usd'], 0.000001 );
	}

	/**
	 * Test prefix resolution prefers the longest matching table key.
	 */
	public function test_estimate_cost_prefix_match_prefers_longest_key() {
		$usage = array(
			'prompt_tokens'     => 1000000,
			'completion_tokens' => 0,
			'total_tokens'      => 1000000,
		);

		// gpt-5-mini-beta should match gpt-5-mini ($0.25), not gpt-5 ($1.25).
		$cost = MainWP_Assistant_Pricing::estimate_cost( $usage, 'gpt-5-mini-beta', 'openai' );

		$this->assertTrue( $cost['known'] );
		$this->assertEqualsWithDelta( 0.25, $cost['usd'], 0.000001 );
	}

	/**
	 * Test sum_cost adds known figures and unknown poisons the sum.
	 */
	public function test_sum_cost_semantics() {
		$known_a = array(
			'usd'       => 0.01,
			'known'     => true,
			'estimated' => true,
		);
		$known_b = array(
			'usd'       => 0.02,
			'known'     => true,
			'estimated' => true,
		);
		$unknown = array(
			'usd'       => null,
			'known'     => false,
			'estimated' => true,
		);

		$sum = MainWP_Assistant_Pricing::sum_cost( $known_a, $known_b );
		$this->assertTrue( $sum['known'] );
		$this->assertEqualsWithDelta( 0.03, $sum['usd'], 0.000001 );

		$poisoned = MainWP_Assistant_Pricing::sum_cost( $known_a, $unknown );
		$this->assertFalse( $poisoned['known'] );
		$this->assertNull( $poisoned['usd'] );
	}

	/**
	 * Test the price table is filterable.
	 */
	public function test_price_table_is_filterable() {
		$override = static function ( $table ) {
			$table['custom-house-model'] = array(
				'input'  => 10.00,
				'output' => 20.00,
			);
			return $table;
		};
		add_filter( 'mainwp_assistant_model_prices', $override );

		try {
			$usage = array(
				'prompt_tokens'     => 1000000,
				'completion_tokens' => 1000000,
				'total_tokens'      => 2000000,
			);
			$cost  = MainWP_Assistant_Pricing::estimate_cost( $usage, 'custom-house-model', 'openai' );

			$this->assertTrue( $cost['known'] );
			$this->assertEqualsWithDelta( 30.0, $cost['usd'], 0.000001 );
		} finally {
			remove_filter( 'mainwp_assistant_model_prices', $override );
		}
	}
}
