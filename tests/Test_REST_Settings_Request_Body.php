<?php
/**
 * JSON request validation for REST v2 settings.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/** Exercises validation through the WordPress REST server. */
class Test_REST_Settings_Request_Body extends MainWP_Abilities_Test_Case {

    /**
     * REST server and authentication state restored after each test.
     *
     * @var array
     */
    private $original = array();

    /** Prepare an administrator without setting the process-wide REST bypass. */
    public function setUp(): void {
        parent::setUp();
        $this->set_current_user_as_admin();
        $auth = \MainWP_REST_Authentication::get_instance();
        $user = new \ReflectionProperty( $auth, 'user' );
        $user->setAccessible( true );
        $this->original = array(
            'server'   => $GLOBALS['wp_rest_server'] ?? null,
            'auth'     => $auth,
            'user'     => $user->getValue( $auth ),
            'priority' => has_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ) ),
        );
        if ( false !== $this->original['priority'] ) {
            $this->assertTrue( remove_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), $this->original['priority'] ) );
        }
        $user->setValue(
            $auth,
            (object) array(
                'permissions' => 'read_write',
                'user_id'     => get_current_user_id(),
                'key_id'      => 0,
            )
        );
        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new \MainWP_Rest_Settings_Controller() )->register_routes();
        update_option( 'mainwp_backupsOnServer', 2 );
    }

    /** Restore the authentication fixture and REST server. */
    public function tearDown(): void {
        $user = new \ReflectionProperty( $this->original['auth'], 'user' );
        $user->setAccessible( true );
        $user->setValue( $this->original['auth'], $this->original['user'] );
        if ( false !== $this->original['priority'] ) {
            add_filter( 'rest_pre_dispatch', array( $this->original['auth'], 'check_user_permissions' ), $this->original['priority'], 3 );
        }
        $GLOBALS['wp_rest_server'] = $this->original['server'];
        parent::tearDown();
    }

    /**
     * Send the raw body through route validation and sanitization.
     *
     * @param string $body Request body.
     * @param string $content_type Media type header.
     * @param string $route Settings route.
     * @return \WP_REST_Response
     */
    private function request( $body, $content_type = 'application/json', $route = 'backups/edit' ) {
        $request = new \WP_REST_Request( 'PUT', '/mainwp/v2/settings/' . $route );
        $request->set_header( 'Content-Type', $content_type );
        $request->set_body( $body );
        return rest_do_request( $request );
    }

    /**
     * Accepted media type spellings.
     *
     * @return array
     */
    public static function json_content_types() {
        return array(
            'plain'      => array( 'application/json' ),
            'charset'    => array( 'application/json; charset=utf-8' ),
            'uppercase'  => array( 'APPLICATION/JSON' ),
            'whitespace' => array( ' Application/JSON ; charset=UTF-8 ' ),
        );
    }

    /**
     * Objects update the requested setting with each accepted header.
     *
     * @dataProvider json_content_types
     * @param string $content_type Media type header.
     */
    public function test_json_object_saves_settings( $content_type ): void {
        $response = $this->request( '{"mainwp_backups_on_server":3}', $content_type );
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $this->assertSame( 1, $response->get_data()['success'] );
        $this->assertSame( 3, (int) get_option( 'mainwp_backupsOnServer' ) );
    }

    /** Non-JSON content must not write settings. */
    public function test_non_json_content_type_is_rejected(): void {
        $response = $this->request( '{"mainwp_backups_on_server":3}', 'text/plain' );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_content_type', $response->get_data()['code'] );
        $this->assertSame( 2, (int) get_option( 'mainwp_backupsOnServer' ) );
    }

    /**
     * JSON values that cannot represent a settings object.
     *
     * @return array
     */
    public static function non_object_bodies() {
        $cases = array();
        foreach ( array( 'backups/edit', 'general/edit', 'advanced/edit', 'monitoring/edit', 'emails/daily_digest/edit', 'cost-tracker/edit', 'dashboard-insights/edit', 'api-backups/gridpane/edit', 'tools/edit' ) as $route ) {
            foreach ( array( '5', '"abc"', 'true', 'null', '[]', '[{"mainwp_backups_on_server":3}]' ) as $body ) {
                $cases[ $route . ':' . $body ] = array( $route, $body );
            }
        }
        return $cases;
    }

    /**
     * Non-object bodies fail before sanitization and leave settings unchanged.
     *
     * @dataProvider non_object_bodies
     * @param string $route Settings route.
     * @param string $body Request body.
     */
    public function test_non_object_body_is_rejected_without_writes( $route, $body ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Snapshot persisted settings without option filters or caches.
        $before   = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
        $response = $this->request( $body, 'application/json', $route );
        $this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_body', $response->get_data()['code'] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare persisted settings without option filters or caches.
        $this->assertSame( $before, $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) );
    }

    /** An empty object remains a valid no-op request. */
    public function test_empty_object_remains_valid(): void {
        $response = $this->request( '{}' );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 2, (int) get_option( 'mainwp_backupsOnServer' ) );
    }
}
