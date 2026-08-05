<?php
/**
 * Uptime monitor tests (MWP-1703).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Schedule;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

class Test_Uptime_Monitor_Scheduling extends \WP_UnitTestCase {

    const DOWN    = 0;
    const UP      = 1;
    const PENDING = 2;

    /**
     * Created site IDs for test.
     *
     * @var array
     */
    protected $created_site_ids = array();

    /**
     * Created site id for test.
     *
     * @var array
     */
    protected $site_id = 0;


    /**
     * Created monitor for test.
     *
     * @var array
     */
    protected $monitor = array();

    /**
     * Created monitor for test.
     *
     * @var array
     */
    protected $simulate_recent_test = '';

    /**
     * Hook most recent uptime response data.
     *
     * @var array
     */
    protected $most_recent_uptime_check_response = array();

    /**
     * Hook sending uptime notification object.
     *
     * @var array
     */
    protected $sending_uptime_notification_object = array();


    /**
     * Set up test environment.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();

        add_filter( 'mainwp_uptime_monitor_check_result', array( $this, 'hook_uptime_monitor_check_result' ), 10, 5 );
        add_filter( 'mainwp_wp_mail_to', array( $this, 'hook_mainwp_wp_mail_to' ), 10, 5 );

        $site_id = $this->create_test_site(
            array(
                'name' => 'Test Site',
                'url'  => 'https://test-uptime-interval-scheduling.example.com/',
            )
        );

        $this->created_site_ids[] = $site_id;
        $this->create_test_monitor( $site_id );
    }

    /**
     * Filters the uptime monitor check result.
     *
     * @param array $resp_info  Response information.
     * @param array $data       Check response data.
     * @param array $monitor    Monitor data.
     * @param array $output     Check output.
     * @param array $params     Additional parameters.
     *
     * @return array Modified response information.
     */
    public function hook_uptime_monitor_check_result( $resp_info, $data, $monitor, $output, $params ) {
        if ( ! empty( $resp_info ) && is_array( $resp_info ) && $this->monitor && $monitor && $this->monitor->monitor_id === $monitor->monitor_id ) {
            if ( 'runtest_1' === $this->simulate_recent_test ) {
                // Simulate a response that triggers an uptime retry check in the next scheduled run.
                $resp_info['http_code']                  = 0;
                $this->most_recent_uptime_check_response[$monitor->monitor_id] = $resp_info;
            } elseif ( 'runtest_2' === $this->simulate_recent_test ) {
                // Simulate a response that triggers an uptime retry check in the next scheduled run.
                $resp_info['http_code']                  = 200;
                $resp_info['status']                     = static::DOWN;
                $resp_info['retry']                     = 1; //  so it will do not set retry again.
                $this->most_recent_uptime_check_response[$monitor->monitor_id] = $resp_info;
            } elseif ( 'runtest_3' === $this->simulate_recent_test ) {
                $resp_info['retry']                     = 1; //  so it will do not set retry again.
                $this->most_recent_uptime_check_response[$monitor->monitor_id] = $resp_info;
            }
        }
        return $resp_info;
    }


    /**
     * Handles the `hook_mainwp_wp_mail_to` filter.
     *
     * @param string       $email           Recipient email address.
     * @param string       $subject         Email subject.
     * @param string       $mail_content    Email message content.
     * @param string       $content_type    Email content type.
     * @param object|mixed $object_sending  Object associated with the email being sent.
     *
     * @return string email.
     */
    public function hook_mainwp_wp_mail_to(
        $email,
        $subject,
        $mail_content,
        $content_type,
        $object_sending
    ) {
        if ( ! empty( $object_sending ) ) {
            if ( is_object( $object_sending ) && ! empty( $object_sending->monitor_id ) &&  $object_sending->monitor_id === $this->monitor->monitor_id ) {
                $this->sending_uptime_notification_object = $object_sending;
            } elseif ( is_array( $object_sending ) ) {
                foreach ( $object_sending as $obj_status ) {
                    if ( ! empty( $obj_status->monitor_id ) && $this->monitor->monitor_id === $obj_status->monitor_id ) {
                        $this->sending_uptime_notification_object = $obj_status;


                    }
                }
            }

            if ( ! empty( $this->sending_uptime_notification_object ) ) {
                return ''; // to prevent sending email.
            }
        }

        return $email;
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
			 WHERE wpid IN (SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-uptime-%')"
        );

        // Clean up test sites (main table - do this AFTER related tables).
        $wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-uptime-%'" );

        parent::tearDown();
    }


    /**
     * Tests the complete uptime monitoring scheduling flow, including:
     *
     * - Initial monitor scheduling.
     * - Retry scheduling after a failed check.
     * - Regular interval scheduling.
     * - Heartbeat creation.
     * - Notification scheduling and sending.
     *
     * @return void
     */
    public function test_monitor_is_scheduled_retried_and_notification_is_sent() {

        $this->site_id = ! empty( $this->created_site_ids ) ? current( $this->created_site_ids ) : 0;
        $site_id       = $this->site_id;
        $this->monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
        $monitor_id    = $this->monitor ? $this->monitor->monitor_id : 0;

        // Created site and monitor for testing.
        $this->assertNotEmpty( $site_id, 'Site was not created.' );
        $this->assertNotEmpty( $monitor_id, 'Monitor was not created.' );

        $global_settings = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings();

        $global_settings['interval'] = 5; // mins.

        // Get monitor for checking.
        $time = mainwp_get_timestamp();

        $params = array(
            'main_counter_lasttime' => $time,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $monitors_found1 = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );

        $this->assertNotEmpty(
            $monitors_found1,
            'Expected the monitor to be scheduled for its initial uptime check.'
        );

        $this->simulate_recent_test = 'runtest_1';
        MainWP_Uptime_Monitoring_Connect::instance()->check_monitors( $monitors_found1, $global_settings );

        $this->assertSame( 'runtest_1', $this->simulate_recent_test,  'The initial uptime check was not executed.' );
        $this->assertNotEmpty( $this->most_recent_uptime_check_response, 'Expected an uptime check response after the initial check.' );
        sleep( 1 );
        $params = array(
            'local_timestamp'       => $time + MINUTE_IN_SECONDS + 1, // simulate current time.
            'main_counter_lasttime' => $time,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $monitors_found2 = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );

        $this->simulate_recent_test = 'runtest_2';
        MainWP_Uptime_Monitoring_Connect::instance()->check_monitors( $monitors_found2, $global_settings );

        $this->assertNotEmpty( $monitors_found2, 'Expected the monitor to be scheduled for a retry.' );
        $this->assertSame( 'runtest_2', $this->simulate_recent_test, 'The retry uptime check was not executed.' );
        $this->assertNotEmpty( $this->most_recent_uptime_check_response, 'Expected an uptime check response after the retry.' );


        sleep( 1 );
        $params = array(
            'local_timestamp'       => $time + 5 * MINUTE_IN_SECONDS + 1, // simulate current time.
            'main_counter_lasttime' => $time + 5 * MINUTE_IN_SECONDS,
            'global_settings'       => $global_settings,
            'limit'                 => 10,
            'dev_log_query'         => 0, // 1 for dev logs.
        );

        $monitors_found3 = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params );

        $this->simulate_recent_test = 'runtest_3';
        MainWP_Uptime_Monitoring_Connect::instance()->check_monitors( $monitors_found3, $global_settings );

        $this->assertNotEmpty( $monitors_found3, 'Expected the monitor to be scheduled for the regular interval check.' );
        $this->assertNotEmpty( $this->most_recent_uptime_check_response, 'Monitor uptime response' );
        $heartbeats = MainWP_DB_Uptime_Monitoring::instance()->get_heartbeat_data_for_incidents( $monitor_id );

        $this->assertNotEmpty( $heartbeats, 'Expected heartbeat records to be created.' );
        $last_heartbeat = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_heartbeat( $site_id );

        $this->assertNotEmpty( $last_heartbeat, 'Expected a latest heartbeat record.' );
        $this->assertSame( 1, $last_heartbeat ? (int)$last_heartbeat->importance : 0, 'Expected the latest heartbeat importance to be 1.' );

        $process_init = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_start_send( 50 );

        $this->assertNotEmpty( $process_init, 'Expected a notification process to be created.' );

        if ( is_array( $process_init ) && ! empty( $process_init ) ) {

            foreach ( $process_init as $uptime_notice ) {
                if ( ! empty( $uptime_notice->process_id ) ) {
                    MainWP_DB::instance()->update_regular_process(
                        array(
                            'process_id'        => $uptime_notice->process_id,
                            'dts_process_start' => $time, // set start time to current time, to continue processs.
                        )
                    );
                }
            }
        }

        $process_notices = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_continue_send( array( 'limit' => 3 ) );
        $this->assertNotEmpty( $process_notices, 'Expected a notification process ready to continue sending.' );

        if ( is_array( $process_notices ) && ! empty( $process_notices ) ) {
            $simulate_params = array(
                'local_time'     => $time,
                'admin_email'    => 'admin-testing-uptime@local.com',
                'email_settings' => array(
                    'disable'    => 0,
                    'recipients' => 'admin-testing-uptime@local.com',
                    'subject'    => 'Uptime Monitoring Alert from your MainWP Dashboard',
                    'heading'    => 'Uptime Monitoring',
                ),
            );
            MainWP_Uptime_Monitoring_Schedule::instance()->send_uptime_notification_importance_status( $process_notices, $simulate_params );
        }
        $this->assertNotEmpty( $this->sending_uptime_notification_object,  'Expected an uptime notification to be sent.' );
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
    protected function create_test_site( array $args = array() ): int {
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
        $defaults        = array(
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
        );

        // Format specifiers matching the column types.
        $formats = array(
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
        );

        $data = array_merge( $defaults, $args );

        // Build format array in same order as data keys.
        $format_array = array();
        foreach ( array_keys( $data ) as $key ) {
            $format_array[] = $formats[ $key ] ?? '%s';
        }

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp',
            $data,
            $format_array
        );

        $site_id                  = (int) $wpdb->insert_id;
        $this->created_site_ids[] = $site_id;

        // Store verify_method in options table.
        $this->set_site_option( $site_id, 'verify_method', $verify_method );

        // Create sync record with version and sync_errors.
        $this->create_test_site_sync(
            $site_id,
            array(
                'version'     => $version,
                'sync_errors' => $sync_errors,
            )
        );

        return $site_id;
    }

    /**
     * Create a test site monitor.
     *
     * @param int   $site_id Site ID.
     * @param array $args Monitor data.
     * @return int Monitor ID.
     */
    protected function create_test_monitor( $site_id, $args = array() ): int {

        $data = array(
            'wpid'            => $site_id,
            'active'          => 1,
            'interval'        => -1, // 5 mins.
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
                array( 'value' => $serialized ),
                array(
                    'wpid' => $site_id,
                    'name' => $option,
                ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'wpid'  => $site_id,
                    'name'  => $option,
                    'value' => $serialized,
                ),
                array( '%d', '%s', '%s' )
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
    protected function create_test_site_sync( int $site_id, array $args = array() ): void {
        global $wpdb;

        $defaults = array(
            'wpid'        => $site_id,
            'version'     => '5.0.0',
            'sync_errors' => '',
        );

        $data         = array_merge( $defaults, $args );
        $data['wpid'] = $site_id;

        // Build format array dynamically to match $data keys/values.
        $formats = array();
        foreach ( $data as $value ) {
            $formats[] = is_int( $value ) ? '%d' : '%s';
        }

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_sync',
            $data,
            $formats
        );
    }
}
