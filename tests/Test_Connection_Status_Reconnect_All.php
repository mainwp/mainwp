<?php
/**
 * Connection Status reconnect queue coverage.
 *
 * @package MainWP\Dashboard\Tests
 */

use MainWP\Dashboard\MainWP_Connection_Status;

require_once dirname( ( new ReflectionClass( WP_UnitTestCase::class ) )->getFileName() ) . '/testcase-ajax.php';

/** Exercise the real widget, query scope and AJAX handler. */
class Test_Connection_Status_Reconnect_All extends WP_Ajax_UnitTestCase {

	/**
	 * Fixture site IDs.
	 *
	 * @var int[]
	 */
	private $ids = array();

	/** Seed thirty disconnected sites and one healthy site. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wpdb;
		for ( $i = 0; $i < 31; $i++ ) {
			$data = array();
			foreach ( $wpdb->get_results( 'DESCRIBE ' . $wpdb->prefix . 'mainwp_wp' ) as $column ) {
				if ( 'id' === $column->Field ) {
					continue;
				}
				if ( null === $column->Default ) {
					$data[ $column->Field ] = str_contains( $column->Type, 'int' ) ? 0 : '';
				}
			}
			$data = array_merge(
				$data,
				array(
					'userid'    => get_current_user_id(),
					'name'      => 'Queue ' . $i . ' <img src=x onerror=alert(1)>',
					'url'       => 'https://queue-' . $i . '.example/',
					'adminname' => 'admin',
				)
			);
			$wpdb->insert( $wpdb->prefix . 'mainwp_wp', $data );
			$id = (int) $wpdb->insert_id;
			$this->assertGreaterThan( 0, $id, $wpdb->last_error );
			$this->ids[] = $id;
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_wp_sync',
				array(
					'wpid'        => $id,
					'sync_errors' => 30 === $i ? '' : 'Test disconnected',
				)
			);
		}
	}

	/** Clear request state after each AJAX probe. */
	public function tear_down() {
		$_REQUEST = array();
		parent::tear_down();
	}

	/** Pagination must not truncate the reconnect queue. */
	public function test_complete_queue_and_widget_render() {
		$widget             = \MainWP\Dashboard\MainWP_Connection_Status::instance();
		$_REQUEST['start']  = 25;
		$_REQUEST['length'] = 25;
		$pagination         = static function ( $args ) {
			$args['offset']   = 25;
			$args['rowcount'] = 25;
			return $args;
		};
		add_filter( 'mainwp_connection_status_widget_get_sites_query_params', $pagination );
		try {
			$sites = $widget->get_reconnect_sites();
		} finally {
			remove_filter( 'mainwp_connection_status_widget_get_sites_query_params', $pagination );
		}
		$actual = array_column( $sites, 'id' );
		sort( $actual );
		$expected = array_slice( $this->ids, 0, 30 );
		sort( $expected );
		$this->assertSame( $expected, $actual );
		ob_start();
		$widget::render_tab( 'disconnected' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="mainwp-connection-status-reconnect-all"', $html );
		$this->assertStringContainsString( 'role="status" aria-live="polite"', $html );
	}

	/** The Team Control site-access filter constrains every queued ID. */
	public function test_access_scope_and_empty_scope() {
		$allowed = array( $this->ids[1], $this->ids[27] );
		$scope   = static function () use ( $allowed ) {
			return $allowed;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		$actual = array_column( \MainWP\Dashboard\MainWP_Connection_Status::instance()->get_reconnect_sites(), 'id' );
		sort( $actual );
		sort( $allowed );
		$this->assertSame( $allowed, $actual );
		remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		add_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
		$this->assertSame( array(), \MainWP\Dashboard\MainWP_Connection_Status::instance()->get_reconnect_sites() );
		remove_filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
	}

	/** Legacy multi-user mode also constrains ownership. */
	public function test_multiuser_owner_exclusion() {
		global $wpdb;
		add_filter( 'mainwp_is_single_user', '__return_false' );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'userid' => 999999 ), array( 'id' => $this->ids[28] ) );
		$actual = array_column( \MainWP\Dashboard\MainWP_Connection_Status::instance()->get_reconnect_sites(), 'id' );
		$this->assertCount( 29, $actual );
		$this->assertNotContains( $this->ids[28], $actual );
		remove_filter( 'mainwp_is_single_user', '__return_false' );
	}

	/** A valid user nonce returns all eligible sites through the AJAX handler. */
	public function test_authenticated_ajax() {
		$_POST    = array(
			'reconnect_all' => 1,
			'security'      => wp_create_nonce( 'mainwp_widgets_connections_status_details_display_rows' ),
		);
		$_REQUEST = $_POST;
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Connection_Status::instance()->ajax_display_rows();
		} catch ( WPAjaxDieContinueException $e ) {
			// The WordPress AJAX test handler captured the JSON response.
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertCount( 30, $response['sites'] );
	}

	/** A valid nonce does not authorize a subscriber to reconnect sites. */
	public function test_non_admin_denied() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST    = array(
			'reconnect_all' => 1,
			'security'      => wp_create_nonce( 'mainwp_widgets_connections_status_details_display_rows' ),
		);
		$_REQUEST = $_POST;
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Connection_Status::instance()->ajax_display_rows();
		} catch ( WPAjaxDieContinueException $e ) {
			// The WordPress AJAX test handler captured the denial response.
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertArrayNotHasKey( 'sites', $response );
	}

	/** Reject missing and invalid widget nonces using the real security check. */
	public function test_bad_nonce_is_rejected() {
		unset( $_REQUEST['security'] );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Post_Handler::instance()->check_security( 'mainwp_widgets_connections_status_details_display_rows', 'security', false ) );
		$_REQUEST['security'] = 'invalid';
		$this->assertFalse( \MainWP\Dashboard\MainWP_Post_Handler::instance()->check_security( 'mainwp_widgets_connections_status_details_display_rows', 'security', false ) );
	}

	/** The rendered success string and endpoint translation decode to the same text. */
	public function test_translated_success_matches_endpoint_text() {
		$translation = 'Site\'s "A&B" <ready>';
		$filter      = static function ( $translated, $text, $domain ) use ( $translation ) {
			return 'mainwp' === $domain && 'Site has been reconnected successfully!' === $text ? $translation : $translated;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		ob_start();
		try {
			MainWP_Connection_Status::render_content( 0, 30 );
			$html     = ob_get_contents();
			$expected = __( 'Site has been reconnected successfully!', 'mainwp' );
		} finally {
			ob_end_clean();
			remove_filter( 'gettext', $filter, 10 );
		}
		$this->assertSame( $translation, $expected );
		$this->assertStringContainsString( "const success = mainwp_entities_text('" . esc_js( $expected ) . "');", $html );
		$this->assertSame(
			html_entity_decode( esc_html( $expected ), ENT_QUOTES, 'UTF-8' ),
			html_entity_decode( stripslashes( esc_js( $expected ) ), ENT_QUOTES, 'UTF-8' )
		);
	}
}
