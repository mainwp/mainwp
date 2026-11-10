<?php
/**
 * Saved widget layout regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */
namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_UI;

class Test_Widget_Collapsed_Layout extends \WP_UnitTestCase {
	public function test_only_multi_widget_collapsed_layouts_use_defaults() {
		$had_boxes = array_key_exists( 'mainwp_widget_boxes', $GLOBALS );
		$before    = $had_boxes ? $GLOBALS['mainwp_widget_boxes'] : null;
		$request = $_GET;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$collapsed = array( 'first' => array( 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 4 ), 'second' => array( 'x' => 0, 'y' => 4, 'w' => 1, 'h' => 4 ) );
		$columns = $collapsed;
		$columns['second']['x'] = 4;
		$mixed = $collapsed;
		$mixed['second']['w'] = 6;
		try {
			foreach ( array(
				array( $collapsed, 'mainwp_page_operations', array( 6, 6 ) ),
				array( $columns, 'mainwp_page_operations', array( 1, 1 ) ),
				array( $mixed, 'mainwp_page_operations', array( 1, 6 ) ),
				array( array( 'first' => $collapsed['first'] ), 'mainwp_page_operations', array( 1 ) ),
				array( $collapsed, 'mainwp_page_manageclients', array( 6, 6 ) ),
				array( $collapsed, 'mainwp_page_operations', array( 6, 6 ), true ),
			) as $case ) {
				list( $layout, $page, $expected ) = $case;
				$_GET = array( 'page' => 'Operations', 'client_id' => 17 );
				$GLOBALS['mainwp_widget_boxes'][ $page ] = array();
				foreach ( array_keys( $layout ) as $id ) {
					$GLOBALS['mainwp_widget_boxes'][ $page ][ $id ] = array( 'id' => $id, 'callback' => static function () {}, 'layout' => array( 0, 0, 6, 4 ) );
				}
				update_user_option( get_current_user_id(), 'mainwp_widgets_sorted_' . $page, 'mainwp_page_manageclients' === $page ? array( 17 => $layout ) : $layout );
				if ( ! empty( $case[3] ) ) {
					\MainWP\Dashboard\MainWP_Ui_Manage_Widgets_Layout::set_get_widgets_layout( true, array( 'saved' => array( 'name' => 'Desktop', 'layout' => $layout ) ), 'operations' );
					$_GET += array( 'select_layout' => 1, '_opennonce' => wp_create_nonce( 'mainwp-admin-nonce' ), 'updated' => 'saved', 'screen_slug' => 'operations' );
				}

				ob_start();
				MainWP_UI::do_widget_boxes( $page );
				$html = ob_get_clean();
				if ( ! empty( $case[3] ) ) {
					$this->assertStringContainsString( 'layout-name="Desktop"', $html );
					$this->assertStringContainsString( 'data-save-on-load="true"', $html );
				}
				preg_match_all( '/gs-w="(\d+)"/', $html, $widths );
				$this->assertSame( $expected, array_map( 'intval', $widths[1] ), $page . ': ' . wp_json_encode( $layout ) );
			}
		} finally {
			$_GET = $request;
			if ( $had_boxes ) {
				$GLOBALS['mainwp_widget_boxes'] = $before;
			} else {
				unset( $GLOBALS['mainwp_widget_boxes'] );
			}
		}
	}
}
