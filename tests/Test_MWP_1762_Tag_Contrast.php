<?php
/**
 * Tag text contrast tests (MWP-1762).
 *
 * Covers MainWP_System_Utility::get_tag_contrast_color() and the site tag
 * chips that use it. Light backgrounds get black text; dark backgrounds
 * keep white. Empty or invalid colors stay on the theme chip.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_MWP_1762_Tag_Contrast extends \WP_UnitTestCase {

	/**
	 * Clear the request-local contrast cache before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_contrast_cache( array() );
	}

	public function test_contrast_light_backgrounds_use_black(): void {
		$colors = array( '#ffff00', '#FFD500', '#98ff98', '#aaf0d1', '#cce2ff', '#7fb100' );
		foreach ( $colors as $color ) {
			$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( $color ), $color );
		}
	}

	public function test_contrast_dark_backgrounds_use_white(): void {
		$colors = array( '#000', '#000000', '#34424D' );
		foreach ( $colors as $color ) {
			$this->assertSame( '#ffffff', MainWP_System_Utility::get_tag_contrast_color( $color ), $color );
		}
	}

	public function test_contrast_threshold_pair(): void {
		$this->assertSame( '#ffffff', MainWP_System_Utility::get_tag_contrast_color( '#767676' ) );
		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '#777777' ) );
	}

	public function test_contrast_hex_forms(): void {
		foreach ( array( '#fff', '#FFF', '#ffffff', '#FFFFFF', '  #fff  ' ) as $color ) {
			$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( $color ), $color );
		}

		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '#aabbcc' ) );
		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '#AbC' ) );
		$this->assertArrayHasKey( '#aabbcc', $this->contrast_cache() );
		$this->assertArrayNotHasKey( '#AbC', $this->contrast_cache() );

		$html = MainWP_System_Utility::get_site_tags( $this->tag_item( 'Short', '3', '#AbC' ) );
		$this->assertStringContainsString( 'background-color:#aabbcc', $html );
		$this->assertStringNotContainsString( '#AbC', $html );
	}

	public function test_contrast_empty_and_invalid(): void {
		$colors = array( '', '   ', 'red', '#gg0000', '#ffff', '#ffffffff', 'rgb(255,255,0)' );
		foreach ( $colors as $color ) {
			$this->assertSame( '', MainWP_System_Utility::get_tag_contrast_color( $color ), $color );
		}
	}

	public function test_contrast_cache_reuses_normalized_hex(): void {
		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '#FFF' ) );
		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '#ffffff' ) );
		$this->assertSame( '#000000', MainWP_System_Utility::get_tag_contrast_color( '  #fff  ' ) );
		$this->assertSame( array( '#ffffff' => '#000000' ), $this->contrast_cache() );
	}

	public function test_contrast_cache_ignores_invalid(): void {
		$colors = array( '', '   ', 'red', '#gg0000', '#ffff', '#ffffffff', 'rgb(255,255,0)' );
		foreach ( $colors as $color ) {
			$this->assertSame( '', MainWP_System_Utility::get_tag_contrast_color( $color ), $color );
		}
		$this->assertSame( array(), $this->contrast_cache() );
	}

	public function test_linked_mint_chip_uses_black_text(): void {
		$needles = array(
			'background-color:#98ff98',
			'color:#000000!important',
			'opacity:1',
		);

		foreach ( array( false, true ) as $belong ) {
			$html = $this->render_tag( 'Mint', '7', '#98FF98', $belong );
			foreach ( $needles as $needle ) {
				$this->assertStringContainsString( $needle, $html );
			}
			$this->assertStringNotContainsString( '#98FF98', $html );
			$this->assertStringContainsString( '<a ', $html );
		}
	}

	public function test_plain_chip_sets_span_color(): void {
		foreach ( array( false, true ) as $belong ) {
			$html = $this->render_tag( 'Mint', '', '#98ff98', $belong );
			$this->assertStringContainsString( 'background-color:#98ff98', $html );
			$this->assertStringContainsString( 'color:#000000', $html );
			$this->assertStringNotContainsString( '!important', $html );
			$this->assertStringNotContainsString( '<a', $html );
			$this->assertStringNotContainsString( 'opacity:', $html );
		}
	}

	public function test_empty_and_invalid_colors_omit_forced_colors(): void {
		$colors = array( '', '   ', 'red', 'rgb(255,255,0)', '#ffff', '#ffffffff' );
		foreach ( $colors as $color ) {
			foreach ( array( false, true ) as $belong ) {
				$html = $this->render_tag( 'North', '4', $color, $belong );
				$this->assertStringContainsString( 'style="opacity:1;"', $html, $color );
				$this->assertStringNotContainsString( 'background-color', $html, $color );
				$this->assertStringNotContainsString( 'color:', $html, $color );
				$this->assertStringNotContainsString( '#fff', $html, $color );
				$this->assertStringNotContainsString( '#000000', $html, $color );
				$this->assertStringContainsString( 'ui tag mini label', $html );
				$this->assertStringContainsString( '>North<', $html );
			}
		}
	}

	public function test_client_tag_link_uses_contrast_color(): void {
		$html = MainWP_System_Utility::get_site_tags( $this->tag_item( 'Mint', '15', '#aaf0d1' ), true );

		$this->assertStringContainsString( 'page=ManageClients', $html );
		$this->assertStringContainsString( 'tags=15', $html );
		$this->assertStringNotContainsString( 'page=managesites', $html );
		$this->assertStringContainsString( 'background-color:#aaf0d1', $html );
		$this->assertStringContainsString( 'color:#000000!important', $html );
		$this->assertStringContainsString( 'opacity:1', $html );
	}

	/**
	 * Render one chip through the site-tag or belong renderer.
	 *
	 * @param string $name   Tag name.
	 * @param string $id     Tag id. Empty renders a plain chip.
	 * @param string $color  Stored background.
	 * @param bool   $belong Use get_site_tags_belong() when true.
	 * @return string
	 */
	private function render_tag( $name, $id, $color, $belong ) {
		$item = $this->tag_item( $name, $id, $color, $belong );
		if ( $belong ) {
			return MainWP_System_Utility::get_site_tags_belong( $item );
		}
		return MainWP_System_Utility::get_site_tags( $item );
	}

	/**
	 * Site row with colors supplied, so rendering does not query groups.
	 *
	 * @param string $name   Tag name.
	 * @param string $id     Tag id.
	 * @param string $color  Stored background.
	 * @param bool   $belong Shape the row for get_site_tags_belong().
	 * @return array
	 */
	private function tag_item( $name, $id, $color, $belong = false ) {
		if ( $belong ) {
			return array(
				'wpgroups_belong'      => $name,
				'wpgroupids_belong'    => $id,
				'wpgroupcolors_belong' => $color,
			);
		}

		return array(
			'wpgroups'        => $name,
			'wpgroupids'      => $id,
			'wpgroups_colors' => $color,
		);
	}

	/**
	 * Read the request-local contrast cache.
	 *
	 * @return array
	 */
	private function contrast_cache() {
		$property = new \ReflectionProperty( MainWP_System_Utility::class, 'tag_contrast_cache' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Replace the request-local contrast cache.
	 *
	 * @param array $cache Cache contents.
	 */
	private function set_contrast_cache( array $cache ) {
		$property = new \ReflectionProperty( MainWP_System_Utility::class, 'tag_contrast_cache' );
		$property->setAccessible( true );
		$property->setValue( null, $cache );
	}
}
