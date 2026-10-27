<?php
/**
 * Stored text remains inert in Dashboard renderers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Widget_Plugins;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin;
use MainWP\Dashboard\Module\Log\Log_Changes_Logs_Helper;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;

/** Output boundary regression coverage. */
class Test_Dashboard_Stored_Text_Escaping extends \WP_UnitTestCase {

	use Site_Fixture;

	/** Render both widget tabs with stored plugin names. */
	public function test_plugin_widget_titles(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		require_once dirname( __DIR__ ) . '/widgets/widget-mainwp-widget-plugins.php';
		$site = MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
		foreach ( array( 'Evil" onmouseover="x <img src=x onerror=x> & More', 'Akismet Anti-Spam & More', 'Akismet &amp; More' ) as $name ) {
			$plugins = array();
			foreach ( array( 0, 1 ) as $active ) {
				$plugins[] = array(
					'name'    => $name,
					'version' => '1.0',
					'slug'    => 'fixture' . $active . '/fixture.php',
					'active'  => $active,
				);
			}
			ob_start();
			MainWP_Widget_Plugins::render_html_widget( $site, $plugins );
			$html  = ob_get_clean();
			$dom   = $this->parse_html( $html );
			$links = ( new \DOMXPath( $dom ) )->query( '//a[contains(@class,"open-plugin-details-modal")]' );
			$this->assertSame( 2, $links->length );
			foreach ( $links as $link ) {
				$this->assertFalse( $link->hasAttribute( 'onmouseover' ), $html );
				$this->assertSame( 'More information about ' . html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ), $link->getAttribute( 'title' ) );
			}
		}
	}

	/** Render stored icon tokens without introducing attributes. */
	public function test_cost_icon_attributes(): void {
		$admin = ( new \ReflectionClass( Cost_Tracker_Admin::class ) )->newInstanceWithoutConstructor();
		foreach ( array( 'deficon:wordpress', 'deficon:wordpress" onmouseover="x < > &', '' ) as $icon ) {
			$product = (object) array(
				'cost_icon'  => $icon,
				'cost_color' => '#34424D" onmouseover="x < > &',
			);
			$html    = $admin->get_product_icon_display( $product );
			$dom     = $this->parse_html( $html );
			$this->assertSame( 0, ( new \DOMXPath( $dom ) )->query( '//*[@onmouseover or @onerror]' )->length, $html );
			$node = $dom->getElementsByTagName( 'i' )->item( 0 );
			$this->assertNotNull( $node );
			if ( '' !== $icon ) {
				$this->assertSame( str_replace( 'deficon:', '', $icon ) . ' large icon', $node->getAttribute( 'class' ) );
			}
			$this->assertSame( 'color:' . $product->cost_color . ';', $node->getAttribute( 'style' ) );
		}
		foreach ( array( 'fixture.png', 'fixture" onerror="x < > &.png' ) as $icon ) {
			$html = $admin->get_product_icon_display(
				(object) array(
					'cost_icon'  => $icon,
					'cost_color' => '',
				)
			);
			$dom  = $this->parse_html( $html );
			$this->assertSame( 0, ( new \DOMXPath( $dom ) )->query( '//*[@onmouseover or @onerror]' )->length, $html );
			$this->assertSame( 1, $dom->getElementsByTagName( 'img' )->length );
			$this->assertSame( 'Product icon', $dom->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'alt' ) );
			if ( 'fixture.png' === $icon ) {
				$this->assertStringEndsWith( '/fixture.png', $dom->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'src' ) );
			}
		}
	}

	/** Format every metadata placeholder through the real table methods. */
	public function test_changes_log_titles(): void {
		$table = new Log_Events_List_Table( null );
		foreach ( array( 'Evil" <img src=x onerror=x> & More', 'Akismet Anti-Spam & More', 'Akismet &amp; More' ) as $name ) {
			foreach ( array( 1455, 1460, 1465, 1470, 1461, 1965 ) as $type ) {
				$record = (object) array(
					'log_type_id' => $type,
					'action'      => $name,
					'meta'        => array(
						'name'   => $name,
						'plugin' => $name,
						'theme'  => $name,
					),
				);
				$html   = in_array( $type, array( 1461, 1965 ), true ) ? $table->get_changes_formatted_object_title( '', $record ) : $table->parse_changes_action_title( $record );
				$this->assertNotSame( '', $html );
				$this->assertSame( 0, $this->parse_html( $html )->getElementsByTagName( 'img' )->length, $html );
				$this->assertStringContainsString( esc_html( $name ), $html );
				$this->assertStringContainsString( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
				$plain_title = new \ReflectionMethod( Log_Changes_Logs_Helper::class, 'plain_text_title' );
				$plain_title->setAccessible( true );
				$this->assertStringContainsString( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ), $plain_title->invoke( null, $html ) );
			}
			$this->assertSame( $name . ' plugin', Log_Changes_Logs_Helper::get_changes_log_title( 1461, array( 'plugin' => $name ), 'object' ) );
		}
	}

	/** Parse output independently of the escaping implementation.
	 *
	 * @param string $html Rendered markup.
	 * @return \DOMDocument
	 */
	private function parse_html( $html ) {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $dom;
	}
}
