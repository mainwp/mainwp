<?php
/**
 * Onboarding cohort, summary, navigation, and checklist regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Get_Started;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\MainWP_Overview;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Setup_Wizard;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Edit;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Status;

/**
 * Exercises production helpers without booting the wizard's exit-driven page.
 */
class Test_Onboarding_Phase_One extends \WP_UnitTestCase {

	/**
	 * Wizard instance.
	 *
	 * @var MainWP_Setup_Wizard
	 */
	private $wizard;

	/**
	 * Saved request.
	 *
	 * @var array
	 */
	private $request;

	/**
	 * Cached site count.
	 *
	 * @var mixed
	 */
	private $site_count;

	/**
	 * Previous user.
	 *
	 * @var int
	 */
	private $previous_user;

	/** Prepare an administrator and isolate request state. */
	public function setUp(): void {
		parent::setUp();
		$this->previous_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->request = array( $_GET, $_POST, $_REQUEST );
		$_GET          = array();
		$_POST         = array();
		$_REQUEST      = array();
		$this->wizard  = ( new \ReflectionClass( MainWP_Setup_Wizard::class ) )->newInstanceWithoutConstructor();
		$property      = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$this->site_count = $property->getValue();
		$property->setValue( null, 0 );
		foreach ( array( 'fresh_install', 'defaults_applied', 'first_update', 'mcp_visited', 'uptime_confirmed' ) as $flag ) {
			delete_option( 'mainwp_onboarding_' . $flag );
		}
		delete_option( 'mainwp_disableSitesHealthMonitoring' );
		delete_option( 'mainwp_sitehealthThreshold' );
		update_option( 'mainwp_global_uptime_monitoring_settings', MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false ) );
	}

	/** Restore cached counts and the caller's request. */
	public function tearDown(): void {
		list( $_GET, $_POST, $_REQUEST ) = $this->request;
		$property                        = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$property->setValue( null, $this->site_count );
		wp_set_current_user( $this->previous_user );
		parent::tearDown();
	}

	/**
	 * Invoke a private helper without changing its production visibility.
	 *
	 * @param string $name Helper name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke( $name, $args = array() ) {
		$method = new \ReflectionMethod( MainWP_Setup_Wizard::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->wizard, $args );
	}

	/**
	 * The same PHP, extensions, SSL, MySQL and OpenSSL checks gate the silent path.
	 *
	 * @dataProvider system_checks
	 * @param array $overrides Detected environment values.
	 * @param bool  $expected Whether all checks pass.
	 */
	public function test_system_check_aggregator( $overrides, $expected ): void {
		$wizard         = ( new \ReflectionClass( Onboarding_System_Check_Fixture::class ) )->newInstanceWithoutConstructor();
		$wizard->checks = array_merge(
			array(
				'php'             => '8.3.0',
				'ssl'             => true,
				'curl'            => true,
				'curl_ssl'        => true,
				'mysql'           => '8.0',
				'openssl_warning' => false,
			),
			$overrides
		);
		$this->assertSame( $expected, $wizard->passes() );
		update_user_option( get_current_user_id(), 'mainwp_selected_theme', 'default' );
		ob_start();
		$wizard->mwp_setup_welcome();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'step=' . ( $expected ? 'connect_first_site' : 'system_check' ), $html );
		$this->assertStringNotContainsString( 'mainwp-guided-tours-option', $html );
	}

	/**
	 * Environment boundaries.
	 *
	 * @return array
	 */
	public static function system_checks() {
		return array(
			'all pass'     => array( array(), true ),
			'old PHP'      => array( array( 'php' => '8.2.99' ), false ),
			'no SSL'       => array( array( 'ssl' => false ), false ),
			'no curl'      => array(
				array(
					'curl'     => false,
					'curl_ssl' => false,
				),
				false,
			),
			'old curl SSL' => array( array( 'curl_ssl' => false ), false ),
			'old MySQL'    => array( array( 'mysql' => '4.9' ), false ),
			'OpenSSL path' => array( array( 'openssl_warning' => true ), false ),
		);
	}

	/**
	 * Each prerequisite independently hides a notice that would otherwise be actionable.
	 *
	 * @dataProvider monitoring_notice_conditions
	 * @param bool $fresh Fresh-install marker.
	 * @param bool $applied Defaults already applied.
	 * @param bool $active Global monitoring enabled.
	 * @param int  $sites Connected sites.
	 * @param bool $allowed Settings right.
	 * @param bool $dismissed Notice dismissed by this user.
	 */
	public function test_monitoring_notice_condition_matrix( $fresh, $applied, $active, $sites, $allowed, $dismissed ): void {
		if ( $fresh ) {
			update_option( 'mainwp_onboarding_fresh_install', time() );
		} else {
			delete_option( 'mainwp_onboarding_fresh_install' );
		}
		if ( $applied ) {
			update_option( 'mainwp_onboarding_defaults_applied', time() );
		} else {
			delete_option( 'mainwp_onboarding_defaults_applied' );
		}
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = (int) $active;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_settings_notification_emails', array( 'uptime' => array( 'recipients' => 'alerts@example.org<script>bad()</script>' ) ) );
		update_user_option( get_current_user_id(), 'mainwp_notice_saved_status', $dismissed ? array( 'onboarding-monitoring' => 1 ) : array() );
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$property->setValue( null, $sites );
		$right = static function ( $can, $type, $name ) use ( $allowed ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $name ? $allowed : $can;
		};
		add_filter( 'mainwp_currentusercan', $right, 999, 3 );
		$method = new \ReflectionMethod( MainWP_Overview::class, 'render_onboarding_monitoring_notice' );
		$method->setAccessible( true );
		ob_start();
		try {
			$method->invoke( null );
		} finally {
			$html = ob_get_clean();
			remove_filter( 'mainwp_currentusercan', $right, 999 );
		}
		$visible = $fresh && ! $applied && ! $active && $sites && $allowed && ! $dismissed;
		if ( ! $visible ) {
			$this->assertSame( '', $html );
			return;
		}
		$this->assertStringContainsString( 'id="mainwp-onboarding-monitoring-notice"', $html );
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 1, $xpath->query( '//i[@notice-id="onboarding-monitoring"][contains(@class,"mainwp-notice-dismiss")]' )->length );
		$this->assertSame( 1, $xpath->query( '//button[@class="ui mini green button"][normalize-space(.)="Turn on uptime monitoring"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@class="ui mini basic button"][normalize-space(.)="Monitoring settings"]' )->length );
		$this->assertSame( admin_url( 'admin.php?page=MonitoringSettings' ), $xpath->query( '//a/@href' )->item( 0 )->value );
		$this->assertNotFalse( wp_verify_nonce( $xpath->query( '//button/@data-nonce' )->item( 0 )->value, 'mainwp_onboarding_enable_monitoring' ) );
		$this->assertStringContainsString( 'Uptime monitoring is off. Turn it on and MainWP emails alerts@example.org&lt;script&gt;bad()&lt;/script&gt; when a site goes down.', $html );
		$this->assertSame( 0, $xpath->query( '//script' )->length );
	}

	/** The notice must not promise emails that the uptime notification settings turn off. */
	public function test_monitoring_notice_copy_respects_disabled_email_alerts(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		update_user_option( get_current_user_id(), 'mainwp_notice_saved_status', array() );
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$property->setValue( null, 1 );
		$method = new \ReflectionMethod( MainWP_Overview::class, 'render_onboarding_monitoring_notice' );
		$method->setAccessible( true );
		$cases = array(
			array( 0, 'alerts@example.org' ),
			array( 1, 'alerts@example.org' ),
			array( 0, '' ),
		);
		foreach ( $cases as list( $disable, $recipients ) ) {
			$disabled = $disable || '' === $recipients;
			update_option(
				'mainwp_settings_notification_emails',
				array(
					'uptime' => array(
						'disable'    => $disable,
						'recipients' => $recipients,
					),
				)
			);
			ob_start();
			try {
				$method->invoke( null );
			} finally {
				$html = ob_get_clean();
			}
			$xpath    = $this->html_xpath( $html );
			$expected = $disabled ? 'Uptime monitoring is off. Turn it on to check your sites for downtime.' : 'Uptime monitoring is off. Turn it on and MainWP emails alerts@example.org when a site goes down.';
			$this->assertSame( $expected, trim( $xpath->query( '//div[@id="mainwp-onboarding-monitoring-notice"]/p[1]' )->item( 0 )->textContent ) );
			if ( $disabled ) {
				$this->assertStringNotContainsString( 'alerts@example.org', $html );
			}
		}
	}

	/**
	 * Cover all combinations, including overlapping reasons to hide the notice.
	 *
	 * @return array
	 */
	public static function monitoring_notice_conditions() {
		$cases = array();
		for ( $mask = 0; 64 > $mask; ++$mask ) {
			$cases[ 'conditions-' . $mask ] = array( (bool) ( $mask & 1 ), (bool) ( $mask & 2 ), (bool) ( $mask & 4 ), (int) (bool) ( $mask & 8 ), (bool) ( $mask & 16 ), (bool) ( $mask & 32 ) );
		}
		return $cases;
	}

	/**
	 * Capture JSON termination without bypassing the production nonce or rights checks.
	 *
	 * @param string $nonce Action nonce.
	 * @param string $action Monitoring action.
	 * @return array JSON response.
	 */
	private function monitoring_ajax( $nonce, $action = 'enable' ) {
		$_REQUEST = array( 'security' => $nonce );
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'onboarding-json-stop' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Handler::instance()->{ 'ajax_onboarding_' . $action . '_monitoring' }();
			$this->fail( 'The handler must terminate with JSON.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'onboarding-json-stop', $exception->getMessage() );
		} finally {
			$json = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		$response = json_decode( $json, true );
		$this->assertIsArray( $response, $json );
		return $response;
	}

	/** Notice activation must preserve settings until both authorization checks pass. */
	public function test_monitoring_notice_handler_rejects_invalid_nonce_and_missing_right(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$before = get_option( 'mainwp_global_uptime_monitoring_settings' );
		foreach ( array( '', 'invalid', wp_create_nonce( 'another-action' ) ) as $nonce ) {
			$this->assertFalse( $this->monitoring_ajax( $nonce )['success'] );
		}
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		try {
			$this->assertFalse( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_enable_monitoring' ) )['success'] );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_enable_monitoring' ) )['success'] );
		$this->assertSame( $before, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/** Existing installs cannot acquire onboarding defaults through a stale notice request. */
	public function test_monitoring_notice_handler_rejects_existing_install(): void {
		$before = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->assertFalse( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_enable_monitoring' ) )['success'] );
		$this->assertSame( $before, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/** A repeated click must neither reapply defaults nor undo later monitoring choices. */
	public function test_monitoring_notice_handler_applies_once_and_confirms(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$nonce    = wp_create_nonce( 'mainwp_onboarding_enable_monitoring' );
		$response = $this->monitoring_ajax( $nonce );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Uptime monitoring is on.', $response['data']['message'] );
		$settings = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->assertSame( 1, (int) $settings['active'] );
		$this->assertSame( 60, (int) $settings['interval'] );
		$this->assertGreaterThan( 0, (int) get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_onboarding_uptime_confirmed' ) );
		$this->assertTrue( MainWP_Get_Started::get_items()[3]['complete'] );
		$settings['interval'] = 15;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_sitehealthThreshold', 100 );
		$this->assertTrue( $this->monitoring_ajax( $nonce )['success'] );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertSame( 100, (int) get_option( 'mainwp_sitehealthThreshold' ) );
		$settings['active'] = 0;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		delete_option( 'mainwp_onboarding_uptime_confirmed' );
		$this->assertFalse( $this->monitoring_ajax( $nonce )['success'] );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/** Absent tour settings default off; saved values survive summary rendering. */
	public function test_summary_guided_tours_default_and_saved_values(): void {
		$_GET = array( 'siteid' => 0 );
		foreach ( array( null, 0, 1 ) as $saved ) {
			if ( null === $saved ) {
				delete_option( 'mainwp_enable_guided_tours' );
			} else {
				update_option( 'mainwp_enable_guided_tours', $saved );
			}
			ob_start();
			$this->wizard->mwp_setup_ready();
			$xpath = $this->html_xpath( ob_get_clean() );
			$this->assertSame( 1 === $saved ? 1 : 0, $xpath->query( '//input[@id="mainwp-guided-tours-option"][@checked]' )->length );
			$this->assertSame( null === $saved ? false : $saved, null === $saved ? get_option( 'mainwp_enable_guided_tours' ) : (int) get_option( 'mainwp_enable_guided_tours' ) );
		}
	}

	/** Exit destinations stay outside the action grid so they do not compete with step actions. */
	public function test_phase_one_footer_and_card_markup(): void {
		update_user_option( get_current_user_id(), 'mainwp_selected_theme', 'default' );
		$_GET = array(
			'page'   => 'mainwp-setup',
			'siteid' => 0,
		);
		foreach ( array( 'mwp_setup_welcome', 'mwp_setup_connect_first_site', 'mwp_setup_ready' ) as $view ) {
			ob_start();
			$this->wizard->$view();
			$this->wizard->setup_wizard_footer();
			$html  = ob_get_clean();
			$xpath = $this->html_xpath( $html );
			$this->assertSame( 1, $xpath->query( '//div[@class="ui padded segment"]' )->length, $view );
			$footer = '//div[@class="ui divider"]/following-sibling::div[1][@class="ui stackable grid"]';
			$this->assertSame( 1, $xpath->query( $footer . '/div[@class="ten wide column"]' )->length, $view );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-qsw-exit-links"]' )->length, $view );
			$this->assertSame( 0, $xpath->query( $footer . '//*[@id="mainwp-qsw-exit-links"]' )->length, $view );
			$this->assertSame( 1, $xpath->query( '//div[@class="ui padded segment"]/following-sibling::*[@id="mainwp-qsw-exit-links"]' )->length, $view );
			$this->assertSame( 0, $xpath->query( $footer . '//*[contains(@class,"mini") or contains(@class,"floated")]' )->length, $view );
			$primary_count = 'mwp_setup_welcome' === $view ? 0 : ( 'mwp_setup_connect_first_site' === $view ? 2 : 1 );
			$this->assertSame( $primary_count, $xpath->query( $footer . '/div[@class="six wide right aligned column"]/*[@class="ui green button"]' )->length, $view );
			if ( 'mwp_setup_welcome' === $view ) {
				$this->assertSame( 2, $xpath->query( '//div[@class="ui two stackable link cards"]/a[@class="ui card"]' )->length );
				$this->assertSame( 0, $xpath->query( '//a[normalize-space(.)="Get started"]' )->length );
			}
			if ( 'mwp_setup_connect_first_site' === $view ) {
				$this->assertSame( 1, $xpath->query( $footer . '/div[@class="ten wide column"]/a[@id="mainwp_addsite_continue_button"][@class="ui basic button"]' )->length );
			}
		}
		$this->assertSame( 3, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"][@class="ui three doubling stackable cards"]/div[@class="card"][div[@class="content"]/div[@class="description"]]' )->length );
		$this->assertStringContainsString( 'Connect your WordPress sites to manage them together.', $html );
		$this->assertStringContainsString( 'Group sites by client for reports and billing.', $html );
		$this->assertStringContainsString( 'https://www.usetiful.com/privacy-policy', $html );
		$this->assertSame( 1, $xpath->query( '//div[@class="field"]/div[input[@id="mainwp-guided-tours-option"]]/following-sibling::span[@id="mainwp-onboarding-tour-help"][@class="ui small text"]/a[@href="https://www.usetiful.com/privacy-policy"]' )->length );
	}

	/** Existing installs receive neither monitoring defaults nor a widget. */
	public function test_existing_install_is_unchanged(): void {
		$before = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->invoke( 'apply_monitoring_defaults' );
		$this->assertSame( $before, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_disableSitesHealthMonitoring' ) );
		$this->assertFalse( get_option( 'mainwp_sitehealthThreshold' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertFalse( MainWP_Get_Started::is_visible() );
	}

	/** A fresh summary enables monitoring once and leaves later edits alone. */
	public function test_fresh_defaults_apply_once(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$site = $this->site();
		$db   = MainWP_DB_Uptime_Monitoring::instance();
		$db->delete_site_monitors( $site->id );
		$this->assertEmpty( $db->get_monitor_by( $site->id, 'issub', 0 ) );
		$this->invoke( 'apply_monitoring_defaults' );
		$this->assertNotEmpty( $db->get_monitor_by( $site->id, 'issub', 0 ) );
		$settings = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->assertSame( 1, (int) $settings['active'] );
		$this->assertSame( 60, (int) $settings['interval'] );
		$this->assertSame( 0, (int) get_option( 'mainwp_disableSitesHealthMonitoring' ) );
		$this->assertSame( 80, (int) get_option( 'mainwp_sitehealthThreshold' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
		$this->assertFalse( MainWP_Get_Started::get_items()[3]['complete'] );
		$settings['active'] = 0;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_sitehealthThreshold', 100 );
		$this->invoke( 'apply_monitoring_defaults' );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertSame( 100, (int) get_option( 'mainwp_sitehealthThreshold' ) );
	}

	/** Explicit settings survive the first summary. */
	public function test_user_monitoring_values_are_preserved(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$settings             = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active']   = 1;
		$settings['interval'] = 15;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_disableSitesHealthMonitoring', 1 );
		update_option( 'mainwp_sitehealthThreshold', 100 );
		$this->invoke( 'apply_monitoring_defaults' );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_disableSitesHealthMonitoring' ) );
		$this->assertSame( 100, (int) get_option( 'mainwp_sitehealthThreshold' ) );
	}

	/** Settings defaults require the same right as explicit saves. */
	public function test_defaults_require_settings_right(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		try {
			$this->invoke( 'apply_monitoring_defaults' );
			$this->assertFalse( get_option( 'mainwp_onboarding_defaults_applied' ) );
			$this->assertSame( 0, (int) get_option( 'mainwp_global_uptime_monitoring_settings' )['active'] );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
	}

	/** An invalid nonce cannot turn uptime off or change tours. */
	public function test_summary_save_rejects_invalid_nonce(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		update_option( 'mainwp_enable_guided_tours', 0 );
		$_REQUEST = array( '_wpnonce' => 'invalid' );
		$_POST    = array(
			'mainwp-guided-tours-option'          => '1',
			'mainwp_setup_enableUptimeMonitoring' => '1',
		);
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'nonce rejected' );
			};
		};
		add_filter( 'wp_die_handler', $die );
		try {
			$this->wizard->mwp_setup_ready_save();
			$this->fail( 'The nonce must stop the save.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'nonce rejected', $error->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $die );
		}
		$this->assertSame( 0, (int) get_option( 'mainwp_enable_guided_tours' ) );
		$this->assertSame( 0, (int) get_option( 'mainwp_global_uptime_monitoring_settings' )['active'] );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/** Leaving the summary needs no settings right and must preserve settings. */
	public function test_summary_save_without_settings_right_redirects_without_writing(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		update_option( 'mainwp_enable_guided_tours', 0 );
		$settings = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$deny     = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		$redirect = static function ( $location ) {
			throw new \RuntimeException( $location );
		};
		$_POST    = array(
			'mainwp-guided-tours-option'          => '1',
			'mainwp_setup_enableUptimeMonitoring' => '1',
		);
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		add_filter( 'wp_redirect', $redirect );
		try {
			try {
				$this->wizard->mwp_setup_ready_save();
				$this->fail( 'Go to Dashboard must redirect.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( admin_url( 'admin.php?page=mainwp_tab' ), $error->getMessage() );
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
		$this->assertSame( 0, (int) get_option( 'mainwp_enable_guided_tours' ) );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/**
	 * Continuing saves the tour choice without changing monitoring.
	 *
	 * @dataProvider monitoring_choices
	 * @param bool $active Stored monitoring choice.
	 */
	public function test_summary_save_preserves_monitoring_and_saves_only_tours( $active ): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$settings           = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
		$settings['active'] = (int) $active;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'mwp-setup' ) );
		$_POST    = array( 'mainwp-guided-tours-option' => '1' );
		$redirect = static function ( $location ) {
			throw new \RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			$this->wizard->mwp_setup_ready_save();
			$this->fail( 'The summary must redirect after saving.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( admin_url( 'admin.php?page=mainwp_tab' ), $error->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enable_guided_tours' ) );
		$this->assertSame( (bool) $active, (bool) get_option( 'mainwp_onboarding_uptime_confirmed' ) );
	}

	/**
	 * Monitoring toggle boundaries.
	 *
	 * @return array
	 */
	public static function monitoring_choices() {
		return array(
			'on'  => array( true ),
			'off' => array( false ),
		);
	}

	/**
	 * Only a fresh install's authorized, nonce-checked active settings save confirms alerts.
	 *
	 * @dataProvider monitoring_saves
	 * @param int  $active Submitted monitoring state.
	 * @param bool $fresh Fresh-install cohort.
	 * @param bool $nonce Valid nonce.
	 * @param bool $right Settings right.
	 * @param bool $expected Expected confirmation.
	 */
	public function test_monitoring_settings_save_records_confirmation( $active, $fresh, $nonce, $right, $expected ): void {
		if ( $fresh ) {
			update_option( 'mainwp_onboarding_fresh_install', time() );
		}
		$_POST      = array(
			'wp_nonce_uptime_settings'   => $nonce ? wp_create_nonce( 'UpdateMonitorSettings' ) : 'invalid',
			'mainwp_edit_monitor_active' => $active,
		);
		$permission = static function ( $allowed, $type, $name ) use ( $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $name ? $right : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $permission, 999, 3 );
		try {
			MainWP_Uptime_Monitoring_Edit::instance()->handle_save_settings();
		} finally {
			remove_filter( 'mainwp_currentusercan', $permission, 999 );
		}
		$this->assertSame( $expected, (bool) get_option( 'mainwp_onboarding_uptime_confirmed' ) );
		$this->assertSame( $expected, MainWP_Get_Started::get_items()[3]['complete'] );
	}

	/**
	 * Global settings confirmation requires an explicit authorized choice.
	 *
	 * @return array
	 */
	public static function monitoring_saves() {
		return array(
			'confirmed on'  => array( 1, true, true, true, true ),
			'saved off'     => array( 0, true, true, true, false ),
			'invalid nonce' => array( 1, true, false, true, false ),
			'no right'      => array( 1, true, true, false, false ),
			'old install'   => array( 1, false, true, true, false ),
		);
	}

	/**
	 * Create a site without connecting to a Child or generating connection keys.
	 *
	 * @return object
	 */
	private function site() {
		$id = MainWP_DB::instance()->add_website(
			get_current_user_id(),
			'Onboarding',
			'https://onboarding.example.org/',
			'admin',
			'',
			'',
			array(
				'http_user' => '',
				'http_pass' => '',
			)
		);
		$this->assertGreaterThan( 0, $id );
		return MainWP_DB::instance()->get_website_by_id( $id );
	}

	/** A missing synced version must not leave an empty WordPress row. */
	public function test_summary_without_wordpress_version_omits_version_row(): void {
		$site = $this->site();
		MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( array() ) );
		$_GET = array( 'siteid' => $site->id );
		ob_start();
		try {
			$this->wizard->mwp_setup_ready();
		} finally {
			$html = ob_get_clean();
		}
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 0, $xpath->query( '//div[@class="ui relaxed list"]/div[@class="item"][starts-with(normalize-space(.),"WordPress ")]' )->length );
		$this->assertStringContainsString( 'Updates and health will show here after the first successful sync.', $html );
		$this->assertStringNotContainsString( 'Everything is up to date', $html );
		$this->assertStringNotContainsString( 'Site health:', $html );
		$this->assertStringContainsString( 'Uptime monitoring is off.', $html );
		$this->assertSame( 0, $xpath->query( '//button[@id="mainwp-onboarding-update-all"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="View updates"]' )->length );
	}

	/** Stale updates never appear as actionable results before a successful sync. */
	public function test_summary_without_sync_hides_stale_updates_and_health(): void {
		$site = $this->site();
		MainWP_DB::instance()->update_website_values(
			$site->id,
			array( 'plugin_upgrades' => wp_json_encode( array( 'example/plugin.php' => array( 'Name' => 'Example' ) ) ) )
		);
		$_GET = array( 'siteid' => $site->id );
		foreach ( array( false, true ) as $sync_error ) {
			MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( $sync_error ? array( 'wpversion' => '6.7' ) : array() ) );
			MainWP_DB::instance()->update_website_sync_values( $site->id, array( 'sync_errors' => $sync_error ? 'Connection failed' : '' ) );
			ob_start();
			try {
				$this->wizard->mwp_setup_ready();
			} finally {
				$html = ob_get_clean();
			}
			$this->assertStringContainsString( 'Updates and health will show here after the first successful sync.', $html );
			$xpath = $this->html_xpath( $html );
			$this->assertSame( 0, $xpath->query( '//button[@id="mainwp-onboarding-update-all"]' )->length );
			$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="View updates"]' )->length );
			$this->assertStringNotContainsString( 'Everything is up to date', $html );
			$this->assertStringNotContainsString( 'Site health:', $html );
			$this->assertStringContainsString( 'Uptime monitoring is off.', $html );
		}
	}

	/**
	 * Counts and the update confirmation use the same singular or plural segments.
	 *
	 * @dataProvider update_counts
	 * @param int    $count Updates of each type.
	 * @param string $expected Expected count text.
	 */
	public function test_summary_pluralizes_update_counts_and_confirmation( $count, $expected ): void {
		$site = $this->site();
		MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( array( 'wpversion' => '6.7' ) ) );
		$plugins = array();
		$themes  = array();
		$trans   = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$plugins[ 'example' . $index . '/plugin.php' ] = array( 'Name' => 'Example' );
			$themes[ 'example' . $index ]                  = array( 'Name' => 'Example' );
			$trans[]                                       = array( 'slug' => 'example' . $index );
		}
		MainWP_DB::instance()->update_website_values(
			$site->id,
			array(
				'plugin_upgrades'      => wp_json_encode( $plugins ),
				'theme_upgrades'       => wp_json_encode( $themes ),
				'translation_upgrades' => wp_json_encode( $trans ),
			)
		);
		update_option( 'mainwp_show_language_updates', 1 );
		$_GET = array( 'siteid' => $site->id );
		foreach ( array( 0, 1 ) as $hide ) {
			update_option( 'mainwp_hide_update_everything', $hide );
			ob_start();
			try {
				$this->wizard->mwp_setup_ready();
			} finally {
				$html = ob_get_clean();
			}
			$xpath = $this->html_xpath( $html );
			$this->assertStringContainsString( $count ? sprintf( '%d updates waiting', 3 * $count ) : 'Everything is up to date', $html );
			$button = $xpath->query( '//button[@id="mainwp-onboarding-update-all"]' );
			$this->assertSame( $count && ! $hide ? 1 : 0, $button->length );
			if ( $button->length ) {
				$this->assertStringContainsString( 'This installs ' . $expected . '.', $button->item( 0 )->getAttribute( 'data-confirm' ) );
			}
			$label = $count && ! $hide ? 'Review updates first' : 'View updates';
			// An up-to-date site has nothing to review.
			$this->assertSame( $count ? 1 : 0, $xpath->query( '//a[normalize-space(.)="' . $label . '"]' )->length );
		}
	}

	/**
	 * Zero, singular and plural update text.
	 *
	 * @return array
	 */
	public static function update_counts() {
		return array(
			'zero'     => array( 0, 'Everything is up to date' ),
			'singular' => array( 1, '1 plugin update, 1 theme update, 1 translation update' ),
			'plural'   => array( 2, '2 plugin updates, 2 theme updates, 2 translation updates' ),
		);
	}

	/** The multi-site summary heading follows the connected-site count. */
	public function test_summary_without_site_pluralizes_connected_count(): void {
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		$_GET = array( 'siteid' => 0 );
		foreach ( array(
			0 => 'No sites connected yet',
			1 => '1 site connected',
			3 => '3 sites connected',
		) as $count => $heading ) {
			$property->setValue( null, $count );
			ob_start();
			try {
				$this->wizard->mwp_setup_ready();
			} finally {
				$html = ob_get_clean();
			}
			$xpath = $this->html_xpath( $html );
			$this->assertSame( $heading, trim( $xpath->query( '//h1' )->item( 0 )->textContent ) );
			$this->assertSame( $count ? 0 : 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]//div[@class="header"][normalize-space(.)="Add your first site"]' )->length );
			$this->assertSame( $count ? 1 : 0, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]//div[@class="header"][normalize-space(.)="Add another site"]' )->length );
			$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="Add a site"]' )->length );
			$this->assertSame( $count ? 1 : 0, $xpath->query( '//a[normalize-space(.)="View updates"]' )->length );
			$client_header = $count ? 'Assign sites to a client' : 'Add your first client';
			$client_button = $count ? 'Assign to a client' : 'Add a client';
			$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]//div[@class="header"][normalize-space(.)="' . $client_header . '"]' )->length );
			$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="' . $client_button . '"]' )->length );
			$client_url = $count ? add_query_arg( 'siteid', 0, $this->invoke( 'get_next_step_link', array( 'add_client' ) ) ) : admin_url( 'admin.php?page=ClientAddNew' );
			$this->assertSame( $client_url, $xpath->query( '//a[normalize-space(.)="' . $client_button . '"]/@href' )->item( 0 )->value );
			$this->assertStringNotContainsString( 'Assign this site to a client', $html );
			$this->assertStringNotContainsString( 'Review updates first', $html );
		}
		$property->setValue( null, null );
	}

	/** Without a selected site, the single-client form assigns the only site; with more it assigns none. */
	public function test_add_client_without_selection_assigns_only_site(): void {
		$only = $this->site();
		foreach ( array( (string) $only->id, '0' ) as $expected ) {
			$_GET = array( 'siteid' => 0 );
			ob_start();
			try {
				$this->wizard->mwp_setup_add_client();
			} finally {
				$html = ob_get_clean();
			}
			$xpath = $this->html_xpath( $html );
			$this->assertSame( $expected, $xpath->query( '//input[@name="selected_first_site"]/@value' )->item( 0 )->value );
			$this->site();
		}
	}

	/** The multi-client form must assign the site chosen from the summary. */
	public function test_add_client_limits_multi_client_rows_to_selected_site(): void {
		$this->site();
		$selected = $this->site();
		MainWP_DB::instance()->update_website_values( $selected->id, array( 'url' => 'https://z-selected.example.org/' ) );
		$sites = MainWP_DB::instance()->get_sites();
		$this->assertNotSame( (int) $selected->id, (int) $sites[0]['id'] );
		$_GET = array( 'siteid' => $selected->id );
		ob_start();
		try {
			$this->wizard->mwp_setup_add_client();
		} finally {
			$html = ob_get_clean();
		}
		$xpath = $this->html_xpath( $html );
		$ids   = $xpath->query( '//input[starts-with(@id,"mainwp-qsw-add-client-website-id-")]/@value' );
		$this->assertSame( 1, $ids->length );
		$this->assertSame( (string) $selected->id, $ids->item( 0 )->value );
		$this->assertSame( 'https://z-selected.example.org/', $xpath->query( '//input[@id="mainwp-qsw-add-client-site-url-0"]/@value' )->item( 0 )->value );
	}

	/**
	 * Site overrides affect the uptime row while the checkbox retains the global value.
	 *
	 * @dataProvider uptime_states
	 * @param int      $global_active Global setting.
	 * @param int|null $site_active Primary monitor setting, or null for no monitor.
	 * @param bool     $expected Effective state.
	 */
	public function test_summary_uptime_uses_effective_monitor_state( $global_active, $site_active, $expected ): void {
		$site = $this->site();
		$db   = MainWP_DB_Uptime_Monitoring::instance();
		$db->delete_site_monitors( $site->id );
		if ( null !== $site_active ) {
			$this->assertGreaterThan(
				0,
				$db->update_wp_monitor(
					array(
						'wpid'   => $site->id,
						'active' => $site_active,
					)
				)
			);
		}
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = $global_active;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_settings_notification_emails', array( 'uptime' => array( 'disable' => 1 ) ) );
		$_GET = array( 'siteid' => $site->id );
		ob_start();
		try {
			$this->wizard->mwp_setup_ready();
		} finally {
			$html = ob_get_clean();
		}
		$this->assertStringContainsString( $expected ? 'Uptime monitoring is on. Email alerts are disabled.' : 'Uptime monitoring is off.', $html );
		$this->assertStringNotContainsString( $expected ? 'Uptime monitoring is off.' : 'Uptime monitoring is on.', $html );
		$xpath = $this->html_xpath( $html );
		$this->assertSame( $global_active, $xpath->query( '//a[@id="mainwp-onboarding-disable-monitoring"]' )->length );
	}

	/**
	 * Explicit settings override global uptime, inherited monitors follow it, and a site without a monitor is not checked.
	 *
	 * @return array
	 */
	public static function uptime_states() {
		return array(
			'site off, global on'    => array( 1, 0, false ),
			'site off, global off'   => array( 0, 0, false ),
			'site on, global on'     => array( 1, 1, true ),
			'site on, global off'    => array( 0, 1, true ),
			'inherited, global on'   => array( 1, -1, true ),
			'inherited, global off'  => array( 0, -1, false ),
			'no monitor, global on'  => array( 1, null, false ),
			'no monitor, global off' => array( 0, null, false ),
		);
	}

	/** Import titles are translated, so only the request identifies wizard navigation. */
	public function test_import_modal_uses_request_page_for_wizard_mode(): void {
		$url = 'admin.php?page=managesites&do=import';
		foreach ( array( 'managesites', 'mainwp-setup' ) as $page ) {
			$_GET = array( 'page' => $page );
			ob_start();
			try {
				MainWP_Manage_Sites::render_import_sites_modal( $url, 'Import Sites' );
			} finally {
				$html = ob_get_clean();
			}
			$xpath     = $this->html_xpath( $html );
			$is_wizard = 'mainwp-setup' === $page ? 1 : 0;
			$this->assertSame( $url, $xpath->query( '//*[@id="mainwp-import-sites-modal"]/@data-page-url' )->item( 0 )->value );
			$this->assertSame( $is_wizard, $xpath->query( '//a[@id="mainwp-import-sites-modal-try-again"]' )->length );
			$this->assertSame( $is_wizard, $xpath->query( '//a[@id="mainwp-import-sites-modal-continue"]' )->length );
			$this->assertSame( $is_wizard, $xpath->query( '//*[@id="mainwp-import-sites-modal"]/i[contains(@style,"display:none")]' )->length );
			$this->assertStringContainsString( "location.href = '" . $url . "'", $html );
		}
	}

	/** The uptime row must not abort rendering the summary's remaining actions. */
	public function test_active_uptime_summary_renders_all_actions(): void {
		$site = $this->site();
		MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( array( 'wpversion' => '6.7' ) ) );
		MainWP_DB::instance()->update_website_values(
			$site->id,
			array(
				'plugin_upgrades' => wp_json_encode(
					array(
						'example/plugin.php' => array(
							'Name'   => 'Example',
							'update' => array(
								'slug'        => 'example',
								'new_version' => '2.0',
							),
						),
					)
				),
			)
		);
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option(
			'mainwp_settings_notification_emails',
			array(
				'uptime' => array(
					'disable'    => 0,
					'recipients' => 'alerts@example.org',
				),
			)
		);
		update_option( 'mainwp_hide_update_everything', 0 );
		$_GET = array(
			'page'   => 'mainwp-setup',
			'step'   => 'next_steps',
			'siteid' => $site->id,
		);
		ob_start();
		try {
			$this->wizard->mwp_setup_ready();
		} finally {
			$html = ob_get_clean();
		}
		$this->assertStringContainsString( "Uptime monitoring is on. We'll email alerts@example.org if Onboarding goes down.", html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 1, $xpath->query( '//button[@id="mainwp-onboarding-update-all"][normalize-space(.)="Update everything now"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="Review updates first"]' )->length );
		$this->assertSame( admin_url( 'admin.php?page=managesites&updateid=' . $site->id ), $xpath->query( '//a[normalize-space(.)="Review updates first"]/@href' )->item( 0 )->value );
		$this->assertSame( 0, $xpath->query( '//input[@id="mainwp_setup_enableUptimeMonitoring"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//button[@type="submit"][normalize-space(.)="Go to Dashboard"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//button[@type="submit"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[@id="mainwp-onboarding-uptime"]/a[@id="mainwp-onboarding-disable-monitoring"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@class="field"]/div[@class="ui checkbox"]/input[@id="mainwp-guided-tours-option"]' )->length );
		$this->assertSame( 'ui green button', $xpath->query( '//button[@id="mainwp-onboarding-update-all"]/@class' )->item( 0 )->value );
		$this->assertSame( 'ui basic button', $xpath->query( '//a[normalize-space(.)="Review updates first"]/@class' )->item( 0 )->value );
		$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]/parent::div/preceding-sibling::*[1][@class="ui hidden divider"]' )->length );
		$this->assertSame( 'ui green button', $xpath->query( '//form//button[@type="submit"]/@class' )->item( 0 )->value );
		$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]//div[@class="header"][normalize-space(.)="Add another site"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="Add a site"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-next-steps"]//div[@class="header"][normalize-space(.)="Assign this site to a client"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[normalize-space(.)="Assign to a client"]' )->length );
		$this->assertSame( add_query_arg( 'siteid', $site->id, $this->invoke( 'get_next_step_link', array( 'add_client' ) ) ), $xpath->query( '//a[normalize-space(.)="Assign to a client"]/@href' )->item( 0 )->value );
	}

	/** Default connection fields exclude optional settings and keep help independently focusable. */
	public function test_connect_form_advanced_title_and_accessible_password_help(): void {
		$_GET = array(
			'page' => 'mainwp-setup',
			'step' => 'connect_first_site',
		);
		ob_start();
		try {
			$this->wizard->mwp_setup_connect_first_site();
		} finally {
			$html = ob_get_clean();
		}
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 1, $xpath->query( '//h1[normalize-space(.)="Connect your first site"]' )->length );
		$this->assertStringContainsString( "You'll need the site's address and an administrator login.", html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp_managesites_add"][normalize-space(.)="Connect site"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@id="mainwp_addsite_continue_button"][normalize-space(.)="Skip for now"]' )->length );
		$this->assertSame( '(Required)', $xpath->query( '//label[@for="mainwp_managesites_add_admin_pwd"]/span' )->item( 0 )->textContent );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-advanced"][@hidden]//input[@id="mainwp_managesites_add_wpname"][not(@required)]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-advanced"]/*[1][@class="ui hidden divider"]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-advanced"]//div[@class="ui blue message"][contains(.,"WordPress admin login two-factor authentication")]' )->length );
		foreach ( array( 'mainwp_managesites_add_wpurl', 'mainwp_managesites_add_wpadmin', 'mainwp_managesites_add_admin_pwd' ) as $id ) {
			$this->assertSame( 1, $xpath->query( '//input[@id="' . $id . '"][not(ancestor::*[@id="mainwp-onboarding-advanced"])]' )->length, $id );
		}
		$this->assertSame( 1, $xpath->query( '//a[@id="mainwp-onboarding-password-help"][@href="#mainwp-onboarding-password-explanation"][@aria-describedby="mainwp-onboarding-password-explanation"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-password-help"]/ancestor::*[@id="mainwp-administrator-password-field"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-password-help"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " label ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-password-explanation"][@role="tooltip"]' )->length );
		$this->assertStringContainsString( 'Your password is never stored by your Dashboard and never sent to MainWP.com.', $xpath->query( '//*[@id="mainwp-onboarding-password-explanation"]' )->item( 0 )->textContent );
	}

	/** CSV help needs a wrapping popup because the explanation exceeds the viewport. */
	public function test_csv_password_help_uses_accessible_wrapping_popup(): void {
		$_GET = array(
			'page' => 'mainwp-setup',
			'step' => 'connect_first_site',
		);
		ob_start();
		try {
			$this->wizard->mwp_setup_connect_first_site();
		} finally {
			$html = ob_get_clean();
		}
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 1, $xpath->query( '//a[@id="mainwp-onboarding-csv-password-help"][@href="#mainwp-onboarding-csv-password-explanation"][@aria-describedby="mainwp-onboarding-csv-password-explanation"][normalize-space(.)="How?"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-csv-password-help"]/@data-tooltip' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@data-tooltip][normalize-space(.)="How?"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-onboarding-csv-password-explanation"][@class="ui wide popup"][@role="tooltip"]' )->length );
		$this->assertSame( 'Used once to set up a secure key. Never stored or sent to MainWP.com. How?', trim( $xpath->query( '//*[@id="mainwp-onboarding-csv-password-help"]/parent::*' )->item( 0 )->textContent ) );
		$this->assertSame( $xpath->query( '//*[@id="mainwp-onboarding-password-explanation"]' )->item( 0 )->textContent, $xpath->query( '//*[@id="mainwp-onboarding-csv-password-explanation"]' )->item( 0 )->textContent );
	}

	/** The checklist promises only the monitoring state that its completion checks. */
	public function test_get_started_uptime_label_matches_monitoring_action(): void {
		$item = MainWP_Get_Started::get_items()[3];
		$this->assertSame( 'Turn on uptime monitoring', $item['title'] );
		$this->assertSame( 'admin.php?page=MonitoringSettings', $item['url'] );
	}

	/**
	 * Only unchecked monitors enabled by the scheduler count as pending.
	 *
	 * @dataProvider pending_monitor_states
	 * @param int $global_active Global monitoring setting.
	 * @param int $active Monitor override.
	 * @param int $status Last check status.
	 * @param int $expected Expected pending count.
	 */
	public function test_uptime_pending_count_matches_scheduler_activation( $global_active, $active, $status, $expected ): void {
		$site = $this->site();
		$db   = MainWP_DB_Uptime_Monitoring::instance();
		$db->delete_site_monitors( $site->id );
		$monitor_id = $db->update_wp_monitor(
			array(
				'wpid'   => $site->id,
				'active' => $active,
			)
		);
		$this->assertGreaterThan( 0, $monitor_id );
		$this->assertSame(
			$monitor_id,
			$db->update_wp_monitor(
				array(
					'monitor_id'  => $monitor_id,
					'last_status' => $status,
				)
			)
		);
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = $global_active;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$scope = static function () use ( $site ) {
			return array( $site->id );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$result = $db->get_count_up_down_monitors();
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
		$this->assertArrayHasKey( 'count_pending', $result );
		$this->assertSame( $expected, (int) $result['count_pending'] );
		$this->assertSame( 1 === $status ? 1 : 0, (int) $result['count_up'] );
		$this->assertSame( 0 === $status ? 1 : 0, (int) $result['count_down'] );
	}

	/**
	 * Activation and status combinations include both inherited global states.
	 *
	 * @return array
	 */
	public static function pending_monitor_states() {
		$cases = array();
		foreach ( array( 0, 1 ) as $global ) {
			foreach ( array( -1, 0, 1 ) as $active ) {
				foreach ( array( 99, 1, 0 ) as $status ) {
					$expected = 99 === $status && ( 1 === $active || ( -1 === $active && 1 === $global ) ) ? 1 : 0;
					$cases[ "global=$global active=$active status=$status" ] = array( $global, $active, $status, $expected );
				}
			}
		}
		return $cases;
	}

	/** Pending totals must not reveal monitors on inaccessible or staging sites. */
	public function test_uptime_pending_count_respects_site_access_and_staging(): void {
		$site      = $this->site();
		$excluded  = $this->site();
		$staging   = $this->site();
		$suspended = $this->site();
		MainWP_DB::instance()->update_website_values( $staging->id, array( 'is_staging' => 1 ) );
		MainWP_DB::instance()->update_website_values( $suspended->id, array( 'suspended' => 1 ) );
		$db = MainWP_DB_Uptime_Monitoring::instance();
		foreach ( array( $site, $excluded, $staging, $suspended ) as $monitor_site ) {
			$db->delete_site_monitors( $monitor_site->id );
			$this->assertGreaterThan(
				0,
				$db->update_wp_monitor(
					array(
						'wpid'   => $monitor_site->id,
						'active' => 1,
					)
				)
			);
		}
		// The scheduler skips suspended sites, so their monitors never get a first check.
		$allowed = array( $site->id, $staging->id, $suspended->id );
		$scope   = static function () use ( &$allowed ) {
			return $allowed;
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$this->assertSame( 1, (int) $db->get_count_up_down_monitors()['count_pending'] );
			$allowed = array();
			$this->assertSame( 0, (int) $db->get_count_up_down_monitors()['count_pending'] );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
	}

	/**
	 * The widget explains pending checks without adding a line when none remain.
	 *
	 * @dataProvider pending_widget_counts
	 * @param int    $count Pending monitors.
	 * @param string $expected Expected line, or empty when absent.
	 */
	public function test_uptime_widget_pending_line( $count, $expected ): void {
		$site = $this->site();
		$db   = MainWP_DB_Uptime_Monitoring::instance();
		$db->delete_site_monitors( $site->id );
		for ( $index = 0; $index < $count; ++$index ) {
			$this->assertGreaterThan(
				0,
				$db->update_wp_monitor(
					array(
						'wpid'   => $site->id,
						'suburl' => 'pending-' . $index,
						'active' => 1,
					)
				)
			);
		}
		$scope = static function () use ( $site ) {
			return array( $site->id );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		ob_start();
		try {
			MainWP_Uptime_Monitoring_Status::render_status();
		} finally {
			$html = ob_get_clean();
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
		$lines = $this->html_xpath( $html )->query( '//div[@class="ui mainwp-cards small cards"]/following-sibling::p[@class="ui small text"]' );
		$this->assertSame( $count ? 1 : 0, $lines->length );
		if ( $count ) {
			$this->assertSame( $expected, trim( $lines->item( 0 )->textContent ) );
		} else {
			$this->assertStringNotContainsString( 'waiting for', $html );
		}
	}

	/**
	 * Pending widget copy at zero, one and two monitors.
	 *
	 * @return array
	 */
	public static function pending_widget_counts() {
		return array(
			'none'     => array( 0, '' ),
			'singular' => array( 1, '1 monitor waiting for its first check' ),
			'plural'   => array( 2, '2 monitors waiting for their first check' ),
		);
	}

	/** CSV and other AJAX callers defer only when the interactive sender opts in. */
	public function test_add_site_passes_only_explicit_defer_flag(): void {
		foreach ( array( array(), array( 'bulk' => true ), array( 'qsw_page' => true ), array( 'defer_site_icon' => 0 ), array( 'defer_site_icon' => 1 ) ) as $request ) {
			$_POST  = $request;
			$params = Onboarding_Add_Site_Parameters_Fixture::add_site();
			$this->assertSame( ! empty( $request['defer_site_icon'] ), $params['defer_site_icon'] );
		}
	}

	/** The interactive flag skips the request while other callers keep fetching. */
	public function test_initial_favicon_defer_and_custom_icons_skip_child_request(): void {
		$site     = $this->site();
		$requests = array();
		$fetch    = static function ( $pre, $website, $action ) use ( &$requests ) {
			$requests[] = array( (int) $website->id, $action );
			return array();
		};
		$method   = new \ReflectionMethod( MainWP_Manage_Sites_View::class, 'maybe_fetch_initial_site_favicon' );
		$method->setAccessible( true );
		add_filter( 'mainwp_fetch_url_authed_pre', $fetch, 10, 3 );
		try {
			foreach ( array( array( 'defer_site_icon' => true ), array( 'uploaded_site_icon' => 'custom.png' ), array( 'selected_site_icon' => 'star' ) ) as $params ) {
				$method->invoke( null, $site->id, $params );
				$this->assertSame( array(), $requests );
			}
			$method->invoke( null, $site->id, array() );
			$this->assertSame( array( array( (int) $site->id, 'get_site_icon' ) ), $requests );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $fetch );
		}
	}

	/** Summary pages fetch missing icons using the existing nonce-protected action. */
	public function test_summary_fetches_only_missing_icons_without_custom_selection(): void {
		$site = $this->site();
		foreach ( array( '', 'uploaded:custom.png;selected:;color:', 'uploaded:;selected:star;color:' ) as $custom ) {
			MainWP_DB::instance()->update_website_option( $site, 'cust_site_icon_info', $custom );
			$html  = $this->render_summary( $site->id );
			$xpath = $this->html_xpath( $html );
			$nodes = $xpath->query( '//*[@id="mainwp-onboarding-site-icon"]' );
			$this->assertSame( '' === $custom ? 1 : 0, $nodes->length );
			if ( '' === $custom ) {
				$this->assertSame( (string) $site->id, $nodes->item( 0 )->getAttribute( 'data-siteid' ) );
				$this->assertNotFalse( wp_verify_nonce( $nodes->item( 0 )->getAttribute( 'data-security' ), 'mainwp_get_site_icon' ) );
			}
		}
		MainWP_DB::instance()->update_website_option( $site, 'cust_site_icon_info', '' );
		MainWP_DB::instance()->update_website_option( $site, 'favi_icon', 'stored-icon.png' );
		$this->assertStringNotContainsString( 'id="mainwp-onboarding-site-icon"', $this->render_summary( $site->id ) );
		$this->assertStringNotContainsString( 'id="mainwp-onboarding-site-icon"', $this->render_summary( 0 ) );
	}

	/** Names and versions come from stored payloads, including premium update overlays. */
	public function test_summary_names_core_plugins_themes_and_translations(): void {
		$site = $this->site();
		MainWP_DB::instance()->update_website_values(
			$site->id,
			array(
				'plugin_upgrades'      => wp_json_encode(
					array(
						'example/plugin.php' => array(
							'Name'    => 'Example <Plugin>',
							'Version' => '1.0',
							'update'  => array( 'new_version' => '2.0' ),
						),
					)
				),
				'theme_upgrades'       => wp_json_encode(
					array(
						'example-theme' => array(
							'Name'    => 'Example Theme',
							'Version' => '3.0',
							'update'  => array( 'new_version' => '4.0' ),
						),
					)
				),
				'translation_upgrades' => wp_json_encode( array( array( 'slug' => 'de_DE' ) ) ),
			)
		);
		MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( array( 'wpversion' => '7.1.3' ) ) );
		MainWP_DB::instance()->update_website_option(
			$site,
			'wp_upgrades',
			wp_json_encode(
				array(
					'current' => '7.1.3',
					'new'     => '7.2',
				)
			)
		);
		update_option( 'mainwp_show_language_updates', 1 );
		$rows = $this->html_xpath( $this->render_summary( $site->id ) )->query( '//ul[@id="mainwp-onboarding-update-list"]/li' );
		$this->assertSame(
			array( 'WordPress 7.1.3 to 7.2', 'Example <Plugin> 1.0 to 2.0', 'Example Theme 3.0 to 4.0', '1 translation update' ),
			array_map(
				static function ( $node ) {
					return $node->textContent;
				},
				iterator_to_array( $rows )
			)
		);
		$this->assertStringContainsString( '4 updates waiting', $this->render_summary( $site->id ) );
		$this->assertStringNotContainsString( '<Plugin>', $this->render_summary( $site->id ) );
		$premium = $this->site();
		MainWP_DB::instance()->update_website_values(
			$premium->id,
			array(
				'plugin_upgrades' => wp_json_encode(
					array(
						'example/plugin.php' => array(
							'Name'    => 'Example <Plugin>',
							'Version' => '1.0',
							'update'  => array( 'new_version' => '2.0' ),
						),
					)
				),
			)
		);
		MainWP_DB::instance()->update_website_option( $premium, 'site_info', wp_json_encode( array( 'wpversion' => '7.1.3' ) ) );
		MainWP_DB::instance()->update_website_values(
			$premium->id,
			array(
				'premium_upgrades' => wp_json_encode(
					array(
						'example/plugin.php' => array(
							'type'   => 'plugin',
							'update' => array( 'new_version' => '2.1' ),
						),
					)
				),
			)
		);
		$this->assertStringContainsString( 'Example &lt;Plugin&gt; 1.0 to 2.1', $this->render_summary( $premium->id ) );
	}

	/** The summary caps rows at five and renders a clean verdict after updates finish. */
	public function test_summary_caps_named_rows_and_renders_up_to_date_verdict(): void {
		$site    = $this->site();
		$plugins = array();
		for ( $number = 1; 7 >= $number; ++$number ) {
			$plugins[ 'example-' . $number . '/plugin.php' ] = array(
				'Name'    => 'Example ' . $number,
				'Version' => '1.0',
				'update'  => array( 'new_version' => '2.0' ),
			);
		}
		MainWP_DB::instance()->update_website_option( $site, 'site_info', wp_json_encode( array( 'wpversion' => '6.7' ) ) );
		MainWP_DB::instance()->update_website_values( $site->id, array( 'plugin_upgrades' => wp_json_encode( $plugins ) ) );
		$html  = $this->render_summary( $site->id );
		$xpath = $this->html_xpath( $html );
		$this->assertSame( 5, $xpath->query( '//ul[@id="mainwp-onboarding-update-list"]/li' )->length );
		$this->assertStringContainsString( '7 updates waiting', $html );
		$this->assertStringContainsString( 'and 2 more', $html );
		$this->assertSame( 'Example 5 1.0 to 2.0', $xpath->query( '//ul[@id="mainwp-onboarding-update-list"]/li[5]' )->item( 0 )->textContent );
		// Three translations share the hidden last row, so the overflow counts updates, not rows.
		update_option( 'mainwp_show_language_updates', 1 );
		MainWP_DB::instance()->update_website_values( $site->id, array( 'translation_upgrades' => wp_json_encode( array( array( 'slug' => 'de_DE' ), array( 'slug' => 'fr_FR' ), array( 'slug' => 'es_ES' ) ) ) ) );
		$html = $this->render_summary( $site->id );
		$this->assertStringContainsString( '10 updates waiting', $html );
		$this->assertStringContainsString( 'and 5 more', $html );
		MainWP_DB::instance()->update_website_values( $site->id, array( 'translation_upgrades' => '[]' ) );
		MainWP_DB::instance()->update_website_values( $site->id, array( 'plugin_upgrades' => wp_json_encode( array_slice( $plugins, -1, 1, true ) ) ) );
		$html = $this->render_summary( $site->id );
		$this->assertStringContainsString( '1 update waiting', $html );
		$this->assertStringContainsString( 'Example 7 1.0 to 2.0', $html );
		MainWP_DB::instance()->update_website_values( $site->id, array( 'plugin_upgrades' => '[]' ) );
		$html = $this->render_summary( $site->id );
		$this->assertStringContainsString( 'Everything is up to date', $html );
		$this->assertStringNotContainsString( 'mainwp-onboarding-update-list', $html );
		$this->assertStringNotContainsString( 'id="mainwp-onboarding-update-all"', $html );
	}

	/** The off action preserves tours and all other monitoring settings. */
	public function test_turn_off_preserves_guided_tours_and_survives_summary_reload(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		MainWP_Setup_Wizard::apply_monitoring_defaults();
		foreach ( array( 0, 1 ) as $tours ) {
			update_option( 'mainwp_enable_guided_tours', $tours );
			$settings           = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
			$settings['active'] = 1;
			update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
			$this->assertTrue( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_disable_monitoring' ), 'disable' )['success'] );
			$settings['active'] = 0;
			$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
			$this->assertSame( $tours, (int) get_option( 'mainwp_enable_guided_tours' ) );
			$html = $this->render_summary( 0 );
			$this->assertStringContainsString( 'Uptime monitoring is off.', $html );
			$this->assertStringNotContainsString( 'id="mainwp-onboarding-disable-monitoring"', $html );
			$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		}
	}

	/** Invalid nonces and restricted users cannot turn monitoring off. */
	public function test_turn_off_requires_its_nonce_and_settings_right(): void {
		$settings           = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		update_option( 'mainwp_enable_guided_tours', 1 );
		foreach ( array( '', 'invalid', wp_create_nonce( 'mainwp_onboarding_enable_monitoring' ) ) as $nonce ) {
			$this->assertFalse( $this->monitoring_ajax( $nonce, 'disable' )['success'] );
		}
		$deny = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		try {
			$this->assertFalse( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_disable_monitoring' ), 'disable' )['success'] );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->monitoring_ajax( wp_create_nonce( 'mainwp_onboarding_disable_monitoring' ), 'disable' )['success'] );
		$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enable_guided_tours' ) );
	}

	/** Single-site, CSV and skipped summaries all retain the global off switch. */
	public function test_all_summary_variants_render_uptime_and_turn_off_link(): void {
		$site = $this->site();
		update_option( 'mainwp_onboarding_fresh_install', time() );
		foreach ( array(
			'single' => $site->id,
			'csv'    => 0,
			'none'   => 0,
		) as $variant => $id ) {
			$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
			$property->setAccessible( true );
			$property->setValue( null, 'none' === $variant ? 0 : 1 );
			$xpath = $this->html_xpath( $this->render_summary( $id ) );
			$this->assertSame( 1, $xpath->query( '//p[@id="mainwp-onboarding-uptime"][contains(.,"Uptime monitoring is on.")]/a[normalize-space(.)="Turn off"]' )->length, $variant );
			$link = $xpath->query( '//*[@id="mainwp-onboarding-disable-monitoring"]' )->item( 0 );
			$this->assertNotFalse( wp_verify_nonce( $link->getAttribute( 'data-security' ), 'mainwp_onboarding_disable_monitoring' ) );
			$this->assertSame( 0, $xpath->query( '//*[@id="mainwp_setup_enableUptimeMonitoring"]' )->length );
			$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-guided-tours-option"]/parent::div/following-sibling::span[@id="mainwp-onboarding-tour-help"]/a[@href="https://www.usetiful.com/privacy-policy"]' )->length );
		}
	}

	/** The uptime sentence promises email only when alerts are enabled and have a recipient. */
	public function test_summary_uptime_email_promise_requires_recipient(): void {
		$site               = $this->site();
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$db = MainWP_DB_Uptime_Monitoring::instance();
		$db->delete_site_monitors( $site->id );
		$db->update_wp_monitor(
			array(
				'wpid'   => $site->id,
				'active' => -1,
			)
		);
		foreach ( array( 'alerts@example.org', '', '  ' ) as $recipients ) {
			update_option(
				'mainwp_settings_notification_emails',
				array(
					'uptime' => array(
						'disable'    => 0,
						'recipients' => $recipients,
					),
				)
			);
			$line = trim( $this->html_xpath( $this->render_summary( $site->id ) )->query( '//p[@id="mainwp-onboarding-uptime"]' )->item( 0 )->textContent );
			$this->assertStringStartsWith( '' === trim( $recipients ) ? 'Uptime monitoring is on. Email alerts are disabled.' : "Uptime monitoring is on. We'll email alerts@example.org if", $line );
		}
	}

	/** A digest promise requires both enabled delivery and recipients. */
	public function test_digest_line_requires_enabled_digest_and_recipient(): void {
		foreach ( array( array( 1, 'digest@example.org' ), array( 0, '' ), array( 0, '  ' ), array( 0, 'digest@example.org' ) ) as list( $disabled, $recipient ) ) {
			update_option(
				'mainwp_settings_notification_emails',
				array(
					'daily_digest' => array(
						'disable'    => $disabled,
						'recipients' => $recipient,
					),
				)
			);
			$xpath = $this->html_xpath( $this->render_summary( 0 ) );
			$line  = $xpath->query( '//p[@id="mainwp-onboarding-digest"]' );
			$this->assertSame( ! $disabled && '' !== trim( $recipient ) ? 1 : 0, $line->length );
			if ( $line->length ) {
				$this->assertSame( "We'll email digest@example.org a daily digest when updates are waiting or a site is disconnected.", $line->item( 0 )->textContent );
			}
		}
	}

	/**
	 * Render the summary for an explicit site selection.
	 *
	 * @param int $site_id Site ID or zero.
	 * @return string
	 */
	private function render_summary( $site_id ) {
		$_GET = array(
			'page'   => 'mainwp-setup',
			'step'   => 'next_steps',
			'siteid' => $site_id,
		);
		ob_start();
		try {
			$this->wizard->mwp_setup_ready();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	/**
	 * Parse rendered HTML without treating WordPress's custom attributes as failures.
	 *
	 * @param string $html Rendered page.
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

	/** Summary counts share global and site ignore rules and the existing health rule. */
	public function test_summary_uses_synced_data_and_ignore_lists(): void {
		$site    = $this->site();
		$plugins = array();
		foreach ( array( 'kept', 'site-held', 'global-held' ) as $slug ) {
			$plugins[ $slug . '/plugin.php' ] = array(
				'Name'   => $slug,
				'update' => array(
					'new_version' => '2.0',
					'slug'        => $slug,
				),
			);
		}
		MainWP_DB::instance()->update_website_values(
			$site->id,
			array(
				'plugin_upgrades'      => wp_json_encode( $plugins ),
				'ignored_plugins'      => wp_json_encode( array( 'site-held/plugin.php' => 'ignored' ) ),
				'theme_upgrades'       => wp_json_encode(
					array(
						'held' => array(
							'Name'   => 'Held',
							'update' => array( 'new_version' => '2.0' ),
						),
					)
				),
				'ignored_themes'       => wp_json_encode( array( 'held' => 'ignored' ) ),
				'translation_upgrades' => wp_json_encode( array( array( 'slug' => 'de_DE' ) ) ),
			)
		);
		$extension                  = MainWP_DB_Common::instance()->get_user_extension();
		$extension->ignored_plugins = wp_json_encode( array( 'global-held/plugin.php' => 'ignored' ) );
		MainWP_DB_Common::instance()->update_user_extension( $extension );
		$db = MainWP_DB::instance();
		$db->update_website_option( $site, 'site_info', wp_json_encode( array( 'wpversion' => '6.7' ) ) );
		$db->update_website_option(
			$site,
			'wp_upgrades',
			wp_json_encode(
				array(
					'current' => '6.7',
					'new'     => '6.8',
				)
			)
		);
		$db->update_website_option(
			$site,
			'health_site_status',
			wp_json_encode(
				array(
					'good'        => 20,
					'recommended' => 1,
					'critical'    => 0,
				)
			)
		);
		$site = MainWP_DB::instance()->get_website_by_id( $site->id );
		$data = $this->invoke( 'summary_data', array( $site ) );
		$this->assertSame( '6.7', $data['version'] );
		$this->assertSame( '6.8', $data['new'] );
		$this->assertSame( 1, $data['updates']['total_plugin_upgrades'] );
		$this->assertSame( 0, $data['updates']['total_theme_upgrades'] );
		$this->assertSame( 1, $data['updates']['total_translation_upgrades'] );
		$this->assertSame( array( 'WordPress 6.7 to 6.8', 'kept 2.0', '1 translation update' ), $data['rows'] );
		$this->assertTrue( $data['good'] );
		$db->update_website_option( $site, 'ignored_trans_updates', 1 );
		$db->update_website_option( $site, 'ignored_wp_upgrades', wp_json_encode( array( 'ignored_versions' => array( '6.8' ) ) ) );
		$db->update_website_option(
			$site,
			'health_site_status',
			wp_json_encode(
				array(
					'good'     => 100,
					'critical' => 1,
				)
			)
		);
		$site = MainWP_DB::instance()->get_website_by_id( $site->id );
		$data = $this->invoke( 'summary_data', array( $site ) );
		$this->assertSame( '', $data['new'] );
		$this->assertSame( 0, $data['updates']['total_wp_upgrades'] );
		$this->assertSame( 0, $data['updates']['total_translation_upgrades'] );
		$this->assertSame( array( 'kept 2.0' ), $data['rows'] );
		$this->assertFalse( $data['good'], 'A high score cannot conceal a critical issue.' );
		$db->update_website_option( $site, 'ignored_trans_updates', 0 );
		$site = MainWP_DB::instance()->get_website_by_id( $site->id );
		$this->assertSame( 1, $this->invoke( 'summary_data', array( $site ) )['updates']['total_translation_upgrades'] );
		update_option( 'mainwp_show_language_updates', 0 );
		$data = $this->invoke( 'summary_data', array( $site ) );
		$this->assertSame( 0, $data['updates']['total_translation_upgrades'], 'Hidden translation updates are skipped by the update chain.' );
		$this->assertSame( array( 'kept 2.0' ), $data['rows'] );
	}

	/** Explicit zero and inaccessible IDs must never fall back to an unrelated site. */
	public function test_summary_site_selection_is_scoped(): void {
		$site = $this->site();
		set_transient( 'mainwp_transient_just_connected_site_id', $site->id );
		$this->assertSame( (int) $site->id, (int) $this->invoke( 'summary_site' )->id );
		$_GET['siteid'] = 0;
		$this->assertFalse( $this->invoke( 'summary_site' ) );
		$_GET['siteid'] = $site->id;
		$deny           = static function ( $allowed, $type ) {
			return 'site' === $type ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		try {
			$this->assertFalse( $this->invoke( 'summary_site' ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 999 );
		}
	}

	/** Only successful updates, including component results, complete the item. */
	public function test_first_update_hook_ignores_failures_installs_and_rollbacks(): void {
		$site = (object) array( 'id' => 123 );
		do_action( 'mainwp_install_update_actions', $site, 'updated', array( 'success' => 1 ), 'core' );
		$this->assertFalse( get_option( 'mainwp_onboarding_first_update' ) );
		update_option( 'mainwp_onboarding_fresh_install', time() );
		do_action( 'mainwp_install_update_actions', $site, 'install', array( 'success' => 1 ), 'core' );
		do_action( 'mainwp_install_update_actions', $site, 'updated', array( 'success' => 0 ), 'core' );
		do_action(
			'mainwp_install_update_actions',
			$site,
			'updated',
			array(
				'updated_data' => array(
					array(
						'success'  => 1,
						'rollback' => 1,
					),
				),
			),
			'plugin'
		);
		$this->assertFalse( get_option( 'mainwp_onboarding_first_update' ) );
		foreach ( array( 'core', 'plugin', 'theme', 'trans' ) as $type ) {
			delete_option( 'mainwp_onboarding_first_update' );
			$result = 'core' === $type ? array( 'success' => 1 ) : array( 'updated_data' => array( array( 'success' => 0 ), array( 'success' => 1 ) ) );
			do_action( 'mainwp_install_update_actions', $site, 'updated', $result, $type );
			$this->assertSame( 1, (int) get_option( 'mainwp_onboarding_first_update' ), $type );
		}
	}

	/** Count, success, monitoring and visit state drive completion and dismissal. */
	public function test_get_started_items_and_dismissal(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$this->assertTrue( MainWP_Get_Started::is_visible() );
		$property = new \ReflectionProperty( MainWP_Manage_Sites::class, 'total_sites' );
		$property->setAccessible( true );
		foreach ( array( 0, 1, 2 ) as $sites ) {
			$property->setValue( null, $sites );
			$items = MainWP_Get_Started::get_items();
			$this->assertSame( 1 <= $sites, $items[0]['complete'] );
			$this->assertSame( 2 <= $sites, $items[2]['complete'] );
			$this->assertFalse( $items[1]['complete'] );
			$this->assertFalse( $items[3]['complete'] );
		}
		ob_start();
		MainWP_Get_Started::render_content();
		$xpath = $this->html_xpath( ob_get_clean() );
		$this->assertSame( 1, $xpath->query( '//div[@id="mainwp-get-started-list"][@class="ui middle aligned divided list"]' )->length );
		$this->assertSame( count( $items ), $xpath->query( '//div[@id="mainwp-get-started-list"]/a[@class="item"]/div[@class="content"][normalize-space(.)!=""][not(*)]' )->length );
		$this->assertSame( 0, $xpath->query( '//div[contains(concat(" ",normalize-space(@class)," ")," steps ")]' )->length );
		$this->assertSame( 2, $xpath->query( '//a[@data-complete="true"]/i[@class="green check circle icon"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@data-complete="false"]/i[@class="heartbeat icon"]' )->length );
		update_option( 'mainwp_onboarding_first_update', 1 );
		update_option( 'mainwp_onboarding_mcp_visited', 1 );
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$this->assertFalse( MainWP_Get_Started::get_items()[3]['complete'], 'Defaults alone are not confirmation.' );
		update_option( 'mainwp_onboarding_uptime_confirmed', 1 );
		$client = MainWP_DB_Client::instance()->update_client(
			array(
				'name'         => 'Onboarding Client',
				'client_email' => 'onboarding@example.org',
			)
		);
		$this->assertNotFalse( $client );
		$items = MainWP_Get_Started::get_items();
		$this->assertNotContains( false, array_column( $items, 'complete' ) );
		$this->assertSame( 'Create a client and report', end( $items )['title'] );
		$this->assertSame( 'admin.php?page=MonitoringSettings', $items[3]['url'] );
		ob_start();
		MainWP_Get_Started::render_content();
		$html  = ob_get_clean();
		$xpath = $this->html_xpath( $html );
		$this->assertSame( count( $items ), $xpath->query( '//a[@data-complete="true"]/div[@class="content"][normalize-space(.)!=""][not(*)]' )->length );
		$this->assertSame( count( $items ), $xpath->query( '//a[@data-complete="true"]/i[@class="green check circle icon"]' )->length );
		$this->assertSame( 0, $xpath->query( '//a[contains(@class,"completed")]' )->length );
		$this->assertTrue( MainWP_Get_Started::is_visible() );
		update_user_option( get_current_user_id(), 'mainwp_notice_saved_status', array( 'get-started' => 1 ) );
		$this->assertFalse( MainWP_Get_Started::is_visible() );
		delete_option( 'mainwp_onboarding_first_update' );
		$this->assertTrue( MainWP_Get_Started::is_visible(), 'A dismissal before completion cannot remove unfinished items.' );
		$settings['active'] = 0;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
		$this->assertFalse( MainWP_Get_Started::get_items()[3]['complete'], 'A prior confirmation cannot complete disabled monitoring.' );
	}

	/** The MCP shortcut is absent when the AI menu is disabled. */
	public function test_mcp_item_is_hidden_when_menu_is_disabled(): void {
		global $_mainwp_disable_menus_items;
		$before                      = $_mainwp_disable_menus_items;
		$hide                        = static function ( $disabled, $level, $slug ) {
			return 'ai' === $slug ? true : $disabled;
		};
		$_mainwp_disable_menus_items = array();
		$this->assertContains( 'admin.php?page=mainwp-mcp', array_column( MainWP_Get_Started::get_items(), 'url' ) );
		add_filter( 'mainwp_is_disable_menu_item', $hide, 10, 3 );
		try {
			$this->assertNotContains( 'admin.php?page=mainwp-mcp', array_column( MainWP_Get_Started::get_items(), 'url' ) );
		} finally {
			remove_filter( 'mainwp_is_disable_menu_item', $hide, 10 );
			$_mainwp_disable_menus_items = $before;
		}
	}
}

/** Supplies detected values while exercising the production aggregator. */
class Onboarding_System_Check_Fixture extends MainWP_Setup_Wizard {
	/**
	 * Detected environment.
	 *
	 * @var array
	 */
	public $checks;

	/**
	 * Supply detected values.
	 *
	 * @return array
	 */
	protected function system_check_values() {
		return $this->checks;
	}

	/**
	 * Expose the production check.
	 *
	 * @return bool
	 */
	public function passes() {
		return $this->system_checks_pass();
	}
}

/** Captures the existing AJAX parameter parser before registration starts. */
class Onboarding_Add_Site_Parameters_Fixture extends MainWP_Manage_Sites_View {
	/**
	 * Return parsed parameters without registering a Child.
	 *
	 * @param object|false $website Existing website.
	 * @param array        $params Parsed parameters.
	 * @param array        $output Registration output.
	 * @return array
	 */
	public static function add_wp_site( $website, $params = array(), &$output = array() ) {
		return $params;
	}
}
