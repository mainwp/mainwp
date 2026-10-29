<?php
/**
 * First Overview visit, layout precedence, and checklist regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Get_Started;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_Overview;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Ui_Manage_Widgets_Layout;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\SystemMonitor\MainWP_System_Monitor_Cron;
use MainWP\Dashboard\SystemMonitor\MainWP_System_Monitor_Issues;

/** Exercises the production renderers with isolated user and request state. */
class Test_Onboarding_First_Overview extends \WP_UnitTestCase {

	/**
	 * State restored after each test.
	 *
	 * @var array
	 */
	private $before;

	/** Prepare the same administrator and onboarding flags as Phase One. */
	public function setUp(): void {
		parent::setUp();
		$this->before = array(
			'user'    => get_current_user_id(),
			'request' => array( $_GET, $_POST, $_REQUEST ),
			'screen'  => get_current_screen(),
			'globals' => array(),
			'static'  => array(),
		);
		foreach ( array( 'mainwp_widget_boxes', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_mainwp_menu_active_slugs', '_mainwp_disable_menus_items', 'plugin_page' ) as $name ) {
			$this->before['globals'][ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
			$GLOBALS[ $name ]                 = array();
		}
		$GLOBALS['plugin_page'] = 'mainwp_tab';
		foreach ( array( array( MainWP_Manage_Sites::class, 'total_sites', 0 ), array( MainWP_Overview::class, 'first_onboarding_overview', array() ), array( MainWP_Overview::class, 'enable_widgets', null ) ) as $state ) {
			$property = new \ReflectionProperty( $state[0], $state[1] );
			$property->setAccessible( true );
			$this->before['static'][] = array( $property, $property->getValue() );
			if ( null !== $state[2] ) {
				$property->setValue( null, $state[2] );
			}
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );
		$_GET     = array( 'page' => 'mainwp_tab' );
		$_POST    = array();
		$_REQUEST = $_GET;
		// Either flag makes the current_screen hook redirect and exit.
		delete_option( 'mainwp_run_quick_setup' );
		delete_option( 'mainwp_activated' );
		set_current_screen( 'toplevel_page_mainwp_tab' );
		foreach ( array( 'fresh_install', 'defaults_applied', 'first_update', 'mcp_visited', 'uptime_confirmed' ) as $flag ) {
			delete_option( 'mainwp_onboarding_' . $flag );
		}
		update_option( 'mainwp_onboarding_fresh_install', time() );
		update_option( 'mainwp_global_uptime_monitoring_settings', MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false ) );
		update_user_option( get_current_user_id(), 'mainwp_notice_saved_status', array() );
	}

	/** Restore globals, static caches, user, and request state. */
	public function tearDown(): void {
		foreach ( $this->before['static'] as $state ) {
			$state[0]->setValue( null, $state[1] );
		}
		foreach ( $this->before['globals'] as $name => $state ) {
			if ( $state[0] ) {
				$GLOBALS[ $name ] = $state[1];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		list( $_GET, $_POST, $_REQUEST ) = $this->before['request'];
		$GLOBALS['current_screen']       = $this->before['screen'];
		wp_set_current_user( $this->before['user'] );
		parent::tearDown();
	}

	/** Clear only the request-local decision to simulate a new page load. */
	private function next_request() {
		$property = new \ReflectionProperty( MainWP_Overview::class, 'first_onboarding_overview' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * Capture a renderer without leaking output to PHPUnit.
	 *
	 * @param callable $callback Production renderer.
	 * @return string
	 */
	private function render( $callback ) {
		ob_start();
		try {
			call_user_func( $callback );
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Resolve registered positions while avoiding unrelated widget data queries.
	 *
	 * @return \DOMXPath
	 */
	private function grid() {
		MainWP_Overview::add_meta_boxes( 'toplevel_page_mainwp_tab' );
		foreach ( $GLOBALS['mainwp_widget_boxes']['toplevel_page_mainwp_tab'] as &$box ) {
			$box['callback'] = static function () {};
		}
		unset( $box );
		$html     = $this->render(
			static function () {
				MainWP_UI::do_widget_boxes( 'toplevel_page_mainwp_tab' );
			}
		);
		$errors   = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		try {
			$document->loadHTML( $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $errors );
		}
		return new \DOMXPath( $document );
	}

	/** The receipt is written by rendering; both prompts share the cached decision. */
	public function test_first_visit_holds_both_prompts_until_second_request(): void {
		$user_id = get_current_user_id();
		$this->assertTrue( MainWP_Overview::is_first_onboarding_overview() );
		$this->assertSame( '', get_user_meta( $user_id, 'mainwp_onboarding_overview_seen', true ) );
		$this->assertStringNotContainsString( 'Feeling stuck?', $this->render( array( MainWP_Menu::class, 'render_left_menu' ) ) );
		$html = $this->render( array( MainWP_Overview::class, 'render_dashboard_body' ) );
		$this->assertStringNotContainsString( 'notice-id="widgets"', $html );
		$this->assertSame( 1, (int) get_user_meta( $user_id, 'mainwp_onboarding_overview_seen', true ) );
		$this->assertTrue( MainWP_Overview::is_first_onboarding_overview() );
		$this->assertStringNotContainsString( 'Feeling stuck?', $this->render( array( MainWP_Menu::class, 'render_left_menu' ) ) );
		$this->assertSame( array(), get_user_option( 'mainwp_notice_saved_status' ), 'Holding a prompt must not dismiss it.' );
		$this->next_request();
		$this->assertFalse( MainWP_Overview::is_first_onboarding_overview() );
		$this->assertStringContainsString( 'notice-id="widgets"', $this->render( array( MainWP_Overview::class, 'render_dashboard_body' ) ) );
		$this->assertStringContainsString( 'Feeling stuck?', $this->render( array( MainWP_Menu::class, 'render_left_menu' ) ) );
	}

	/** Existing installations retain both prompts, no checklist, and no receipt. */
	public function test_existing_install_without_marker_is_unchanged(): void {
		delete_option( 'mainwp_onboarding_fresh_install' );
		$this->assertFalse( MainWP_Overview::is_first_onboarding_overview() );
		$this->assertStringContainsString( 'notice-id="widgets"', $this->render( array( MainWP_Overview::class, 'render_dashboard_body' ) ) );
		$this->assertStringContainsString( 'Feeling stuck?', $this->render( array( MainWP_Menu::class, 'render_left_menu' ) ) );
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'mainwp_onboarding_overview_seen', true ) );
		$this->assertSame( 0, $this->grid()->query( '//*[@id="widget-get-started"]' )->length );
	}

	/** Other screens and individual sites cannot consume the global Overview visit. */
	public function test_gate_is_scoped_to_global_overview_and_each_user(): void {
		set_current_screen( 'mainwp_page_managesites' );
		$this->assertFalse( MainWP_Overview::is_first_onboarding_overview() );
		set_current_screen( 'toplevel_page_mainwp_tab' );
		MainWP_System_Utility::set_current_wpid( 123 );
		$this->assertFalse( MainWP_Overview::is_first_onboarding_overview() );
		MainWP_System_Utility::set_current_wpid( 0 );
		$this->render( array( MainWP_Overview::class, 'render_dashboard_body' ) );
		$first_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_System_Utility::set_current_wpid( 0 );
		$this->assertTrue( MainWP_Overview::is_first_onboarding_overview() );
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'mainwp_onboarding_overview_seen', true ) );
		wp_set_current_user( $first_user );
		$this->next_request();
		$this->assertFalse( MainWP_Overview::is_first_onboarding_overview() );
	}

	/** Updates fills row zero; Get Started begins at its lower edge. */
	public function test_default_position_is_first_slot_below_updates(): void {
		$xpath   = $this->grid();
		$updates = $xpath->query( '//*[@id="widget-overview"]' )->item( 0 );
		$started = $xpath->query( '//*[@id="widget-get-started"]' )->item( 0 );
		$this->assertSame( '0', $updates->getAttribute( 'gs-y' ) );
		$this->assertSame( '12', $updates->getAttribute( 'gs-w' ) );
		$this->assertSame( '0', $started->getAttribute( 'gs-x' ) );
		$this->assertSame( '4', $started->getAttribute( 'gs-w' ) );
		$this->assertSame( (int) $updates->getAttribute( 'gs-y' ) + (int) $updates->getAttribute( 'gs-h' ), (int) $started->getAttribute( 'gs-y' ) );
		$this->assertSame( 'widget-overview', $started->previousElementSibling->getAttribute( 'id' ) );
	}

	/** Saved per-user coordinates override the first-visit default. */
	public function test_saved_user_position_wins(): void {
		update_user_option(
			get_current_user_id(),
			'mainwp_widgets_sorted_toplevel_page_mainwp_tab',
			array(
				'get-started' => array(
					'x' => 8,
					'y' => 40,
					'w' => 4,
					'h' => 35,
				),
			)
		);
		$started = $this->grid()->query( '//*[@id="widget-get-started"]' )->item( 0 );
		$this->assertSame( '8', $started->getAttribute( 'gs-x' ) );
		$this->assertSame( '40', $started->getAttribute( 'gs-y' ) );
		$this->assertSame( '35', $started->getAttribute( 'gs-h' ) );
	}

	/** A deliberately loaded layout wins over both the default and saved positions. */
	public function test_explicitly_loaded_layout_wins(): void {
		update_user_option(
			get_current_user_id(),
			'mainwp_widgets_sorted_toplevel_page_mainwp_tab',
			array(
				'get-started' => array(
					'x' => 8,
					'y' => 40,
					'w' => 4,
					'h' => 35,
				),
			)
		);
		MainWP_Ui_Manage_Widgets_Layout::set_get_widgets_layout(
			true,
			array(
				'chosen' => array(
					'name'   => 'Chosen layout',
					'layout' => array(
						'get-started' => array(
							'x' => 4,
							'y' => 60,
							'w' => 8,
							'h' => 25,
						),
					),
				),
			),
			'toplevel_page_mainwp_tab'
		);
		$_GET    = array_merge(
			$_GET,
			array(
				'select_layout' => 1,
				'_opennonce'    => wp_create_nonce( 'mainwp-admin-nonce' ),
				'updated'       => 'chosen',
				'screen_slug'   => 'toplevel_page_mainwp_tab',
			)
		);
		$xpath   = $this->grid();
		$started = $xpath->query( '//*[@id="widget-get-started"]' )->item( 0 );
		$this->assertSame( '4', $started->getAttribute( 'gs-x' ) );
		$this->assertSame( '60', $started->getAttribute( 'gs-y' ) );
		$this->assertSame( '8', $started->getAttribute( 'gs-w' ) );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-widgets-selected-layout"][@data-save-on-load="true"]' )->length );
	}

	/** Page Settings visibility takes precedence even on a first visit. */
	public function test_hidden_get_started_stays_hidden(): void {
		update_user_option( get_current_user_id(), 'mainwp_settings_show_widgets', array( 'get-started' => 0 ) );
		$xpath = $this->grid();
		$this->assertSame( 0, $xpath->query( '//*[@id="widget-get-started"]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="widget-overview"]' )->length );
	}

	/** The first-visit coordinates do not override automatic placement on later visits. */
	public function test_second_visit_keeps_ordinary_default_placement(): void {
		$this->render( array( MainWP_Overview::class, 'render_dashboard_body' ) );
		$this->next_request();
		$started = $this->grid()->query( '//*[@id="widget-get-started"]' )->item( 0 );
		$this->assertFalse( $started->hasAttribute( 'gs-x' ) );
		$this->assertFalse( $started->hasAttribute( 'gs-y' ) );
	}

	/** The header uses the same flags as the checklist, before and after an update. */
	public function test_header_count_tracks_completed_milestones(): void {
		$this->assertCount( 6, MainWP_Get_Started::get_items() );
		$this->assertStringContainsString( '0 of 6 done', $this->render( array( MainWP_Get_Started::class, 'render_content' ) ) );
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$property->setValue( null, 1 );
		$this->assertStringContainsString( '1 of 6 done', $this->render( array( MainWP_Get_Started::class, 'render_content' ) ) );
		update_option( 'mainwp_onboarding_first_update', 1 );
		$this->assertStringContainsString( '2 of 6 done', $this->render( array( MainWP_Get_Started::class, 'render_content' ) ) );
	}

	/** The revised warning requires fresh onboarding and enabled uptime monitoring. */
	public function test_scheduled_tasks_description_respects_monitoring_and_cohort(): void {
		$code    = MainWP_System_Monitor_Cron::ISSUE_MONITOR_STALE;
		$payload = array( 'delay' => 3 * HOUR_IN_SECONDS );
		$this->assertSame( 'Scheduled tasks has not run for 3 hours. Verify that WP-Cron is functioning correctly.', MainWP_System_Monitor_Issues::get_message( $code, $payload ) );
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$this->assertSame( "Scheduled tasks haven't run for 3 hours. Uptime and update checks depend on them.", MainWP_System_Monitor_Issues::get_message( $code, $payload ) );
		$this->assertSame( 'Scheduled tasks have not run recently.', MainWP_System_Monitor_Issues::get_definition( $code )['title'] );
		$this->assertStringContainsString( 'WP-Cron is disabled', MainWP_System_Monitor_Issues::get_message( $code, $payload + array( 'wp_cron_disabled' => true ) ) );
		delete_option( 'mainwp_onboarding_fresh_install' );
		$this->assertSame( 'Scheduled tasks has not run for 3 hours. Verify that WP-Cron is functioning correctly.', MainWP_System_Monitor_Issues::get_message( $code, $payload ) );
	}
}
