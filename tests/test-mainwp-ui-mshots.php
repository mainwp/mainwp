<?php
/**
 * Tests for MainWP_UI mShots helpers.
 *
 * @package Mainwp
 */

use MainWP\Dashboard\MainWP_UI;

/**
 * MainWP_UI mShots tests.
 */
class MainWP_UIMshotsTest extends WP_UnitTestCase {

	/**
	 * Test canonical mShots URL generation.
	 */
	public function test_get_mshots_image_url_builds_encoded_path() {
		$url      = ' https://example.com/path/?foo=bar baz ';
		$expected = '//s0.wp.com/mshots/v1/' . rawurlencode( esc_url_raw( trim( $url ) ) ) . '?w=900';

		$this->assertSame( $expected, MainWP_UI::get_mshots_image_url( $url, 900 ) );
	}

	/**
	 * Test requeue URL generation.
	 */
	public function test_get_mshots_image_url_can_requeue() {
		$expected = '//s0.wp.com/mshots/v1/' . rawurlencode( 'https://example.com/' ) . '?w=170&requeue=true';

		$this->assertSame( $expected, MainWP_UI::get_mshots_image_url( 'https://example.com/', 170, true ) );
	}

	/**
	 * Test paired primary and requeue sources.
	 */
	public function test_get_mshots_image_sources_returns_primary_and_requeue_urls() {
		$actual = MainWP_UI::get_mshots_image_sources( 'https://example.com/', 170 );

		$this->assertSame( MainWP_UI::get_mshots_image_url( 'https://example.com/', 170 ), $actual['src'] );
		$this->assertSame( MainWP_UI::get_mshots_image_url( 'https://example.com/', 170, true ), $actual['requeue_src'] );
	}
}
