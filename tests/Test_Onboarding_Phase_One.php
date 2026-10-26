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
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Setup_Wizard;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;

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
		foreach ( array( 'fresh_install', 'defaults_applied', 'first_update', 'mcp_visited' ) as $flag ) {
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

	/** Existing installs receive neither monitoring defaults nor a widget. */
	public function test_existing_install_is_unchanged(): void {
		$before = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->invoke( 'apply_monitoring_defaults' );
		$this->assertSame( $before, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
		$this->assertFalse( get_option( 'mainwp_disableSitesHealthMonitoring' ) );
		$this->assertFalse( get_option( 'mainwp_sitehealthThreshold' ) );
		$this->assertFalse( get_option( 'mainwp_onboarding_defaults_applied' ) );
		$this->assertFalse( MainWP_Get_Started::is_visible() );
		$version = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$version->setAccessible( true );
		update_site_option( 'mainwp_db_version', $version->getValue( MainWP_Install::instance() ) );
		MainWP_Install::instance()->install();
		$this->assertFalse( get_option( 'mainwp_onboarding_fresh_install' ) );
	}

	/** A fresh summary enables monitoring once and leaves later edits alone. */
	public function test_fresh_defaults_apply_once(): void {
		update_option( 'mainwp_onboarding_fresh_install', time() );
		$this->invoke( 'apply_monitoring_defaults' );
		$settings = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$this->assertSame( 1, (int) $settings['active'] );
		$this->assertSame( 60, (int) $settings['interval'] );
		$this->assertSame( 0, (int) get_option( 'mainwp_disableSitesHealthMonitoring' ) );
		$this->assertSame( 80, (int) get_option( 'mainwp_sitehealthThreshold' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'mainwp_onboarding_defaults_applied' ) );
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
	}

	/** Leaving the summary needs no settings right, while both buttons must preserve settings. */
	public function test_summary_save_without_settings_right_redirects_without_writing(): void {
		update_option( 'mainwp_enable_guided_tours', 0 );
		$settings = get_option( 'mainwp_global_uptime_monitoring_settings' );
		$deny     = static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type && 'manage_dashboard_settings' === $right ? false : $allowed;
		};
		$redirect = static function ( $location ) {
			throw new \RuntimeException( $location );
		};
		$_POST    = array(
			'save_monitoring'                     => '1',
			'mainwp-guided-tours-option'          => '1',
			'mainwp_setup_enableUptimeMonitoring' => '1',
		);
		add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
		add_filter( 'wp_redirect', $redirect );
		try {
			$this->wizard->mwp_setup_ready_save();
			$this->assertSame( 0, (int) get_option( 'mainwp_enable_guided_tours' ) );
			$this->assertSame( $settings, get_option( 'mainwp_global_uptime_monitoring_settings' ) );
			unset( $_POST['save_monitoring'] );
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
		$this->assertStringContainsString( 'Everything is up to date', $html );
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
		$this->assertSame( $global_active, $xpath->query( '//input[@id="mainwp_setup_enableUptimeMonitoring"][@checked]' )->length );
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
		$this->assertSame( 1, $xpath->query( '//input[@id="mainwp_setup_enableUptimeMonitoring"][@type="checkbox"][@checked]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//button[@type="submit"][normalize-space(.)="Go to Dashboard"]' )->length );
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
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-advanced"][@hidden]//input[@id="mainwp_managesites_add_wpname"][not(@required)]' )->length );
		foreach ( array( 'mainwp_managesites_add_wpurl', 'mainwp_managesites_add_wpadmin', 'mainwp_managesites_add_admin_pwd' ) as $id ) {
			$this->assertSame( 1, $xpath->query( '//input[@id="' . $id . '"][not(ancestor::*[@id="mainwp-onboarding-advanced"])]' )->length, $id );
		}
		$this->assertSame( 1, $xpath->query( '//button[@id="mainwp-onboarding-password-help"][@type="button"][@aria-describedby="mainwp-onboarding-password-explanation"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-password-help"]/ancestor::*[@id="mainwp-administrator-password-field"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="mainwp-onboarding-password-help"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " label ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="mainwp-onboarding-password-explanation"][@role="tooltip"]' )->length );
		$this->assertStringContainsString( 'Your password is never stored by your Dashboard and never sent to MainWP.com.', $xpath->query( '//*[@id="mainwp-onboarding-password-explanation"]' )->item( 0 )->textContent );
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
		$this->assertFalse( $data['good'], 'A high score cannot conceal a critical issue.' );
		$db->update_website_option( $site, 'ignored_trans_updates', 0 );
		$site = MainWP_DB::instance()->get_website_by_id( $site->id );
		$this->assertSame( 1, $this->invoke( 'summary_data', array( $site ) )['updates']['total_translation_upgrades'] );
		update_option( 'mainwp_show_language_updates', 0 );
		$data = $this->invoke( 'summary_data', array( $site ) );
		$this->assertSame( 0, $data['updates']['total_translation_upgrades'], 'Hidden translation updates are skipped by the update chain.' );
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
		update_option( 'mainwp_onboarding_first_update', 1 );
		update_option( 'mainwp_onboarding_mcp_visited', 1 );
		$settings           = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
		$settings['active'] = 1;
		update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
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
		$this->assertTrue( MainWP_Get_Started::is_visible() );
		update_user_option( get_current_user_id(), 'mainwp_notice_saved_status', array( 'get-started' => 1 ) );
		$this->assertFalse( MainWP_Get_Started::is_visible() );
		delete_option( 'mainwp_onboarding_first_update' );
		$this->assertTrue( MainWP_Get_Started::is_visible(), 'A dismissal before completion cannot remove unfinished items.' );
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
