<?php
/**
 * Child detection responses, start choices, MCP availability and milestone receipts.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Get_Started;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler;
use MainWP\Dashboard\MainWP_Setup_Wizard;

/** Covers the PHP side without booting the wizard's exit-driven entry point. */
class Test_Onboarding_Phase_Two extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Previous request and user.
	 *
	 * @var array
	 */
	private $before;

	/**
	 * Cached site count.
	 *
	 * @var mixed
	 */
	private $site_count;

	/** Isolate the actor, request and cached count. */
	public function setUp(): void {
		parent::setUp();
		$this->before = array( $_GET, $_POST, $_REQUEST, get_current_user_id() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$this->site_count = $property->getValue();
		$property->setValue( null, 0 );
		update_user_option( get_current_user_id(), 'mainwp_selected_theme', 'default' );
		delete_option( 'mainwp_onboarding_first_update' );
		delete_option( 'mainwp_onboarding_fresh_install' );
	}

	/** Restore shared cached state and the caller. */
	public function tearDown(): void {
		list( $_GET, $_POST, $_REQUEST, $user ) = $this->before;
		wp_set_current_user( $user );
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$property->setValue( null, $this->site_count );
		parent::tearDown();
	}

	/**
	 * Stop at the response hook before check_site's unconditional die.
	 *
	 * @param array $information Child response.
	 * @param bool  $connected Existing Dashboard connection.
	 * @return array
	 */
	private function check_response( $information, $connected = false ) {
		$url = 'https://onboarding-' . wp_generate_uuid4() . '.example.org/';
		if ( $connected ) {
			$this->create_test_site( array( 'url' => $url ) );
		}
		$_POST   = array(
			'url'   => $url,
			'admin' => 'admin',
		);
		$fetch   = static function () use ( $information ) {
			return $information;
		};
		$capture = static function ( $response ) {
			throw new \RuntimeException( wp_json_encode( $response ) );
		};
		add_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
		add_action( 'mainwp_check_site_result', $capture );
		try {
			MainWP_Manage_Sites_Handler::check_site();
			$this->fail( 'The response hook must run before exit.' );
		} catch ( \RuntimeException $result ) {
			return json_decode( $result->getMessage(), true );
		} finally {
			remove_filter( 'mainwp_fetch_url_not_authed_pre', $fetch );
			remove_action( 'mainwp_check_site_result', $capture );
		}
	}

	/** Only the two named integers survive the successful stats response. */
	public function test_check_site_forwards_only_present_integer_timer_fields(): void {
		$result = $this->check_response(
			array(
				'wpversion'                   => '6.2',
				'connection_window_minutes'   => 20,
				'connection_window_remaining' => 1199,
				'private_field'               => 'discard',
			)
		);
		$this->assertSame( 'OK', $result['response'] );
		$this->assertSame( 20, $result['connection_window_minutes'] );
		$this->assertSame( 1199, $result['connection_window_remaining'] );
		$this->assertArrayNotHasKey( 'wpversion', $result );
		$this->assertArrayNotHasKey( 'private_field', $result );
		$disabled = $this->check_response(
			array(
				'wpversion'                 => '6.2',
				'connection_window_minutes' => 0,
			)
		);
		$this->assertSame( 0, $disabled['connection_window_minutes'] );
		$this->assertArrayNotHasKey( 'connection_window_remaining', $disabled );
	}

	/** Older Children and malformed timer values cannot manufacture a deadline. */
	public function test_check_site_omits_absent_and_non_integer_timer_fields(): void {
		foreach ( array( null, '20', 20.0, false, array( 20 ) ) as $value ) {
			$result = $this->check_response(
				array(
					'wpversion'                   => '6.2',
					'connection_window_minutes'   => $value,
					'connection_window_remaining' => $value,
				)
			);
			$this->assertArrayNotHasKey( 'connection_window_minutes', $result );
			$this->assertArrayNotHasKey( 'connection_window_remaining', $result );
		}
		$this->assertSame(
			array(
				'response' => 'OK',
				'check_me' => null,
			),
			$this->check_response( array( 'wpversion' => '6.2' ) )
		);
	}

	/** An error response, including an existing connection, never carries timer data. */
	public function test_check_site_does_not_forward_timer_fields_on_errors(): void {
		$information = array(
			'error'                       => 'This site already contains a link.',
			'connection_window_minutes'   => 20,
			'connection_window_remaining' => 600,
		);
		foreach ( array( false, true ) as $connected ) {
			$result = $this->check_response( $information, $connected );
			$this->assertStringStartsWith( 'ERROR ', $result['response'] );
			$this->assertArrayNotHasKey( 'connection_window_minutes', $result );
			$this->assertArrayNotHasKey( 'connection_window_remaining', $result );
		}
	}

	/**
	 * Render a wizard view without its entry point.
	 *
	 * @param string $view Public view method.
	 * @return string
	 */
	private function view( $view ) {
		$wizard = ( new \ReflectionClass( Onboarding_Phase_Two_Wizard::class ) )->newInstanceWithoutConstructor();
		ob_start();
		try {
			$wizard->$view();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** The screenshot and both cards retain their mode through the silent check. */
	public function test_welcome_renders_preview_callouts_and_both_start_choices(): void {
		$html = $this->view( 'mwp_setup_welcome' );
		foreach ( array( 'How do you want to start?', 'dashboard-preview.jpg', 'Updates waiting across your sites', 'Update everything in one click', 'Connection status for every site', 'Connect my first site', 'Import many sites (CSV)', 'Recommended', 'mode=single-site', 'mode=multiple-site', 'step=connect_first_site' ) as $copy ) {
			$this->assertStringContainsString( $copy, $html );
		}
		$this->assertStringContainsString( 'alt="MainWP Dashboard overview with connected sites and pending updates"', $html );
		$this->assertStringNotContainsString( 'name="tab_connect"', $html );
	}

	/** CSV entry selects the existing import panel; unknown modes select the single form. */
	public function test_connect_view_selects_requested_mode_without_radio_cards(): void {
		foreach ( array( 'multiple-site', 'single-site', 'bad" onmouseover="alert(1)' ) as $mode ) {
			$_GET['mode'] = $mode;
			$html         = $this->view( 'mwp_setup_connect_first_site' );
			$expected     = 'multiple-site' === $mode ? $mode : 'single-site';
			$this->assertStringContainsString( 'data-mode="' . $expected . '"', $html );
			$this->assertStringNotContainsString( 'name="tab_connect"', $html );
			$this->assertStringContainsString( 'id="mainwp-onboarding-detection"', $html );
			$this->assertStringContainsString( 'qsw_nonce[mainwp_checkwp]', $html );
			$this->assertStringNotContainsString( 'onmouseover="alert', $html );
		}
	}

	/** The summary card follows the same menu and permission guard as Get Started. */
	public function test_summary_ai_card_follows_mcp_availability(): void {
		global $_mainwp_disable_menus_items;
		$before                      = $_mainwp_disable_menus_items;
		$_mainwp_disable_menus_items = array();
		$_GET['siteid']              = 0;
		$hide                        = static function ( $disabled, $level, $slug ) {
			return 'ai' === $slug ? true : $disabled;
		};
		try {
			$html = $this->view( 'mwp_setup_ready' );
			$this->assertStringContainsString( 'Connect your AI assistant', $html );
			$this->assertStringContainsString( '<code>Which of my sites have plugin updates waiting?</code>', $html );
			$this->assertStringContainsString( 'admin.php?page=mainwp-mcp', $html );
			$errors   = libxml_use_internal_errors( true );
			$document = new \DOMDocument();
			try {
				$document->loadHTML( $html );
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $errors );
			}
			$xpath = new \DOMXPath( $document );
			$this->assertSame( 3, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]/div[@class="card"]' )->length );
			$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]/div[@class="card"]//a[normalize-space(.)="Set up MCP"]' )->length );
			add_filter( 'mainwp_is_disable_menu_item', $hide, 10, 3 );
			$this->assertStringNotContainsString( 'Connect your AI assistant', $this->view( 'mwp_setup_ready' ) );
			remove_filter( 'mainwp_is_disable_menu_item', $hide, 10 );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertStringNotContainsString( 'Connect your AI assistant', $this->view( 'mwp_setup_ready' ) );
		} finally {
			remove_filter( 'mainwp_is_disable_menu_item', $hide, 10 );
			$_mainwp_disable_menus_items = $before;
		}
	}

	/**
	 * Render the widget and capture its animation classes.
	 *
	 * @return string
	 */
	private function widget() {
		ob_start();
		MainWP_Get_Started::render_content();
		return ob_get_clean();
	}

	/** Each user sees each completed milestone celebrate once, including later completions. */
	public function test_celebration_seen_tracking_is_per_user_and_once_per_milestone(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$first = get_current_user_id();
		$this->widget();
		$this->assertNotContains( 'first_update', (array) get_user_meta( $first, 'mainwp_onboarding_seen_milestones', true ) );
		update_option( 'mainwp_onboarding_first_update', 1 );
		$this->assertMatchesRegularExpression( '/<i class="green check circle icon tadaaaa"><\/i>\s*<div class="content"><div class="header">Run your first update<\/div>/', $this->widget() );
		$this->assertContains( 'first_update', get_user_meta( $first, 'mainwp_onboarding_seen_milestones', true ) );
		$this->assertStringNotContainsString( 'tadaaaa', $this->widget() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertMatchesRegularExpression( '/<i class="green check circle icon tadaaaa"><\/i>\s*<div class="content"><div class="header">Run your first update<\/div>/', $this->widget() );
		$this->assertStringNotContainsString( 'tadaaaa', $this->widget() );
		wp_set_current_user( $first );
		delete_option( 'mainwp_onboarding_first_update' );
		$this->widget();
		update_option( 'mainwp_onboarding_first_update', 1 );
		$this->assertStringNotContainsString( 'tadaaaa', $this->widget() );
	}

	/** Hidden widgets cannot consume unseen completions. */
	public function test_hidden_widget_does_not_record_seen_milestones(): void {
		update_option( 'mainwp_onboarding_first_update', 1 );
		$this->assertSame( '', $this->widget() );
		$this->assertSame( '', get_user_meta( get_current_user_id(), 'mainwp_onboarding_seen_milestones', true ) );
	}
}

/** Keeps rendering tests independent of the host PHP and OpenSSL configuration. */
class Onboarding_Phase_Two_Wizard extends MainWP_Setup_Wizard {
	/**
	 * Render the happy path through the production welcome view.
	 *
	 * @return bool
	 */
	protected function system_checks_pass() {
		return true;
	}
}
