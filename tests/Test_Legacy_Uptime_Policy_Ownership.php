<?php
/**
 * Site ownership in legacy uptime and password policy AJAX handlers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A dedicated Error marks captured handler termination.
// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- Match the PHPUnit test filename convention.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Requests exercise the production nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Request globals are preserved for teardown.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Response text is captured for assertions, never rendered.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\Policy_Ownership_Password_Settings;
use MainWP\Dashboard\Policy_Ownership_Uptime_Widget;

/** Termination must bypass production catch (Exception) blocks. */
class Legacy_Uptime_Policy_Stop extends \Error {}

/** Exercise real lookups, nonce checks, writes and monitoring queries. */
class Test_Legacy_Uptime_Policy_Ownership extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Saved request globals and user.
     *
     * @var array
     */
    private $state;

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Child requests captured before transport.
     *
     * @var array
     */
    private $requests = array();

    /**
     * Monitoring queries issued by the handler.
     *
     * @var array
     */
    private $monitor_queries = array();

    /**
     * Current user's site.
     *
     * @var int
     */
    private $own_site;

    /**
     * Another administrator's site.
     *
     * @var int
     */
    private $foreign_site;

    /** Capture termination while retaining production nonce checks and database work. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Uptime_Monitoring_Site_Widget' => 'Policy_Ownership_Uptime_Widget',
            'MainWP_Password_Policy_Settings'      => 'Policy_Ownership_Password_Settings',
        );
        foreach ( array( 'widgets/widget-mainwp-uptime-monitoring-site-widget.php', 'pages/page-mainwp-password-policy-settings.php' ) as $file ) {
            $code = '';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read production handlers for termination capture.
            $source = file_get_contents( dirname( __DIR__ ) . '/' . $file );
            foreach ( token_get_all( $source ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            // The update handler catches Error; only the test termination sentinel must escape it.
            $code = str_replace( 'catch ( \Error $e ) {', 'catch ( \Error $e ) { if ( $e instanceof \MainWP\Dashboard\Tests\Legacy_Uptime_Policy_Stop ) { throw $e; }', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture termination while retaining production nonce checks, lookups and writes.
        }
    }

    /** Create two administrators with separately owned sites. */
    public function setUp(): void {
        parent::setUp();
        $this->state = array( $_GET, $_POST, $_REQUEST, get_current_user_id(), $GLOBALS['current_screen'] ?? null );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $other              = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $this->own_site     = $this->create_test_site();
        $this->foreign_site = $this->create_test_site( array( 'userid' => $other ) );
        set_current_screen( 'dashboard' );
        $this->filter( 'mainwp_is_single_user', '__return_false' );
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () {
                return 'all';
            }
        );
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        $this->filter( 'wp_doing_ajax', '__return_true' );
        $this->filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
        $this->filter(
            'mainwp_fetch_url_authed_pre',
            function ( $pre, $website, $what, $params ) {
                $this->requests[] = array( (int) $website->id, $what, $params );
                return array( 'result' => 'SUCCESS' );
            }
        );
        $this->filter(
            'pre_http_request',
            static function () {
                throw new \Error( 'Unexpected HTTP request in ownership tests.' );
            }
        );
    }

    /** Restore request and filter state after each simulated request. */
    public function tearDown(): void {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        list( $_GET, $_POST, $_REQUEST, $user, $screen ) = $this->state;
        wp_set_current_user( $user );
        $GLOBALS['current_screen'] = $screen;
        parent::tearDown();
    }

    /**
     * Ownership and Team Control cases.
     *
     * @return array Request cases.
     */
    public static function access_cases(): array {
        return array(
            'foreign multi-user'       => array( 'foreign', false ),
            'own multi-user'           => array( 'own', true ),
            'foreign single-user'      => array( 'single', true ),
            'missing target'           => array( 'missing', false ),
            'Team Control site denial' => array( 'team', false ),
        );
    }

    /**
     * Test both individual-policy save branches independently.
     *
     * @return array Request cases and override switches.
     */
    public static function save_cases(): array {
        $cases = array();
        foreach ( self::access_cases() as $name => $case ) {
            foreach ( array( '1', '0' ) as $overwrite ) {
                $cases[ $name . ' override ' . $overwrite ] = array_merge( $case, array( $overwrite ) );
            }
        }
        return $cases;
    }

    /**
     * Refused monitoring requests expose no stats and issue no monitor query.
     *
     * @dataProvider access_cases
     * @param string $scope Viewer scope.
     * @param bool   $allowed Expected access.
     */
    public function test_uptime_response_times( string $scope, bool $allowed ): void {
        global $wpdb;
        $site_id = $this->select_target( $scope );
        $this->seed_monitor( $this->own_site );
        $this->seed_monitor( $this->foreign_site );
        $this->filter(
            'query',
            function ( $sql ) use ( $wpdb ) {
                if ( false !== strpos( $sql, $wpdb->prefix . 'mainwp_monitors' ) || false !== strpos( $sql, $wpdb->prefix . 'mainwp_monitor_' ) ) {
                    $this->monitor_queries[] = $sql;
                }
                return $sql;
            }
        );
        $this->request(
            'mainwp_uptime_monitoring_get_response_times',
            array(
                'siteid'   => $site_id,
                'period'   => 'week',
                'dtsstart' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
                'dtsstop'  => gmdate( 'Y-m-d', time() ),
            )
        );
        $body = $this->capture( array( Policy_Ownership_Uptime_Widget::instance(), 'ajax_get_response_times' ) );
        $this->assertSame( array(), $this->requests );
        if ( ! $allowed ) {
            $this->assertArrayNotHasKey( 'data_stats', $body, 'Denied uptime request disclosed stats.' );
            $this->assertArrayNotHasKey( 'data', $body );
            $this->assertSame( array( 'error' => 'The Site ID is invalid or not found. Please try again.' ), $body );
            $this->assertSame( array(), $this->monitor_queries, 'Denied request queried monitoring tables.' );
            return;
        }
        $this->assertNotEmpty( $this->monitor_queries );
        $this->assertNotEmpty( $body['data'] );
        $this->assertSame( '1.25', $body['data_stats']['avg_resp_time'] );
        $this->assertSame( '1', $body['data_stats']['current_status'] );
        $this->assertSame( '200', $body['data_stats']['http_code'] );
        $this->assertSame( '1', $body['data_stats']['incidents_count'] );
        $this->assertSame( '0.7500000000', $body['data_stats']['ratios_number'] );
    }

    /**
     * Both save branches must authorize before local writes or Child dispatch.
     *
     * @dataProvider save_cases
     * @param string $scope Viewer scope.
     * @param bool   $allowed Expected access.
     * @param string $overwrite Override switch.
     */
    public function test_individual_policy_save( string $scope, bool $allowed, string $overwrite ): void {
        $site_id        = $this->select_target( $scope );
        $before         = $this->seed_policy( $site_id );
        $this->requests = array();
        $this->request( 'mainwp_password_policy_save_individual', array_merge( $this->policy_input( $site_id ), array( 'overwrite_enabled' => $overwrite ) ) );
        $body  = $this->capture( array( Policy_Ownership_Password_Settings::class, 'ajax_save_individual' ) );
        $after = $this->read_policy( $site_id );
        if ( ! $allowed ) {
            $this->assertSame( $before, $after, 'Denied save changed stored policy.' );
            $this->assertSame( array(), $this->requests, 'Denied save dispatched to Child.' );
            $this->assertSame(
                array(
                    'success' => false,
                    'data'    => array( 'message' => 'Site not found' ),
                ),
                $body
            );
            return;
        }
        $this->assertTrue( $body['success'] );
        $expected = '1' === $overwrite ? array_merge( array( 'overwrite_enabled' => true ), $this->policy_settings() ) : array( 'overwrite_enabled' => false );
        $this->assertSame( $expected, json_decode( $after, true ) );
        $this->assertSame( '1' === $overwrite ? array( array( $site_id, 'password_policy_settings', $this->expected_payload() ) ) : array(), $this->requests );
    }

    /**
     * Updating a site's policy must authorize before reading overrides or sending.
     *
     * @dataProvider access_cases
     * @param string $scope Viewer scope.
     * @param bool   $allowed Expected access.
     */
    public function test_policy_update( string $scope, bool $allowed ): void {
        $site_id = $this->select_target( $scope );
        $before  = $this->seed_policy( $site_id );
        $this->request( 'mainwp_password_policy_update_site', $this->policy_input( $site_id ) );
        $body = $this->capture( array( Policy_Ownership_Password_Settings::class, 'ajax_update_site' ) );
        $this->assertSame( $before, $this->read_policy( $site_id ) );
        $this->assertSame( $allowed ? array( array( $site_id, 'password_policy_settings', $this->expected_payload() ) ) : array(), $this->requests );
        $this->assertSame( $allowed ? array( 'success' => true ) : array( 'error' => 'Site not found' ), $body );
    }

    /** The existing manage_users gate still refuses both password handlers. */
    public function test_manage_users_denial(): void {
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $right ) {
                return 'manage_users' === $right ? false : $can;
            }
        );
        $before = $this->seed_policy( $this->own_site );
        foreach ( array( 'save_individual', 'update_site' ) as $handler ) {
            $this->request( 'mainwp_password_policy_' . $handler, $this->policy_input( $this->own_site ) );
            $body = $this->capture( array( Policy_Ownership_Password_Settings::class, 'ajax_' . $handler ) );
            $this->assertSame( $before, $this->read_policy( $this->own_site ) );
            $this->assertSame( array(), $this->requests );
            if ( 'save_individual' === $handler ) {
                $this->assertFalse( $body['success'] );
                $this->assertNotEmpty( $body['data']['message'] );
            } else {
                $this->assertNotEmpty( $body['error'] );
            }
        }
    }

    /**
     * Select a real owned or foreign site, or an absent target.
     *
     * @param string $scope Viewer scope.
     * @return int Target ID.
     */
    private function select_target( string $scope ): int {
        if ( 'single' === $scope ) {
            remove_filter( 'mainwp_is_single_user', $this->filters['mainwp_is_single_user'], 999 );
            unset( $this->filters['mainwp_is_single_user'] );
            $this->assertTrue( MainWP_System::instance()->is_single_user(), 'Default single-user mode must be enabled.' );
        } else {
            $this->assertFalse( MainWP_System::instance()->is_single_user() );
        }
        if ( 'team' === $scope ) {
            $this->filter( 'mainwp_currentuserallowedaccesssites', '__return_empty_array' );
        }
        return 'missing' === $scope ? 999999999 : ( 'foreign' === $scope || 'single' === $scope ? $this->foreign_site : $this->own_site );
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Seed monitoring rows in the disposable test database.

    /**
     * Seed real monitoring rows with response and status data.
     *
     * @param int $site_id Target ID.
     */
    private function seed_monitor( int $site_id ): void {
        global $wpdb;
        $this->assertSame(
            1,
            $wpdb->insert(
                $wpdb->prefix . 'mainwp_monitors',
                array(
                    'wpid'           => $site_id,
                    'active'         => 1,
                    'interval'       => 60,
                    'lasttime_check' => time(),
                )
            )
        );
        $monitor_id = (int) $wpdb->insert_id;
        foreach ( array( 0, 1 ) as $status ) {
            $this->assertSame(
                1,
                $wpdb->insert(
                    $wpdb->prefix . 'mainwp_monitor_heartbeat',
                    array(
                        'monitor_id' => $monitor_id,
                        'msg'        => 'fixture',
                        'importance' => 1,
                        'status'     => $status,
                        'time'       => gmdate( 'Y-m-d H:i:s', time() - ( $status ? 60 : 120 ) ),
                        'ping_ms'    => 1250,
                        'duration'   => $status ? 180 : 60,
                        'http_code'  => $status ? 200 : 503,
                    )
                )
            );
        }
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

    /**
     * Seed an override-disabled policy so update reaches Child transport.
     *
     * @param int $site_id Target ID.
     * @return string Stored policy.
     */
    private function seed_policy( int $site_id ): string {
        $before = wp_json_encode(
            array(
                'overwrite_enabled' => false,
                'max_age_days'      => 30,
            )
        );
        MainWP_DB::instance()->update_website_option( (object) array( 'id' => $site_id ), 'password_policy_individual_settings', $before );
        $this->assertSame( $before, $this->read_policy( $site_id ) );
        return $before;
    }

    /**
     * Read policy without applying the viewer's access filter.
     *
     * @param int $site_id Target ID.
     * @return string Stored JSON.
     */
    private function read_policy( int $site_id ): string {
        return MainWP_DB::instance()->get_website_option( (object) array( 'id' => $site_id ), 'password_policy_individual_settings' );
    }

    /**
     * Expected stored policy values.
     *
     * @return array
     */
    private function policy_settings(): array {
        return array(
            'max_age_days'     => 90,
            'due_soon_message' => 'Due soon fixture',
            'overdue_message'  => 'Overdue fixture',
            'show_notices_to'  => 'all_users',
        );
    }

    /**
     * Build the actual AJAX input.
     *
     * @param int $site_id Target ID.
     * @return array Request fields.
     */
    private function policy_input( int $site_id ): array {
        return array_merge(
            array(
                'site_id'           => $site_id,
                'overwrite_enabled' => '1',
                'due_soon_days'     => 7,
            ),
            $this->policy_settings()
        );
    }

    /**
     * Expected Child command data.
     *
     * @return array
     */
    private function expected_payload(): array {
        return array(
            'action'           => 'update_password_policy',
            'max_age_days'     => 90,
            'due_soon_days'    => 7,
            'due_soon_message' => 'Due soon fixture',
            'overdue_message'  => 'Overdue fixture',
            'show_notices_to'  => 'all_users',
        );
    }

    /**
     * Supply a valid nonce to each source handler.
     *
     * @param string $action Nonce action.
     * @param array  $fields Request fields.
     */
    private function request( string $action, array $fields ): void {
        $_GET     = array();
        $nonce    = wp_create_nonce( $action );
        $_POST    = array_merge( $fields, array( 'security' => $nonce ) );
        $_REQUEST = $_POST;
        $this->assertNotFalse( wp_verify_nonce( $nonce, $action ) );
    }

    /**
     * Capture JSON without allowing wp_die to end the test process.
     *
     * @param callable $callback Source handler.
     * @return array JSON response.
     */
    private function capture( callable $callback ): array {
        ob_start();
        $message = '';
        try {
            $callback();
        } catch ( Legacy_Uptime_Policy_Stop $stop ) {
            $message = $stop->getMessage();
        } finally {
            $body = ob_get_clean();
        }
        $decoded = json_decode( $body . $message, true );
        $this->assertIsArray( $decoded, 'Handler must return JSON: ' . $body . $message );
        return $decoded;
    }

    /**
     * AJAX termination callback.
     *
     * @return callable
     */
    public function die_handler(): callable {
        return static function ( $message ) {
            throw new Legacy_Uptime_Policy_Stop( (string) $message );
        };
    }

    /**
     * Replace only callbacks installed by this test.
     *
     * @param string   $hook Hook name.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 4 );
    }
}
