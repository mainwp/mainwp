<?php
/**
 * Category popup site-name escaping tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Post_Page_Handler;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Verify site names cannot provide popup HTML.
 */
class Test_Category_Popup_Site_Name_Escaping extends \WP_UnitTestCase {

	/**
	 * Load the page handler used by the category AJAX response.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/pages/page-mainwp-post-page-handler.php';
	}

	/**
	 * Entity-encoded markup must be handled as text by the popup initializer.
	 */
	public function test_site_name_is_not_exposed_as_fomantic_html(): void {
		$categories = array(
			array(
				'name'        => 'Security',
				'slug'        => 'security',
				'taxonomy'    => 'category',
				'parent'      => 0,
				'description' => '',
				'site_id'     => 1,
				'site_name'   => '&lt;img src=x onerror=alert(1)&gt;',
				'children'    => array(),
			),
		);
		$printed    = array();

		ob_start();
		MainWP_Post_Page_Handler::print_catergories_tree( $categories, $printed );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'data-html=', $html );
		$this->assertStringContainsString(
			'html-popup-content="&lt;img src=x onerror=alert(1)&gt;"',
			$html
		);
	}
}
