<?php
/**
 * Log and Dashboard data writers must enforce their own MainWP rights.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Captured exits belong to this regression harness.
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Fixtures exercise production nonce verification.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Responses are captured for assertions, never sent to a browser.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Source copies allow legacy exits to be captured.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Queries use internal table names and fixed fixture data.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Connection-local temporary tables isolate the real SQL.
// phpcs:disable WordPress.DB.SlowDBQuery -- Single-row metadata fixtures do not run in production.

use MainWP\Dashboard\Logs_Data_Post_Handler;
use MainWP\Dashboard\Logs_Data_Demo_Handle;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Demo_Handle;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Password_Policy_Settings;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Server_Information;
use MainWP\Dashboard\Module\Log\Log_DB_Archive;
use MainWP\Dashboard\Module\Log\Log_DB;
use MainWP\Dashboard\Module\Log\Log_DB_Helper;
use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Module\Log\Logs_Data_Log_Admin;
use MainWP\Dashboard\Module\Log\Logs_Data_Insights_Page;

/** An Error escapes legacy handlers that catch Exception. */
class Logs_Data_Response_Stop extends \Error {}

/** Record compact dispatch while retaining the production SQL worker. */
class Logs_Data_Compact_DB extends Log_DB {

    /**
     * Submitted ranges.
     *
     * @var array
     */
    public $ranges = array();

    /**
     * Observe entry without changing dates or database operations.
     *
     * @param int $start_time Range start.
     * @param int $end_time Range end.
     * @return mixed Worker result.
     */
    public function create_compact_and_erase_records( $start_time, $end_time ) {
        $this->ranges[] = array( $start_time, $end_time );
        return parent::create_compact_and_erase_records( $start_time, $end_time );
    }
}

/** Exercise real writers while preserving unrelated database state. */
class Test_Dashboard_Logs_Data_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Connection-local fixture tables.
     *
     * @var array
     */
    private $tables = array();

    /**
     * Original database prefixes.
     *
     * @var array
     */
    private $db_prefixes = array();

    /**
     * Original wpdb logging table names.
     *
     * @var array
     */
    private $log_tables = array();

    /**
     * Original logger and demo cache properties.
     *
     * @var array
     */
    private $properties = array();

    /**
     * Original PHP session data.
     *
     * @var array
     */
    private $session = array();

    /** Capture exits without changing guards, inputs, or writer behavior. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Base_Handler'        => 'Logs_Data_Base_Handler',
            'MainWP_Post_Handler'             => 'Logs_Data_Post_Handler',
            'MainWP_Demo_Handle'              => 'Logs_Data_Demo_Handle',
            'Log_Admin'                       => 'Logs_Data_Log_Admin',
            'Log_Manage_Insights_Events_Page' => 'Logs_Data_Insights_Page',
        );
        foreach ( array(
            'class/class-mainwp-post-base-handler.php',
            'class/class-mainwp-post-handler.php',
            'class/class-mainwp-demo-handle.php',
            'modules/logs/classes/class-log-admin.php',
            'modules/logs/pages/page-log-manage-insights-events-page.php',
        ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( ( T_STRING === $token[0] || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] ) ) ? strtr( $token[1], $names ) : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            if ( 'class/class-mainwp-demo-handle.php' === $file ) {
                // CLI output prevents session_start; retain the actual session cleanup.
                $code = str_replace( 'MainWP_Cache::init_session();', '', $code );
            }
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits while executing the source guards and writes.
        }
    }

    /** Prime nonce salts before any option snapshot. */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        set_current_screen( 'dashboard' );
        wp_create_nonce( 'mainwp-logs-data-fixture' );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        $this->filter(
            'mainwp_currentuserallowedaccesssites',
            static function () {
                return 'all';
            }
        );
        $this->filter( 'mainwp_logger_to_db', '__return_true' );
        foreach ( array( 'logPriority', 'logSpecific' ) as $name ) {
            $this->preserve_property( MainWP_Logger::instance(), $name );
        }
        $this->preserve_property( MainWP_Demo_Handle::get_instance(), 'demo_website_ids' );
        $this->preserve_property( Logs_Data_Demo_Handle::get_instance(), 'demo_website_ids' );
        $this->session = $_SESSION ?? array();
        $_SESSION      = array();
        update_option( 'mainwp_ajaxposts', array( 'fixture' => 'preserve' ) );
        update_option(
            'mainwp_showhide_events_notice',
            array(
                'request_reviews1'           => 30,
                'request_reviews1_starttime' => time(),
            )
        );
    }

    /** Restore table routing, cached properties, filters and request state. */
    public function tearDown(): void {
        global $wpdb;
        foreach ( $this->tables as $table ) {
            $wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $table" );
        }
        foreach ( $this->db_prefixes as $class => $prefix ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $property->setValue( $class::instance(), $prefix );
        }
        foreach ( $this->log_tables as $name => $table ) {
            $wpdb->$name = $table;
        }
        foreach ( $this->properties as $entry ) {
            $entry[0]->setValue( $entry[1], $entry[2] );
        }
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $_SESSION = $this->session;
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Permitted requests, restricted administrators and non-admins.
     *
     * @return array Writer and effective request permissions.
     */
    public static function ajax_writers(): array {
        $cases = array();
        foreach ( array( 'delete', 'compact', 'dismiss', 'dismiss_all', 'import_demo', 'delete_demo' ) as $writer ) {
            $cases[ $writer . ' allowed' ]   = array( $writer, true, true );
            $cases[ $writer . ' refused' ]   = array( $writer, false, true );
            $cases[ $writer . ' non-admin' ] = array( $writer, true, false );
        }
        return $cases;
    }

    /**
     * Refusal precedes reads, SQL mutations and duplicate-request writes.
     *
     * @dataProvider ajax_writers
     * @param string $writer Writer name.
     * @param bool   $allowed MainWP right.
     * @param bool   $admin WordPress administrator.
     */
    public function test_ajax_writer_right( string $writer, bool $allowed, bool $admin ): void {
        global $wpdb;
        $insights = in_array( $writer, array( 'dismiss', 'dismiss_all' ), true );
        $demo     = in_array( $writer, array( 'import_demo', 'delete_demo' ), true );
        $this->right( $insights ? 'manage_insights_actions' : 'manage_dashboard_settings', $allowed );
        $this->network_tables();
        $post = array();
        if ( $demo ) {
            update_option( 'mainwp_enable_guided_tours', 'delete_demo' === $writer ? 1 : 0 );
            update_option( 'mainwp_setting_demo_mode_enabled', 'delete_demo' === $writer ? 1 : 0 );
            $site = $this->create_test_site( array( 'url' => 'https://logs-data-fixture.example/' ) );
            update_option(
                'mainwp_demo_mode_added_ids',
                array(
                    'sites_ids'   => array( $site ),
                    'clients_ids' => array(),
                )
            );
            $property = new \ReflectionProperty( MainWP_Demo_Handle::class, 'demo_website_ids' );
            $property->setAccessible( true );
            $property->setValue( null, array( $site ) );
            $property = new \ReflectionProperty( Logs_Data_Demo_Handle::class, 'demo_website_ids' );
            $property->setAccessible( true );
            $property->setValue( null, array( $site ) );
            $action   = 'import_demo' === $writer ? 'mainwp_import_demo_data' : 'mainwp_delete_demo_data';
            $callback = array( Logs_Data_Post_Handler::instance(), 'import_demo' === $writer ? 'ajax_import_demo_data' : 'ajax_delete_demo_data' );
        } elseif ( $insights ) {
            $page     = ( new \ReflectionClass( Logs_Data_Insights_Page::class ) )->newInstanceWithoutConstructor();
            $action   = 'dismiss' === $writer ? 'mainwp_insight_events_dismiss_actions' : 'mainwp_insight_events_dismiss_all';
            $callback = array( $page, 'dismiss' === $writer ? 'ajax_sites_changes_dismiss_selected' : 'ajax_sites_changes_dismiss_all' );
            $post     = array( 'log_id' => '901' );
        } else {
            $page          = ( new \ReflectionClass( Logs_Data_Log_Admin::class ) )->newInstanceWithoutConstructor();
            $db            = new Logs_Data_Compact_DB( Log_Manager::instance()->db->driver );
            $page->manager = (object) array( 'db' => $db );
            $action        = 'delete' === $writer ? 'mainwp_module_log_delete_records' : 'mainwp_module_log_compact_records';
            $callback      = array( $page, 'delete' === $writer ? 'ajax_delete_records' : 'ajax_compact_records' );
            $post          = array(
                'startdate' => '2026-01-01',
                'enddate'   => '2026-12-31',
                'year'      => '2026',
            );
        }
        if ( ! $admin ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
        }
        $_POST    = array_merge(
            $post,
            array(
                'security' => wp_create_nonce( $action ),
                'dts'      => wp_generate_uuid4(),
            )
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        $queries  = array();
        $tables   = $this->tables;
        $this->filter(
            'query',
            static function ( $sql ) use ( &$queries, $tables ) {
                foreach ( $tables as $table ) {
                    if ( false !== strpos( $sql, $table ) ) {
                        $queries[] = $sql;
                        break;
                    }
                }
                return $sql;
            }
        );
        $response = $this->capture( $callback );
        $observed = isset( $db ) ? $db->ranges : $queries;
        if ( ! $allowed || ! $admin ) {
            $this->assertSame( $before, $this->snapshot(), 'Refusal changed options or rows, including mainwp_ajaxposts.' );
            $this->assertSame( array(), $observed, 'Refusal reached a log read or writer.' );
            $this->assertStringContainsString( $admin ? 'insufficient permissions' : '0', $response );
        } elseif ( $insights ) {
            $after = $this->table_snapshot();
            $this->assertSame( array(), $after[0] );
            $this->assertSame( array(), $after[1] );
            $this->assertSame( '901', $after[2][0]['log_id'] );
            $this->assertSame( 'preserve', $after[3][0]['meta_value'] );
            $this->assertSame( array( 'success' => 'yes' ), json_decode( $response, true ) );
        } elseif ( $demo ) {
            $enabled = 'import_demo' === $writer ? 1 : 0;
            $this->assertSame( $enabled, (int) get_option( 'mainwp_enable_guided_tours' ) );
            $this->assertSame( $enabled, (int) get_option( 'mainwp_setting_demo_mode_enabled' ) );
            if ( $enabled ) {
                $this->assertGreaterThan( 0, json_decode( $response, true )['inserted'] );
                $this->assertNotSame( $before['sites'], $this->site_snapshot() );
            } else {
                $this->assertNull( MainWP_DB::instance()->get_website_by_id( $site ) );
            }
        } else {
            // Existing compact SQL compares second ranges with microsecond rows.
            $this->assertNotEmpty( $observed, 'The permitted request must reach the real log SQL.' );
            $this->assertSame( $before['logs'], $this->table_snapshot() );
            $this->assertSame( array( 'result' => 'SUCCESS' ), json_decode( $response, true ) );
        }
    }

    /**
     * Save and clear have identical nonce, method and rights requirements.
     *
     * @return array Writer, request type and expected permission.
     */
    public static function action_logs_requests(): array {
        $cases = array();
        foreach ( array( 'save', 'clear' ) as $writer ) {
            foreach ( array( 'allowed', 'no-right', 'get', 'missing-nonce', 'invalid-nonce', 'get-nonce', 'no-view' ) as $request ) {
                $cases[ $writer . ' ' . $request ] = array( $writer, $request );
            }
        }
        return $cases;
    }

    /**
     * Both mutation paths must preserve every row and option on refusal.
     *
     * @dataProvider action_logs_requests
     * @param string $writer Save or clear.
     * @param string $request Request permissions and nonce source.
     */
    public function test_action_logs_request( string $writer, string $request ): void {
        global $wpdb;
        $table = $this->action_log_table();
        $this->assertNotFalse(
            $wpdb->insert(
                $table,
                array(
                    'log_timestamp' => time(),
                    'log_content'   => 'preserve',
                )
            ),
            $wpdb->last_error
        );
        update_option( 'mainwp_actionlogs', MainWP_Logger::DISABLED );
        update_option( 'mainwp_specific_logs', 0 );
        update_option( 'mainwp_actionlogs_enabled_timestamp', 1 );
        $this->right( 'manage_dashboard_settings', 'no-right' !== $request );
        if ( 'no-view' === $request ) {
            $this->right( 'see_server_information', false );
        }
        $post = array( 'actionlogs_status' => 'specific_' . MainWP_Logger::UPTIME_CHECK_LOG_PRIORITY );
        if ( 'clear' === $writer ) {
            $post['actionlogs_clear'] = 'Delete Log';
        }
        if ( 'missing-nonce' !== $request ) {
            $post['_wpnonce'] = 'invalid-nonce' === $request ? 'invalid' : wp_create_nonce( 'mainwp-admin-nonce' );
        }
        if ( 'get' === $request ) {
            $_GET = $post;
        } else {
            $_POST = $post;
            if ( 'get-nonce' === $request ) {
                $_GET['_wpnonce'] = $post['_wpnonce'];
                unset( $_POST['_wpnonce'] );
            }
        }
        $_REQUEST = array_merge( $_GET, $_POST );
        $before   = $this->snapshot();
        $body     = $this->capture( array( MainWP_Server_Information::class, 'render_action_logs' ) );
        if ( 'allowed' !== $request ) {
            $this->assertSame( $before, $this->snapshot(), 'Refused Action Logs request changed rows or options.' );
            if ( 'no-view' === $request ) {
                $this->assertStringContainsString( 'mainwp-permission-error', $body );
                $this->assertStringNotContainsString( '<form', $body );
            } elseif ( 'no-right' === $request ) {
                $this->assertStringContainsString( 'mainwp-permission-error', $body );
            } elseif ( in_array( $request, array( 'missing-nonce', 'invalid-nonce', 'get-nonce' ), true ) ) {
                $this->assertStringContainsString( 'Insecure request', $body );
            } elseif ( 'get' === $request ) {
                $this->assertStringNotContainsString( 'Insecure request', $body );
                $this->assertStringNotContainsString( 'mainwp-permission-error', $body );
                $this->assertStringContainsString( 'name="actionlogs_status"', $body );
            }
        } else {
            $this->assertSame( MainWP_Logger::UPTIME_CHECK_LOG_PRIORITY, (int) get_option( 'mainwp_actionlogs' ) );
            $this->assertSame( 1, (int) get_option( 'mainwp_specific_logs' ) );
            $this->assertGreaterThan( 1, (int) get_option( 'mainwp_actionlogs_enabled_timestamp' ) );
            if ( 'clear' === $writer ) {
                $this->assertSame( array(), $this->table_snapshot()[0] );
            }
        }
    }

    /** Submit the actual renderer's nonce and select values as a browser would. */
    public function test_action_logs_real_form_save(): void {
        $this->action_log_table();
        update_option( 'mainwp_actionlogs', MainWP_Logger::DISABLED );
        $body = $this->capture( array( MainWP_Server_Information::class, 'render_action_logs' ) );
        $doc  = new \DOMDocument();
        @$doc->loadHTML( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Legacy page markup is parsed only to assert the posted form controls.
        $xpath = new \DOMXPath( $doc );
        $nonce = $xpath->query( '//form[@method="POST"][.//select[@name="actionlogs_status"]]//input[@name="_wpnonce"]' );
        $this->assertSame( 1, $nonce->length );
        $status = $xpath->query( '//select[@name="actionlogs_status"]/option[@value="3"]' );
        $this->assertSame( 1, $status->length );
        $this->assertSame( 1, $xpath->query( '//form[@method="POST"]//input[@name="actionlogs_clear"]' )->length );
        $_POST    = array(
            '_wpnonce'          => $nonce->item( 0 )->getAttribute( 'value' ),
            'actionlogs_status' => $status->item( 0 )->getAttribute( 'value' ),
        );
        $_REQUEST = $_POST;
        $this->capture( array( MainWP_Server_Information::class, 'render_action_logs' ) );
        $this->assertSame( MainWP_Logger::DEBUG, (int) get_option( 'mainwp_actionlogs' ) );
        $this->assertSame( 0, (int) get_option( 'mainwp_specific_logs' ) );
    }

    /**
     * Menu access cannot replace authorization inside the password writer.
     *
     * @dataProvider rights
     * @param bool   $allowed Users right.
     * @param string $window Password policy window.
     */
    public function test_password_policy_right( bool $allowed, string $window = '90' ): void {
        update_option( 'mainwp_password_policy_settings', array( 'max_age_days' => 30 ) );
        $this->right( 'manage_users', $allowed );
        $_POST    = array(
            'bulk_update_password_policy'             => '1',
            'security'                                => wp_create_nonce( 'mainwp_password_policy_settings' ),
            'mainwp_password_policy_window'           => $window,
            'mainwp_password_policy_due_soon_message' => 'Due soon',
            'mainwp_password_policy_overdue_message'  => 'Overdue',
            'mainwp_password_policy_show_notices_to'  => 'all_users',
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        $body     = $this->capture( array( MainWP_Password_Policy_Settings::class, 'render' ) );
        if ( $allowed ) {
            $this->assertSame(
                array(
                    'max_age_days'     => 90,
                    'due_soon_message' => 'Due soon',
                    'overdue_message'  => 'Overdue',
                    'show_notices_to'  => 'all_users',
                ),
                get_option( 'mainwp_password_policy_settings' )
            );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertStringContainsString( 'mainwp-permission-error', $body );
        }
    }

    /**
     * Per-site password policy writers with the users right granted and refused.
     *
     * @return array
     */
    public static function password_policy_ajax_writers(): array {
        $cases = array();
        foreach ( array( 'save_individual', 'update_site' ) as $writer ) {
            $cases[ $writer . ' refused' ] = array( $writer, false );
            $cases[ $writer . ' allowed' ] = array( $writer, true );
        }
        return $cases;
    }

    /**
     * The per-site AJAX writers need the same right as the bulk save they serve.
     *
     * @dataProvider password_policy_ajax_writers
     * @param string $writer Writer name.
     * @param bool   $allowed Users right.
     */
    public function test_password_policy_ajax_right( string $writer, bool $allowed ): void {
        $this->right( 'manage_users', $allowed );
        $site = $this->create_test_site( array( 'url' => 'https://password-policy-fixture.example/' ) );
        // A site on individual settings is skipped by update_site before any Child request.
        MainWP_DB::instance()->update_website_option( $site, 'password_policy_individual_settings', wp_json_encode( array( 'overwrite_enabled' => true ) ) );
        $action   = 'mainwp_password_policy_' . $writer;
        $_POST    = array(
            'security'          => wp_create_nonce( $action ),
            'site_id'           => (string) $site,
            'overwrite_enabled' => '0',
            'max_age_days'      => '90',
            'due_soon_message'  => 'Due soon',
            'overdue_message'   => 'Overdue',
            'show_notices_to'   => 'all_users',
        );
        $_REQUEST = $_POST;
        $before   = $this->snapshot();
        $response = json_decode( $this->capture( array( MainWP_Password_Policy_Settings::class, 'ajax_' . $writer ) ), true );
        $this->assertIsArray( $response );
        if ( ! $allowed ) {
            $this->assertSame( $before, $this->snapshot(), 'Refusal changed options or site rows.' );
            $message = 'save_individual' === $writer ? $response['data']['message'] : $response['error'];
            $this->assertStringStartsWith( 'You do not have sufficient permissions', $message );
        } elseif ( 'save_individual' === $writer ) {
            $this->assertTrue( $response['success'] );
            $this->assertSame( wp_json_encode( array( 'overwrite_enabled' => false ) ), MainWP_DB::instance()->get_website_option( $site, 'password_policy_individual_settings' ) );
        } else {
            $this->assertTrue( $response['skipped'] );
        }
    }

    /**
     * Effective users rights.
     *
     * @return array
     */
    public static function rights(): array {
        return array(
            'refused'                => array( false ),
            'allowed'                => array( true ),
            'refused-invalid-window' => array( false, '7' ),
        );
    }

    /** Dead AJAX registration and its writer must both be absent. */
    public function test_showhide_sections_removed(): void {
        global $wp_filter;
        $saved = array_map(
            static function ( $hook ) {
                return clone $hook;
            },
            $wp_filter
        );
        try {
            MainWP_Post_Handler::instance()->init();
            $this->assertFalse( has_action( 'wp_ajax_mainwp_showhide_sections' ) );
            $this->assertFalse( method_exists( MainWP_Post_Handler::class, 'mainwp_showhide_sections' ) );
        } finally {
            $wp_filter = $saved;
        }
    }

    /** Isolate archive SQL and the wpdb table aliases used by compact SQL. */
    private function network_tables(): void {
        global $wpdb;
        $prefix = $wpdb->prefix . 'logs_data_rights_';
        foreach ( array( 'wp_logs', 'wp_logs_meta', 'wp_logs_archive', 'wp_logs_meta_archive' ) as $suffix ) {
            $this->temporary_table( $prefix . $suffix, MainWP_DB::instance()->get_table_name( $suffix ) );
        }
        foreach ( array( Log_DB_Archive::class, Log_DB_Helper::class ) as $class ) {
            $property = new \ReflectionProperty( $class, 'table_prefix' );
            $property->setAccessible( true );
            $this->db_prefixes[ $class ] = $property->getValue( $class::instance() );
            $property->setValue( $class::instance(), $prefix );
        }
        foreach ( array(
            'mainwp_tbl_logs'      => $this->tables[0],
            'mainwp_tbl_logs_meta' => $this->tables[1],
        ) as $name => $table ) {
            $this->log_tables[ $name ] = $wpdb->$name;
            $wpdb->$name               = $table;
        }
        $this->assertNotFalse(
            $wpdb->insert(
                $this->tables[0],
                array(
                    'log_id'     => 901,
                    'user_login' => 'fixture',
                    'action'     => 'updated',
                    'context'    => 'plugins',
                    'connector'  => 'plugins',
                    'created'    => strtotime( '2026-06-01' ) * 1000000,
                    'dismiss'    => 0,
                )
            ),
            $wpdb->last_error
        );
        $this->assertNotFalse(
            $wpdb->insert(
                $this->tables[1],
                array(
                    'meta_id'     => 902,
                    'meta_log_id' => 901,
                    'meta_key'    => 'fixture',
                    'meta_value'  => 'preserve',
                )
            ),
            $wpdb->last_error
        );
    }

    /**
     * Clone the production schema without changing persistent tables.
     *
     * @param string $table Temporary table name.
     * @param string $source Production table name.
     */
    private function temporary_table( string $table, string $source ): void {
        global $wpdb;
        $this->assertNotFalse( $wpdb->query( "CREATE TEMPORARY TABLE $table LIKE $source" ), $wpdb->last_error );
        $this->tables[] = $table;
    }

    /**
     * Temporary destination for the real Action Logs SQL.
     *
     * @return string
     */
    private function action_log_table(): string {
        global $wpdb;
        $source = MainWP_DB_Common::instance()->get_table_name( 'action_log' );
        $table  = $wpdb->prefix . 'logs_data_action_log';
        $this->temporary_table( $table, $source );
        $this->filter(
            'query',
            static function ( $sql ) use ( $source, $table ) {
                return str_replace( $source, $table, $sql );
            }
        );
        return $table;
    }

    /**
     * Options and rows, including the secure_request marker.
     *
     * @return array
     */
    private function snapshot(): array {
        global $wpdb;
        return array(
            'options'   => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ),
            'logs'      => $this->table_snapshot(),
            'sites'     => $this->site_snapshot(),
            'ajaxposts' => get_option( 'mainwp_ajaxposts' ),
        );
    }

    /**
     * Fixture rows read independently of the log workers.
     *
     * @return array
     */
    private function table_snapshot(): array {
        global $wpdb;
        $rows = array();
        foreach ( $this->tables as $table ) {
            $rows[] = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
        }
        return $rows;
    }

    /**
     * Demo data tables, including related site and client rows.
     *
     * @return array
     */
    private function site_snapshot(): array {
        global $wpdb;
        $rows = array();
        foreach ( array( 'wp', 'wp_sync', 'wp_settings_backup', 'wp_options', 'wp_clients', 'wp_clients_contacts' ) as $suffix ) {
            $table  = MainWP_DB::instance()->get_table_name( $suffix );
            $rows[] = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
        }
        return $rows;
    }

    /**
     * Restore singleton properties that WordPress transactions do not reset.
     *
     * @param object $singleton Singleton.
     * @param string $name Property name.
     */
    private function preserve_property( $singleton, string $name ): void {
        $property = new \ReflectionProperty( $singleton, $name );
        $property->setAccessible( true );
        $this->properties[] = array( $property, $singleton, $property->getValue( $singleton ) );
    }

    /**
     * A REST bypass would invalidate every refusal assertion.
     *
     * @param string $cap MainWP right.
     * @param bool   $allowed Grant.
     */
    private function right( string $cap, bool $allowed ): void {
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run before Test_REST_*.' );
        $previous = $this->filters['mainwp_currentusercan'];
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $requested ) use ( $cap, $allowed, $previous ) {
                return 'dashboard' === $type && $cap === $requested ? $allowed : $previous( $can, $type, $requested );
            }
        );
        $this->assertSame( $allowed, \mainwp_current_user_can( 'dashboard', $cap ) );
    }

    /**
     * Replace only this test's own callback.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /**
     * Capture real AJAX exits and renderer output.
     *
     * @param callable $callback Writer or renderer.
     * @return string Response body.
     */
    private function capture( callable $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Logs_Data_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /**
     * Die callback that escapes legacy Exception handlers.
     *
     * @return callable
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Logs_Data_Response_Stop( (string) $message );
        };
    }
}
