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

	/** Choices precede the preview so the screenshot cannot bury the actions. */
	public function test_welcome_renders_choices_before_a_modest_preview(): void {
		$html = $this->view( 'mwp_setup_welcome' );
		foreach ( array( 'How do you want to start?', 'dashboard-preview.jpg', 'Updates waiting across your sites', 'Update everything in one click', 'Connection status for every site', 'Connect my first site', 'Import a CSV', 'Recommended', 'mode=single-site', 'mode=multiple-site', 'step=connect_first_site' ) as $copy ) {
			$this->assertStringContainsString( $copy, $html );
		}
		$this->assertStringContainsString( 'alt="MainWP Dashboard overview with connected sites and pending updates"', $html );
		$this->assertStringNotContainsString( 'name="tab_connect"', $html );
		$xpath = $this->html_xpath( $html );
		$cards = '//div[@class="ui two stackable link cards"][@id="mainwp-onboarding-start-cards"]';
		$this->assertSame( 1, $xpath->query( '//h1[normalize-space(.)="How do you want to start?"]/following-sibling::*[1][self::p]/following-sibling::*[1][@class="ui two stackable link cards"]' )->length );
		$this->assertSame( 2, $xpath->query( $cards . '/a[@class="ui card"]' )->length );
		$this->assertSame( 2, $xpath->query( $cards . '/a[@class="ui card"][div[@class="content"]/div[@class="description"]]' )->length );
		$this->assertSame( 0, $xpath->query( $cards . '/a//p' )->length );
		$this->assertSame( 1, $xpath->query( $cards . '/a[1]/div[@class="extra content"]/span[@class="ui green button"][@aria-hidden="true"][normalize-space(.)="Connect my first site"]' )->length );
		$this->assertSame( 1, $xpath->query( $cards . '/a[2]/div[@class="extra content"]/span[@class="ui basic button"][@aria-hidden="true"][normalize-space(.)="Import a CSV"]' )->length );
		$this->assertSame( 1, $xpath->query( $cards . '/a[1]//span[@class="ui top right attached green label"][normalize-space(.)="Recommended"]' )->length );
		$this->assertSame( 0, $xpath->query( $cards . '/a//a | ' . $cards . '/a//button' )->length );
		$preview = $cards . '/following-sibling::*[1][self::div][@class="mainwp-onboarding-preview"]';
		$grid    = $preview . '/div[@class="ui stackable grid"]';
		$details = $grid . '/div[@class="ten wide middle aligned column"]';
		$this->assertSame( 1, $xpath->query( $grid . '/div[@class="six wide column"]/img[@class="ui medium rounded bordered image"]' )->length );
		$this->assertSame( 1, $xpath->query( $details . '/*[1][self::p]' )->length );
		$this->assertSame( "This is what you'll see in about 3 minutes.", trim( $xpath->query( $details . '/*[1]' )->item( 0 )->textContent ) );
		$this->assertSame( 3, $xpath->query( $details . '/div[@class="ui list"]/div[@class="item"]' )->length );
		$this->assertSame( 3, $xpath->query( $details . '/div[@class="ui list"]/div[@class="item"][i[@class="green check icon"]][div[@class="content"]]' )->length );
		$this->assertSame( 0, $xpath->query( '//figure | ' . $preview . '//span | ' . $preview . '//img[@width="1200"]' )->length );
	}

	/** The quiet exit retains its destination and the CSV card describes a full login. */
	public function test_welcome_corner_and_quiet_skip_replace_walkthrough(): void {
		$html  = $this->view( 'mwp_setup_welcome' );
		$xpath = $this->html_xpath( $html );
		$this->assertStringNotContainsString( 'Walkthrough', $html );
		$this->assertStringContainsString( "Upload a CSV with each site's address and an administrator login.", html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		$skip = $xpath->query( '//a[normalize-space(.)="Skip setup, I\'ll add sites later"]' )->item( 0 );
		$this->assertNotNull( $skip );
		$this->assertSame( admin_url( 'admin.php?page=mainwp_tab' ), $skip->getAttribute( 'href' ) );
		$this->assertStringNotContainsString( 'button', $skip->getAttribute( 'class' ) );
	}

	/** Initial status and field names remain accessible before JavaScript runs. */
	public function test_connect_reserves_status_and_labels_url_input_and_protocol(): void {
		$xpath = $this->html_xpath( $this->view( 'mwp_setup_connect_first_site' ) );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-detection"][@class="ui message"][@data-state="idle"][@aria-live="polite"][not(@hidden)]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-detection-headline"][@tabindex="-1"][normalize-space(.)="We\'ll check the site for you"]' )->length );
		foreach ( array(
			'wpurl'     => 'Site URL',
			'wpadmin'   => 'Administrator username',
			'admin_pwd' => 'Administrator password',
		) as $id => $label ) {
			$this->assertSame( 1, $xpath->query( '//label[@for="mainwp_managesites_add_' . $id . '"][starts-with(normalize-space(.),"' . $label . '")]/span[normalize-space(.)="(Required)"]' )->length );
		}
		$this->assertSame( 1, $xpath->query( '//select[@id="mainwp_managesites_add_wpurl_protocol"][@aria-label="Protocol"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="How to install MainWP Child"]' )->length );
	}

	/** Both forms explain the password in place, with unique controlled regions. */
	public function test_password_reassurance_precedes_input_and_disclosures_are_inline(): void {
		$xpath = $this->html_xpath( $this->view( 'mwp_setup_connect_first_site' ) );
		foreach ( array( 'mainwp-onboarding', 'mainwp-onboarding-csv' ) as $prefix ) {
			$this->assertSame( 1, $xpath->query( '//button[@id="' . $prefix . '-password-help"][@type="button"][@aria-expanded="false"][@aria-controls="' . $prefix . '-password-explanation"]' )->length );
			$this->assertSame( 1, $xpath->query( '//*[@id="' . $prefix . '-password-explanation"][@hidden][not(@role="tooltip")][not(contains(@class,"popup"))]' )->length );
		}
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-csv-password-explanation"]/following-sibling::div//*[@id="mainwp-managesites-row-import-sites"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@id="mainwp_managesites_add_admin_pwd"]/preceding-sibling::div[contains(@class,"mainwp-onboarding-password-note")][.//strong[normalize-space(.)="Used once, then discarded."]][.//i[contains(@class,"lock")]]' )->length );
	}

	/**
	 * Inspect rendered markup without depending on whitespace.
	 *
	 * @param string $html Rendered view.
	 * @return \DOMXPath
	 */
	private function html_xpath( $html ) {
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
			$xpath   = $this->html_xpath( $html );
			$heading = 'multiple-site' === $expected ? 'Import your sites' : 'Connect your first site';
			$this->assertSame( 1, $xpath->query( '//h1[normalize-space(.)="' . $heading . '"]' )->length );
			$intro = 'multiple-site' === $expected ? "Upload a CSV with each site's address and an administrator login." : "You'll need the site's address and an administrator login.";
			$this->assertSame( $intro, trim( $xpath->query( '//h1/following-sibling::p[1]' )->item( 0 )->textContent ) );
			$this->assertSame( 1, $xpath->query( '//button[@id="mainwp_managesites_add_import"][@type="button"][@disabled][normalize-space(.)="Connect sites"]' )->length );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-timer"]/p[@id="mainwp-onboarding-time-left"]' )->length );
			$this->assertSame( 0, $xpath->query( '//div[contains(@class,"menu-connect-first-site")]' )->length );
			$this->assertStringNotContainsString( 'Import a CSV instead', $html );
			$this->assertSame( 'multiple-site' === $expected ? 1 : 0, $xpath->query( '//a[normalize-space(.)="Connect one site instead"][contains(@href,"mode=single-site")][contains(@href,"step=connect_first_site")]' )->length );
			$this->assertSame( 1, $xpath->query( '//div[@data-tab="' . $expected . '"][contains(@class,"active")]' )->length );
			$this->assertSame( 0, $xpath->query( '//div[@data-tab][contains(@class,"attached")]' )->length );
		}
	}

	/** Direct entry still offers both tabs and the original single-site default. */
	public function test_connect_direct_entry_keeps_tabs_and_status_list(): void {
		unset( $_GET['mode'] );
		$html  = $this->view( 'mwp_setup_connect_first_site' );
		$xpath = $this->html_xpath( $html );
		$menu  = '//div[contains(@class,"menu-connect-first-site")]';
		$this->assertSame( 2, $xpath->query( $menu . '/a[@data-tab]' )->length );
		$this->assertSame( 1, $xpath->query( $menu . '/a[@data-tab="single-site"][contains(@class,"active")]' )->length );
		$this->assertStringNotContainsString( 'Import a CSV instead', $html );
		$this->assertStringNotContainsString( 'Connect one site instead', $html );
		$labels = array(
			'reachable' => 'Site reachable',
			'child'     => 'MainWP Child installed and active',
		);
		foreach ( $labels as $id => $label ) {
			$row = '//div[@id="mainwp-onboarding-detection"]/div[@class="ui horizontal list"]/div[@class="item"]';
			$this->assertSame( 1, $xpath->query( $row . '/i[@id="mainwp-onboarding-' . $id . '-icon"][@class="notched circle loading icon"][@aria-hidden="true"]' )->length );
			$this->assertSame( 1, $xpath->query( $row . '/div[@class="content"][starts-with(normalize-space(.),"' . $label . '")]/span[@class="screen-reader-text"][@id="mainwp-onboarding-' . $id . '"][normalize-space(.)="Checking..."]' )->length );
		}
	}

	/** The summary celebrates connections without claiming success for an empty Dashboard. */
	public function test_summary_hero_tiles_and_footer_per_variant(): void {
		$id = $this->create_test_site( array( 'name' => 'Agency <Site>' ) );
		$this->set_site_option( $id, 'site_info', wp_json_encode( array( 'wpversion' => '6.7' ) ) );
		$this->set_site_option( $id, 'favi_icon', 'https://example.org/site-icon.png' );
		update_option(
			'mainwp_settings_notification_emails',
			array(
				'daily_digest' => array(
					'disable'    => 0,
					'recipients' => 'digest@example.org',
				),
			)
		);
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		foreach ( array(
			array( $id, 1, 'Your first site is connected' ),
			array( 0, 3, '3 sites connected' ),
			array( 0, 0, 'No sites connected yet' ),
		) as list( $site_id, $count, $heading ) ) {
			$_GET['siteid'] = $site_id;
			$property->setValue( null, $count );
			$html  = $this->view( 'mwp_setup_ready' );
			$xpath = $this->html_xpath( $html );
			$this->assertSame( $heading, trim( $xpath->query( '//h1' )->item( 0 )->textContent ) );
			$this->assertSame( $count ? 1 : 0, $xpath->query( '//*[@class="mainwp-onboarding-check"][@aria-hidden="true"]' )->length );
			$this->assertSame( $count ? 0 : 1, $xpath->query( '//*[contains(@class,"mainwp-onboarding-empty")]' )->length );
			$this->assertSame( $site_id ? 1 : 0, $xpath->query( '//*[@id="mainwp-onboarding-wordpress"]' )->length );
			$this->assertSame( $site_id ? 1 : 0, $xpath->query( '//*[@id="mainwp-onboarding-updates"]' )->length );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-tiles"]/*[@id="mainwp-onboarding-uptime"]' )->length );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-tiles"]/*[@id="mainwp-onboarding-digest"]' )->length );
			$this->assertStringNotContainsString( 'Site health:', $html );
			$this->assertStringNotContainsString( 'What&#039;s next', $html );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-next-steps"]' )->length );
			$add = $count ? 'Add another site' : 'Add your first site';
			$this->assertSame( 1, $xpath->query( '//form//a[@class="ui basic button"][normalize-space(.)="' . $add . '"][contains(@href,"do=new")]' )->length );
			$this->assertSame( 1, $xpath->query( '//form//button[@type="submit"][@class="ui green button"][normalize-space(.)="Go to Dashboard"]' )->length );
			$this->assertSame( 1, $xpath->query( '//form//*[@class="ten wide column"]//*[@id="mainwp-guided-tours-option"]' )->length );
			$this->assertSame( ! $site_id && $count ? 1 : 0, $xpath->query( '//a[contains(@href,"page=UpdatesManage")][normalize-space(.)="View updates"]' )->length );
			if ( $site_id ) {
				$this->assertStringContainsString( 'Agency &lt;Site&gt;', $html );
				$this->assertSame( 1, $xpath->query( '//*[@class="mainwp-onboarding-site"]//img[@src="https://example.org/site-icon.png"]' )->length );
				$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-site-icon"]' )->length );
				$this->assertStringContainsString( 'Up to date', $html );
				$this->assertStringContainsString( 'Nothing waiting right now', $html );
			}
		}
	}

	/** The summary link follows the same menu and permission guard as Get Started. */
	public function test_summary_ai_link_follows_mcp_availability(): void {
		global $_mainwp_disable_menus_items;
		$before                      = $_mainwp_disable_menus_items;
		$_mainwp_disable_menus_items = array();
		$_GET['siteid']              = 0;
		$hide                        = static function ( $disabled, $level, $slug ) {
			return 'ai' === $slug ? true : $disabled;
		};
		try {
			$html = $this->view( 'mwp_setup_ready' );
			$this->assertStringNotContainsString( 'Connect your AI assistant', $html );
			$this->assertStringNotContainsString( '<code>', $html );
			$this->assertStringContainsString( 'changes wait for your approval', $html );
			$this->assertStringContainsString( 'Connect Claude or Cursor', $html );
			$this->assertStringNotContainsString( 'Set up MCP', $html );
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
			$group = '//div[@id="mainwp-onboarding-next-steps"]';
			$this->assertSame( 1, $xpath->query( $group )->length );
			$this->assertSame( 0, $xpath->query( $group . '/div[@class="card"]' )->length );
			$this->assertSame( 2, $xpath->query( $group . '//a' )->length );
			$this->assertSame( 0, $xpath->query( $group . '//h3' )->length );
			$this->assertSame( 1, $xpath->query( $group . '//a[normalize-space(.)="Connect Claude or Cursor"][contains(@href,"admin.php?page=mainwp-mcp")]' )->length );
			add_filter( 'mainwp_is_disable_menu_item', $hide, 10, 3 );
			$html  = $this->view( 'mwp_setup_ready' );
			$xpath = $this->html_xpath( $html );
			$this->assertStringNotContainsString( 'Connect your AI assistant', $html );
			$this->assertSame( 1, $xpath->query( $group )->length );
			$this->assertSame( 1, $xpath->query( $group . '//a' )->length );
			remove_filter( 'mainwp_is_disable_menu_item', $hide, 10 );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$html  = $this->view( 'mwp_setup_ready' );
			$xpath = $this->html_xpath( $html );
			$this->assertStringNotContainsString( 'Connect your AI assistant', $html );
			$this->assertSame( 1, $xpath->query( $group )->length );
			$this->assertSame( 1, $xpath->query( $group . '//a' )->length );
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
		$html = $this->widget();
		$this->assertMatchesRegularExpression( '/<i class="green check circle icon" data-celebrate="true"><\/i>\s*<div class="content">Run your first update<\/div>/', $html );
		$this->assertStringNotContainsString( 'tadaaaa', $html );
		$this->assertContains( 'first_update', get_user_meta( $first, 'mainwp_onboarding_seen_milestones', true ) );
		$this->assertStringNotContainsString( 'data-celebrate=', $this->widget() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertMatchesRegularExpression( '/<i class="green check circle icon" data-celebrate="true"><\/i>\s*<div class="content">Run your first update<\/div>/', $this->widget() );
		$this->assertStringNotContainsString( 'data-celebrate=', $this->widget() );
		wp_set_current_user( $first );
		delete_option( 'mainwp_onboarding_first_update' );
		$this->widget();
		update_option( 'mainwp_onboarding_first_update', 1 );
		$this->assertStringNotContainsString( 'data-celebrate=', $this->widget() );
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
