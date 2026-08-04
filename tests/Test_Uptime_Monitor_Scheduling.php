<?php
/**
 * Uptime monitor tests (MWP-1703).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Uptime_Monitor_Scheduling extends \WP_UnitTestCase {

	/**
	 * Created site IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_site_ids = [];


    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_monitors_scheduling() {

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-uptime-interval-scheduling.example.com/',
        ] );

        $data = array(
            'interval'        => 5, // 5 mins.
        );

        $monitor_id = $this->create_test_monitor( $site_id, $data );

        $time   = mainwp_get_timestamp();

        $global_settings = array(
            'interval'        => 5, // mins.
            'maxretries'      => 1,
            'active' => 1,
        );

        $params = array(
            'main_counter_lasttime' => $time - 10 * MINUTE_IN_SECONDS,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $check_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );

        $up_result = array(
            'monitor_id'     => $monitor_id,
            'last_status'    => 1, // UP.
            'last_http_code' => 200,
            'lasttime_check' => $time,
            'last_main_status' => 1, // to save the last important status for notification.
            'dts_auto_monitoring_start' => $time  - 3,
            'dts_auto_monitoring_time'       => $time - 2, // prevent equal start time.
            'dts_interval_lasttime'          => $time - 1,
        );

        // Save uptime status.
        MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $up_result );


        // Get monitors after 1 minutes,
        $params = array(
            'main_counter_lasttime' => $time + MINUTE_IN_SECONDS,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $continue_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );


        // Set down result and retry.
        $down_values = array(
            'monitor_id'                     => $monitor_id,
            'retries'                        => 1,
            'dts_auto_monitoring_time'       => $time + 1, // prevent equal start time.
            'dts_auto_monitoring_retry_time' => $time,
            'dts_interval_lasttime'          => $time,
            'last_status'    => 2, // DOWN.
            'last_http_code' => 200,
            'lasttime_check' => $time,
            'last_main_status' => 2 // to save the last important status for notification.
        );

        MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $down_values );

        $this->assertIsInt( $site_id );
        $this->assertNotEmpty( $site_id );

        $this->assertIsInt( $monitor_id );
        $this->assertNotEmpty( $monitor_id );

        $this->assertNotEmpty(
            $check_monitors,
            'Expected the monitor to be scheduled for its initial uptime check.'
        );

        $this->assertEmpty(
            $continue_monitors,
            'The monitor should not be scheduled again before the configured interval has elapsed.'
        );


         // Get monitors to retry.
        $params = array(
            'main_counter_lasttime' => $time + MINUTE_IN_SECONDS,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $retry_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );

        $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( false, 'monitor_id', $monitor_id );


        $this->assertEquals( 1, $monitor->retries );
        $this->assertEquals( $time, $monitor->dts_auto_monitoring_retry_time );
        $this->assertEquals( 2, $monitor->last_status );

        $this->assertNotEmpty(
            $retry_monitors,
            'Expected the failed monitor to be scheduled for retry after the retry interval.'
        );
    }


	/**
	 * Create a test site.
	 *
	 * Creates a site in mainwp_wp table and corresponding records in
	 * mainwp_wp_sync and mainwp_wp_options tables as needed.
	 *
	 * @param array $args Site properties.
	 * @return int Site ID.
	 */
	protected function create_test_site( array $args = [] ): int {
		global $wpdb;

		// Extract values that go to other tables (not columns in mainwp_wp).
		$verify_method = $args['verify_method'] ?? 1;
		$version       = $args['version'] ?? '5.0.0';
		$sync_errors   = $args['sync_errors'] ?? '';

		// Remove non-column fields from args before merging.
		unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

		// Defaults for mainwp_wp table columns only.
		// Use current user ID if available, otherwise use 1.
		$current_user_id = get_current_user_id();
		$defaults        = [
			'userid'               => $current_user_id > 0 ? $current_user_id : 1,
			'url'                  => 'https://test-uptime-' . wp_generate_uuid4() . '.example.com/',
			'name'                 => 'Test Site',
			'adminname'            => 'admin',
			'pubkey'               => 'test-pubkey',
			'privkey'              => 'test-privkey',
			'ssl_version'          => 0,
			'http_user'            => '',
			'http_pass'            => '',
			'suspended'            => 0,
			'offline_check_result' => 1,
			'client_id'            => 0,
		];

		// Format specifiers matching the column types.
		$formats = [
			'userid'               => '%d',
			'url'                  => '%s',
			'name'                 => '%s',
			'adminname'            => '%s',
			'pubkey'               => '%s',
			'privkey'              => '%s',
			'ssl_version'          => '%d',
			'http_user'            => '%s',
			'http_pass'            => '%s',
			'suspended'            => '%d',
			'offline_check_result' => '%d',
			'client_id'            => '%d',
		];

		$data = array_merge( $defaults, $args );

		// Build format array in same order as data keys.
		$format_array = [];
		foreach ( array_keys( $data ) as $key ) {
			$format_array[] = $formats[ $key ] ?? '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			$data,
			$format_array
		);

		$site_id = (int) $wpdb->insert_id;
		$this->created_site_ids[] = $site_id;

		// Store verify_method in options table.
		$this->set_site_option( $site_id, 'verify_method', $verify_method );

		// Create sync record with version and sync_errors.
		$this->create_test_site_sync(
			$site_id,
			[
				'version'     => $version,
				'sync_errors' => $sync_errors,
			]
		);

		return $site_id;
	}

    /**
	 * Create a test site monitor.
	 *
	 * @param int $site_id Site ID.
     * @param array $args Monitor data.
	 * @return int Monitor ID.
	 */
	protected function create_test_monitor( $site_id, $args = array() ): int {

        $data = array(
            'wpid'            => $site_id,
            'active'          => 1,
            'interval'        => 5, // 5 mins.
            'timeout'         => -1,
            'method'          => 'get',
            'type'            => 'useglobal',
            'up_status_codes' => 'useglobal',
            'issub'           => 0, // primary monitor.
        );

        $data = array_merge( $data, $args );

        global $wpdb;

        $table = $wpdb->prefix . 'mainwp_monitors';

        $wpdb->insert( $table, $data );

        return $wpdb->insert_id;
	}

    /**
	 * Set a site option via MainWP's wp_options table.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $option  Option name.
	 * @param mixed  $value   Option value.
	 * @return void
	 */
	protected function set_site_option( int $site_id, string $option, $value ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mainwp_wp_options';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE wpid = %d AND name = %s",
				$site_id,
				$option
			)
		);

		$serialized = is_scalar( $value ) ? $value : maybe_serialize( $value );

		if ( $exists ) {
			$wpdb->update(
				$table,
				[ 'value' => $serialized ],
				[
					'wpid' => $site_id,
					'name' => $option,
				],
				[ '%s' ],
				[ '%d', '%s' ]
			);
		} else {
			$wpdb->insert(
				$table,
				[
					'wpid'  => $site_id,
					'name'  => $option,
					'value' => $serialized,
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

    /**
	 * Create a sync record for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Sync properties.
	 * @return void
	 */
	protected function create_test_site_sync( int $site_id, array $args = [] ): void {
		global $wpdb;

		$defaults = [
			'wpid'        => $site_id,
			'version'     => '5.0.0',
			'sync_errors' => '',
		];

		$data = array_merge( $defaults, $args );
		$data['wpid'] = $site_id;

		// Build format array dynamically to match $data keys/values.
		$formats = [];
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			$data,
			$formats
		);
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		// Get site IDs before deleting main records.
		$site_ids = $this->created_site_ids;

		// Clean up related tables for tracked site IDs.
		if ( ! empty( $site_ids ) ) {
			$ids_placeholder = implode( ',', array_map( 'intval', $site_ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid IN ({$ids_placeholder})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid IN ({$ids_placeholder})" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_monitors WHERE wpid IN ({$ids_placeholder})" );
		}

		// Also clean by URL pattern (catches any sites not in created_site_ids).
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}mainwp_wp_sync
			 WHERE wpid IN (SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-uptime-%')"
		);
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}mainwp_wp_options
			 WHERE wpid IN (SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-uptime--%')"
		);

		// Clean up test sites (main table - do this AFTER related tables).
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-uptime--%'" );

		parent::tearDown();
	}

}
