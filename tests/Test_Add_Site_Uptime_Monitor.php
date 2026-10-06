<?php
/**
 * Uptime monitor tests (MWP-1770).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Schedule;
use MainWP\Dashboard\MainWP_Manage_Sites_View;


// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests uptime monitor creation, recovery, activation, and scheduling behavior.
 */
class Test_Add_Site_Uptime_Monitor extends \WP_UnitTestCase {

    /**
	 * Mocked verify fetch not authed result to return.
	 *
	 * @var mixed
	 */
	protected $mock_fetch_not_authed_result = array( 'register' => 'OK' );

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

        add_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'mock_fetch_not_authed' ), 10, 12 );
        add_filter( 'mainwp_add_site_skip_key_generation', array( $this, 'mock_add_site_skip_key_generation' ), 10, 3 );

	}

	/**
	 * Clean up test fixtures and generated uptime data.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

        remove_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'mock_fetch_not_authed' ), 10 );
        remove_filter( 'mainwp_add_site_skip_key_generation', array( $this, 'mock_add_site_skip_key_generation' ), 10 );

		parent::tearDown();
	}

    /**
     * Verify a new site gets a primary monitor while global monitoring is disabled.
     *
     * @return void
     */
    public function test_primary_monitor_is_created_when_global_monitoring_is_disabled() {

        // Disable global monitoring.
        $this->set_global_monitoring_enabled( false);

        $global_settings = $this->get_global_settings();

        $this->assertNotEmpty(
            $global_settings
        );

        $this->assertEmpty(
            $global_settings['active']
        );

        // Create a new site while monitoring is disabled.
        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Disabled',
                'url'  => 'https://test-uptime-disabled.example.com/',
            )
        );

         $this->assertNotEmpty(
            $site_id
        );

        // The primary monitor must still be created.
        $monitor = $this->get_primary_monitor( $site_id );

        $this->assertNotEmpty(
            $monitor,
            'A primary monitor should be created even when global monitoring is disabled.'
        );

        $this->assertSame(
            '',
            (string) $monitor->suburl,
            'The monitor created for a new site should be the primary monitor.'
        );


        $this->assertSame(
            -1,
            (int) $monitor->active,
            'The monitor created for a new site should be use global settings.'
        );

    }


    /**
     * Verify a new site gets an active primary monitor when global monitoring is enabled.
     *
     * @return void
     */
    public function test_primary_monitor_is_created_when_global_monitoring_is_enabled() {

        // Enable global monitoring.
         $this->set_global_monitoring_enabled( true );

        $global_settings = $this->get_global_settings();


        $this->assertNotEmpty(
            $global_settings
        );

        $this->assertNotEmpty(
            $global_settings['active']
        );

        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Enabled',
                'url'  => 'https://test-uptime-enabled.example.com/',
            )
        );

        $monitor = $this->get_primary_monitor( $site_id );

        $this->assertNotEmpty(
            $monitor,
            'A primary monitor should be created when global monitoring is enabled.'
        );

        $this->assertSame(
            '',
            (string) $monitor->suburl,
            'The monitor created for a new site should be the primary monitor.'
        );

        $this->assertSame(
            -1,
            (int) $monitor->active,
            'The monitor created for a new site should be use global settings.'
        );

    }

    /**
     * Verify an existing site without a primary monitor is repaired successfully.
     *
     * @return void
     */
    public function test_missing_primary_monitor_is_repaired() {

        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Missing Primary',
                'url'  => 'https://test-uptime-missing-primary.example.com/',
            )
        );

        // Delete primary monitor.
        MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'wpid' =>  $site_id ) );

        $primary_monitor = $this->get_primary_monitor( $site_id );

        $this->assertEmpty(
            $primary_monitor
        );

        // Run the recovery method.
        $result = $this->repair_missing_monitor(
            $site_id
        );

        $this->assertTrue(
            $result,
            'Missing primary monitor recovery should succeed.'
        );

        $primary_monitor = $this->get_primary_monitor( $site_id );

        $this->assertNotEmpty(
            $primary_monitor,
            'The missing primary monitor should be created.'
        );

        $this->assertSame(
            '',
            (string) $primary_monitor->suburl,
            'The repaired monitor must be the Primary Monitor.'
        );

        $this->assertSame(
            -1,
            (int) $primary_monitor->active,
            'The repaired monitor created for a new site should be use global settings.'
        );
    }

    /**
     * Verify repair reports failure when the Primary Monitor insert fails.
     *
     * @return void
     */
    public function test_missing_primary_monitor_insert_failure() {
        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Missing Primary Insert Failure',
                'url'  => 'https://test-uptime-missing-primary-failure.example.com/',
            )
        );

        // Delete primary monitor.
        MainWP_DB_Uptime_Monitoring::instance()->delete_monitor(
            array( 'wpid' => $site_id )
        );

        $primary_monitor = $this->get_primary_monitor( $site_id );

        $this->assertEmpty( $primary_monitor );

        // Force update_wp_monitor() / INSERT to fail here.
        add_filter(
            'mainwp_update_wp_monitor',
            '__return_empty_array'
        );

        $result = MainWP_DB_Uptime_Monitoring::instance()->repair_missing_primary_monitors(
            $site_id
        );

        $this->assertSame( 1, $result['found'] );
        $this->assertSame( 0, $result['created'] );
    }

    /**
     * Verify repeated primary-monitor recovery does not create duplicates.
     *
     * @return void
     */
    public function test_repeated_primary_monitor_recovery_does_not_create_duplicates() {

        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Recovery',
                'url'  => 'https://test-uptime-recovery.example.com/',
            )
        );

        $this->assertNotEmpty(
            $site_id
        );

         // Delete primary monitor.
        MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'wpid' =>  $site_id ) );

        // Run recovery twice.
        $this->repair_missing_monitor(
            $site_id
        );
        $this->repair_missing_monitor(
            $site_id
        );

        // Query all monitors for this site and verify exactly one has suburl = ''.
        $primary_monitors = $this->get_primary_monitors( $site_id );

        $this->assertCount(
            1,
            $primary_monitors,
            'Repeated recovery must not create duplicate Primary Monitors.'
        );
    }


    /**
     * Verify global monitoring toggling controls a repaired monitor
     * without changing its inherited configuration.
     *
     * @return void
     */
    public function test_repaired_monitor_follows_global_monitoring_toggle() {

        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Global Toggle',
                'url'  => 'https://test-uptime-global-toggle.example.com/',
            )
        );

        // Delete primaryy monitor.
        MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'wpid' =>  $site_id ) );

        // Repair the missing primary monitor.
        $this->repair_missing_monitor(
            $site_id
        );

        $monitor = $this->get_primary_monitor( $site_id );

        $this->assertNotEmpty(
            $monitor
        );

        $original_type          = $monitor->type;
        $original_method        = $monitor->method;
        $original_up_status_codes = $monitor->up_status_codes;

        // Global OFF.
        $this->set_global_monitoring_enabled( false );

        $monitor = $this->get_primary_monitor( $site_id );

        $this->assertFalse(
            $this->is_monitor_active( $monitor ),
            'The repaired monitor should be disabled when global monitoring is disabled.'
        );

        $this->assertSame( $original_type, $monitor->type );
        $this->assertSame( $original_method, $monitor->method );
        $this->assertSame( $original_up_status_codes, $monitor->up_status_codes );

        // Global ON.
        $this->set_global_monitoring_enabled( true );

        $monitor = $this->get_primary_monitor( $site_id );

        $this->assertTrue(
            $this->is_monitor_active( $monitor ),
            'The repaired monitor should become active when global monitoring is enabled.'
        );

        // Inherited configuration must remain unchanged.
        $this->assertSame( $original_type, $monitor->type );
        $this->assertSame( $original_method, $monitor->method );
        $this->assertSame( $original_up_status_codes, $monitor->up_status_codes );
    }

    /**
     * Verify failed persistence is reported and does not produce a false success.
     *
     * @return void
     */
    public function test_missing_primary_monitor_recovery_fails_when_persistence_fails() {

        $site_id = $this->add_new_test_site(
            array(
                'name' => 'Test Site Persistence Failure',
                'url'  => 'https://test-uptime-persistence.example.com/',
            )
        );

        $result = $this->repair_missing_monitor(
            $site_id
        );

        $this->assertFalse(
            $result,
            'Primary-monitor recovery should report failure when persistence fails.'
        );
    }

    /**
     * Mock the fetch_url_not_authed response for PHPUnit tests.
     *
     * Captures the requested site URL and returns a mock response without
     * performing the actual child site communication.
     *
     * @param mixed       $false_val          Default filter value. Expected to be false.
     * @param string      $url                URL being fetched.
     * @param string      $admin              Admin username or identifier.
     * @param string      $what               Action being performed.
     * @param array|null  $params             Request parameters.
     * @param bool        $pForceFetch         Whether to force the fetch.
     * @param bool|null   $verifyCertificate   Whether to verify the SSL certificate.
     * @param string|null $http_user           HTTP authentication username.
     * @param string|null $http_pass           HTTP authentication password.
     * @param int         $sslVersion          SSL version.
     * @param array       $others              Additional request options.
     * @param array       $output              Output data passed by reference.
     *
     * @return array Mock fetch response containing the site URL and WordPress version.
     */
    public function mock_fetch_not_authed(
        $false_val,
        $url,
        $admin,
        $what,
        $params,
        $pForceFetch,
        $verifyCertificate,
        $http_user,
        $http_pass,
        $sslVersion,
        $others,
        $output
    ) {
        $this->mock_fetch_not_authed_result['siteurl']   = $url;
        $this->mock_fetch_not_authed_result['wpversion'] = '7.2';
        return $this->mock_fetch_not_authed_result;
    }

    /**
     * Mock the add-site key generation filter for PHPUnit tests.
     *
     * Returns true to skip connection key generation when adding a site.
     *
     * @param bool   $skip_keys Whether to skip connection key generation.
     * @param object $website   Website object being added.
     * @param array  $params    Site connection parameters.
     *
     * @return bool True to skip connection key generation.
     */
    public function mock_add_site_skip_key_generation( $skip_keys, $website, $params ) {
        return true;
    }

	/**
	 * Return global monitoring settings for a test.
	 *
	 * @return array
	 */
	protected function get_global_settings() {
		return MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
	}

    /**
     * Update global monitoring settings with the active status.
     *
     * @param bool $active Whether uptime monitoring is active.
     *
     * @return void
     */
    protected function set_global_monitoring_enabled( $active ) {
        $settings         = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings();
        $settings['active'] = $active ? 1 : 0;
        MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $settings );
    }

    /**
     * Get the primary uptime monitor for a site.
     *
     * @param int $site_id Site ID.
     *
     * @return array|false Primary monitor data, or false if no primary monitor is found.
     */
    protected function get_primary_monitor( $site_id ) {
        return MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
    }

    /**
     * Get the primary uptime monitor for a site.
     *
     * @param int $site_id Site ID.
     *
     * @return array|false Primary monitor data, or false if no primary monitor is found.
     */
    protected function get_primary_monitors( $site_id ) {
        return MainWP_DB_Uptime_Monitoring::instance()->get_monitors(
            array(
                'wpid' => $site_id,
                'issub' => 0
            )
         );
    }

    /**
     * Repair a missing primary uptime monitor for a site.
     *
     * @param int $site_id Site ID.
     *
     * @return bool True if the primary monitor was repaired successfully, otherwise false.
     */
    protected function repair_missing_monitor( $site_id ) {
        $return = MainWP_DB_Uptime_Monitoring::instance()->repair_missing_primary_monitors( $site_id );
        return is_array($return) && isset($return['created']) && 1 === (int)$return['created'] ? true : false;
    }

    /**
     * Check whether an uptime monitor is active.
     *
     * @param object|mixed $monitor Uptime monitor data.
     *
     * @return bool True if the monitor is active, otherwise false.
     */
    protected function is_monitor_active( $monitor ) {
        $global_settings = $this->get_global_settings();
        $active = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'active', (int) $monitor->active, $global_settings, -1, 0 );
        return  ! empty( $active );
    }



    /**
     * Add a test site without using POST data.
     *
     * @param array $params Site parameters.
     *
     * @return mixed Result from adding the site.
     */
    public static function add_new_test_site( $params = array() ) {

        $defaults = array(
            'url'                => '',
            'name'               => '',
            'wpadmin'             => 'adminuser',
            'adminpwd'           => '',
            'unique_id'          => '',
            'ssl_verify'         => false,
            'ssl_version'        => false,
            'force_use_ipv4'     => null,
            'http_user'          => '',
            'http_pass'          => '',
            'groupids'           => array(),
            'groupnames_import'  => '',
            'clientid'           => 0,
            'uploaded_site_icon' => '',
            'selected_site_icon' => 'favi.icon',
            'cust_icon_color'    => '',
        );

        $params = wp_parse_args( $params, $defaults );

        list( $message, $error, $site_id, $found_id ) = MainWP_Manage_Sites_View::add_wp_site( false, $params, $output );

        return $site_id;
    }


	/**
	 * Create a test monitor.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Monitor properties.
	 *
	 * @return int
	 */
	protected function create_test_monitor( $site_id, $args = array() ) {
		global $wpdb;

		$data = array_merge(
			array(
				'wpid'            => $site_id,
				'active'          => -1,
				'interval'        => -1,
				'maxretries'      => -1,
				'retry_interval'  => 1,
				'timeout'         => -1,
				'method'          => 'get',
				'type'            => 'useglobal',
				'up_status_codes' => 'useglobal',
				'issub'           => 0,
			),
			$args
		);

        $data['issub'] =  !empty($data['suburl']) ? 1 : 0;

		$wpdb->insert( $wpdb->prefix . 'mainwp_monitors', $data );

		return (int) $wpdb->insert_id;
	}

}
