<?php
/**
 * API-key requests must retain the key owner's site scope.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Abilities_Updates;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class Test_Abilities_Api_Key_Scoping
 */
class Test_Abilities_Api_Key_Scoping extends MainWP_Abilities_Test_Case {

    /**
     * @var array<string, callable>
     */
    private $scope_filters = array();

    public function tearDown(): void {
        foreach ( $this->scope_filters as $hook => $callback ) {
            remove_filter( $hook, $callback );
        }
        $this->scope_filters = array();
        $this->set_machine_context_override( null );
        parent::tearDown();
    }

    /**
     * Reproduce the API-key DB bypass without defining a process-wide constant.
     *
     * @param int[] $ids Allowed site IDs in the ability layer.
     * @return void
     */
    private function restrict_ability_layer_only( array $ids ): void {
        if ( isset( $this->scope_filters['mainwp_currentuserallowedaccesssites'] ) ) {
            remove_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'] );
        }
        $callback = static function () use ( $ids ) {
            foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ) as $frame ) {
                if ( MainWP_DB::class === ( $frame['class'] ?? '' ) ) {
                    return 'all';
                }
            }
            return $ids;
        };
        $this->scope_filters['mainwp_currentuserallowedaccesssites'] = $callback;
        add_filter( 'mainwp_currentuserallowedaccesssites', $callback, 10 );
    }

    private function set_machine_context_override( $value ): void {
        $prop = new \ReflectionProperty( MainWP_Abilities_Util::class, 'machine_context_override' );
        $prop->setAccessible( true );
        $prop->setValue( null, $value );
    }

    public function test_list_sites_scopes_items_and_total() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( 'mainwp/list-sites-v1', array( 'per_page' => 100 ) );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertNotContains( $outside, $ids );
        $this->assertContains( $allowed, $ids );
        $this->assertSame( 1, $result['total'] );
        $this->assertCount( 1, $result['items'] );
    }

    public function test_count_sites_scopes_total() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', array() );

        $this->assertNotWPError( $result );
        $this->assertSame( 1, $result['total'] );
    }

    public function test_get_sites_basic_scopes_items_and_total() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( 'mainwp/get-sites-basic-v1', array( 'per_page' => 100 ) );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertNotContains( $outside, $ids );
        $this->assertContains( $allowed, $ids );
        $this->assertSame( 1, $result['total'] );
        $this->assertCount( 1, $result['items'] );
    }

    public function test_allowed_site_ids_drops_zero() {
        $this->set_current_user_as_admin();
        $this->restrict_ability_layer_only( array( 0 ) );

        $this->assertSame( array(), MainWP_Abilities_Util::get_allowed_site_ids() );
    }

    public function test_count_sites_with_zero_scope_returns_zero() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $this->create_test_site();
        $this->restrict_ability_layer_only( array( 0 ) );

        $result = $this->execute_ability( 'mainwp/count-sites-v1', array() );

        $this->assertNotWPError( $result );
        $this->assertSame( 0, $result['total'] );
    }

    public static function all_sites_batch_abilities() {
        return array(
            'sync-sites'  => array( 'mainwp/sync-sites-v1' ),
            'update-all'  => array( 'mainwp/update-all-v1' ),
            'run-updates' => array( 'mainwp/run-updates-v1' ),
        );
    }

    /**
     * An empty scope prevents child calls, including before the enumeration fix.
     *
     * @dataProvider all_sites_batch_abilities
     */
    public function test_all_sites_batch_does_not_disclose_out_of_scope_ids( $ability_name ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array() );

        $result = $this->execute_ability( $ability_name, array() );

        $this->assertNotWPError( $result );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'errors', $result );
        foreach ( $result['errors'] as $error ) {
            foreach ( array( 'identifier', 'site_url', 'message' ) as $field ) {
                $this->assertStringNotContainsString( (string) $outside, (string) ( $error[ $field ] ?? '' ), $ability_name . ': ' . $field );
            }
        }
        array_walk_recursive(
            $result,
            function ( $value, $key ) use ( $outside, $ability_name ) {
                if ( 'message' === $key ) {
                    $this->assertStringNotContainsString( (string) $outside, (string) $value, $ability_name );
                }
            }
        );
        $this->assertSame( array(), $result['errors'], $ability_name );
    }

    public static function partial_scope_batch_abilities() {
        return array(
            'suspend-sites'    => array( 'mainwp/suspend-sites-v1', 'suspended' ),
            'disconnect-sites' => array( 'mainwp/disconnect-sites-v1', 'disconnected' ),
        );
    }

    /**
     * @dataProvider partial_scope_batch_abilities
     */
    public function test_all_sites_batch_with_partial_scope_excludes_outside_site( $ability_name, $result_key ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site( array( 'url' => 'https://test-allowed.example.com/' ) );
        $outside = $this->create_test_site( array( 'url' => 'https://test-outside.example.com/' ) );
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( $ability_name, array() );

        $this->assertNotWPError( $result );
        $this->assertIsArray( $result );
        $ids = array_column( $result[ $result_key ], 'id' );
        $this->assertContains( $allowed, $ids );
        $this->assertNotContains( $outside, $ids );
        $this->assertSame( array(), $result['errors'] );
        $encoded = json_encode( $result );
        $this->assertIsString( $encoded );
        $this->assertDoesNotMatchRegularExpression( '/(?<![0-9])' . $outside . '(?![0-9])/', $encoded );
    }

    public function test_list_updates_scopes_rows_summary_and_total() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        foreach ( array( $allowed, $outside ) as $site_id ) {
            $this->set_site_plugin_upgrades(
                $site_id,
                array( 'scope-test/plugin.php' => array( 'Name' => 'Scope Test', 'Version' => '1.0', 'new_version' => '2.0' ) )
            );
        }
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( 'mainwp/list-updates-v1', array( 'types' => array( 'plugins' ), 'per_page' => 100 ) );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['updates'], 'site_id' );
        $this->assertNotContains( $outside, $ids );
        $this->assertContains( $allowed, $ids );
        $this->assertSame( 1, $result['total'] );
        $this->assertCount( 1, $result['updates'] );
        $this->assertSame( array( 'core' => 0, 'plugins' => 1, 'themes' => 0, 'translations' => 0, 'total' => 1 ), $result['summary'] );
    }

    public function test_list_ignored_updates_scopes_rows_and_total() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        foreach ( array( $allowed, $outside ) as $site_id ) {
            $this->set_site_ignored_plugins( $site_id, array( 'scope-test/plugin.php' => 'Scope Test' ) );
        }
        $this->restrict_ability_layer_only( array( $allowed ) );

        $result = $this->execute_ability( 'mainwp/list-ignored-updates-v1', array( 'types' => array( 'plugins' ) ) );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['ignored'], 'site_id' );
        $this->assertNotContains( $outside, $ids );
        $this->assertContains( $allowed, $ids );
        $this->assertSame( 1, $result['total'] );
        $this->assertCount( 1, $result['ignored'] );
    }

    public function test_empty_scope_returns_empty_lists_and_zero_counts() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $this->set_site_plugin_upgrades( $site_id, array( 'scope-test/plugin.php' => array( 'Name' => 'Scope Test', 'Version' => '1.0', 'new_version' => '2.0' ) ) );
        $this->set_site_ignored_plugins( $site_id, array( 'ignored-test/plugin.php' => 'Ignored Test' ) );
        $this->restrict_ability_layer_only( array() );

        foreach ( array( 'list-sites', 'get-sites-basic', 'list-updates', 'list-ignored-updates' ) as $path ) {
            $result = $this->execute_ability( 'mainwp/' . $path . '-v1', array() );
            $this->assertNotWPError( $result, $path );
            $key = 'list-updates' === $path ? 'updates' : ( 'list-ignored-updates' === $path ? 'ignored' : 'items' );
            $this->assertSame( array(), $result[ $key ], $path );
            $this->assertSame( 0, $result['total'], $path );
            if ( 'list-updates' === $path ) {
                $this->assertSame( array( 'core' => 0, 'plugins' => 0, 'themes' => 0, 'translations' => 0, 'total' => 0 ), $result['summary'] );
            }
        }
        $count = $this->execute_ability( 'mainwp/count-sites-v1', array() );
        $this->assertNotWPError( $count );
        $this->assertSame( 0, $count['total'] );
    }

    public function test_machine_context_without_user_skips_intersection() {
        $this->restrict_ability_layer_only( array() );
        $this->set_machine_context_override( true );
        wp_set_current_user( 0 );

        $this->assertSame( 'all', MainWP_Abilities_Util::get_scoped_site_ids_or_all() );

        $this->set_current_user_as_admin();
        $this->assertSame( array(), MainWP_Abilities_Util::get_scoped_site_ids_or_all(), 'An authenticated key owner retains their scope in a machine context.' );
    }

    public static function invalid_update_array_inputs() {
        $cases = array();
        foreach ( array( 'execute_run_updates', 'execute_update_all' ) as $method ) {
            foreach ( array( 'site_ids_or_domains', 'types', 'specific_items' ) as $parameter ) {
                foreach ( array( 'zero' => '0', 'null' => null ) as $label => $value ) {
                    $cases[ $method . '-' . $parameter . '-' . $label ] = array( $method, $parameter, $value );
                }
            }
        }
        return $cases;
    }

    /**
     * Callbacks must reject scalars even when schema validation coerces arrays.
     *
     * @dataProvider invalid_update_array_inputs
     */
    public function test_update_callbacks_reject_present_non_arrays_without_child_calls( $method, $parameter, $value ) {
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $this->set_site_plugin_upgrades( $site_id, array( 'scope-test/plugin.php' => array( 'Name' => 'Scope Test', 'Version' => '1.0', 'new_version' => '2.0' ) ) );
        $this->restrict_ability_layer_only( array( $site_id ) );
        $this->scope_filters['mainwp_fetch_url_authed_pre'] = function () {
            $this->fail( 'Invalid array input must not send a child request.' );
        };
        add_filter( 'mainwp_fetch_url_authed_pre', $this->scope_filters['mainwp_fetch_url_authed_pre'] );

        $result = MainWP_Abilities_Updates::$method( array( $parameter => $value ) );

        $this->assertWPError( $result );
        $this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    private function restrict_current_user_to_groups( array $group_ids ): void {
        if ( isset( $this->scope_filters['mainwp_currentuserallowedaccessgroups'] ) ) {
            remove_filter( 'mainwp_currentuserallowedaccessgroups', $this->scope_filters['mainwp_currentuserallowedaccessgroups'] );
        }
        $callback = static function () use ( $group_ids ) {
            return $group_ids;
        };
        $this->scope_filters['mainwp_currentuserallowedaccessgroups'] = $callback;
        add_filter( 'mainwp_currentuserallowedaccessgroups', $callback );
    }

    public static function tag_filtered_site_abilities() {
        return array(
            'list'  => array( 'mainwp/list-sites-v1', 'tag_id' ),
            'count' => array( 'mainwp/count-sites-v1', 'tag_ids' ),
            'basic' => array( 'mainwp/get-sites-basic-v1', 'tag_ids' ),
        );
    }

    /**
     * @dataProvider tag_filtered_site_abilities
     */
    public function test_site_abilities_hide_disallowed_tag_filters( $ability_name, $parameter ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $visible = $this->create_test_tag();
        $hidden = $this->create_test_tag();
        $this->assign_site_to_tag( $site_id, $visible );
        $this->assign_site_to_tag( $site_id, $hidden );
        $this->restrict_ability_layer_only( array( $site_id ) );
        $this->restrict_current_user_to_groups( array( $visible ) );

        $result = $this->execute_ability( $ability_name, array( $parameter => 'tag_id' === $parameter ? $hidden : array( $hidden ) ) );

        $this->assertNotWPError( $result );
        $this->assertSame( 0, $result['total'] );
        if ( 'tag_id' === $parameter || 'mainwp/get-sites-basic-v1' === $ability_name ) {
            $this->assertSame( array(), $result['items'] );
        }

        $control = $this->execute_ability( $ability_name, array( $parameter => 'tag_id' === $parameter ? $visible : array( $visible ) ) );
        $this->assertNotWPError( $control );
        $this->assertSame( 1, $control['total'] );
        if ( isset( $control['items'] ) ) {
            $this->assertSame( array( $site_id ), array_column( $control['items'], 'id' ) );
        }

        if ( 'tag_ids' === $parameter ) {
            $mixed = $this->execute_ability( $ability_name, array( $parameter => array( $visible, $hidden ) ) );
            $this->assertSame( $control, $mixed );
        }
    }

    /**
     * Dispatch through the real permission callback with an authenticated read key.
     * Authentication transport is outside this test; the DB bypass uses our seam.
     */
    private function dispatch_v2_get( $route, array $params = array() ) {
        return $this->dispatch_v2_request( 'GET', $route, $params );
    }

    private function dispatch_v2_post( $route, array $params = array() ) {
        return $this->dispatch_v2_request( 'POST', $route, $params );
    }

    private function dispatch_v2_request( $method, $route, array $params ) {
        global $wp_rest_server;
        $previous_server = $wp_rest_server;
        $auth = \MainWP_REST_Authentication::get_instance();
        $user_property = new \ReflectionProperty( $auth, 'user' );
        $user_property->setAccessible( true );
        $previous_user = $user_property->getValue( $auth );
        $user_property->setValue( $auth, (object) array( 'user_id' => get_current_user_id(), 'permissions' => 'GET' === $method ? 'read' : 'read_write' ) );

        // check_user_permissions() would define MAINWP_REST_API_DOING for the rest of the
        // process and flip every later test into machine context; the DB bypass it stands
        // for is already reproduced by restrict_ability_layer_only(). The route's own
        // permission callback still runs.
        remove_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), 10 );
        try {
            $wp_rest_server = new \WP_REST_Server();
            do_action( 'rest_api_init' );
            ( new \MainWP_Rest_Sites_Controller() )->register_routes();
            ( new \MainWP_Rest_Updates_Controller() )->register_routes();
            ( new \MainWP_Rest_Tags_Controller() )->register_routes();
            ( new \MainWP_Rest_Clients_Controller() )->register_routes();
            ( new \MainWP_Rest_Monitors_Controller() )->register_routes();
            $request = new \WP_REST_Request( $method, $route );
            $request->set_query_params( $params );
            return rest_get_server()->dispatch( $request );
        } finally {
            add_filter( 'rest_pre_dispatch', array( $auth, 'check_user_permissions' ), 10, 3 );
            $user_property->setValue( $auth, $previous_user );
            $wp_rest_server = $previous_server;
        }
    }

    public function test_rest_sites_count_retains_api_key_scope() {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        $response = $this->dispatch_v2_get( '/mainwp/v2/sites/count' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'count' => 1 ), $response->get_data() );
    }

    public static function legacy_site_list_routes() {
        return array(
            'basic'     => array( '/mainwp/v2/sites/basic', array() ),
            'with_tags' => array( '/mainwp/v2/sites', array( 'with_tags' => 'false' ) ),
            'full_data' => array( '/mainwp/v2/sites', array( 'full_data' => 'false' ) ),
        );
    }

    /**
     * @dataProvider legacy_site_list_routes
     */
    public function test_rest_site_lists_retain_api_key_scope( $route, $params ) {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        $response = $this->dispatch_v2_get( $route, $params );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( array( 'success', 'total', 'data' ), array_keys( $data ) );
        $this->assertSame( 1, $data['success'] );
        $this->assertSame( 1, $data['total'] );
        $this->assertSame( array( $allowed ), array_map( 'intval', array_column( $data['data'], 'id' ) ) );
    }

    public function test_rest_sites_include_intersects_scope_and_preserves_exclude() {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );

        foreach ( array( '/mainwp/v2/sites', '/mainwp/v2/sites/basic', '/mainwp/v2/sites/count' ) as $route ) {
            $empty = '/mainwp/v2/sites/count' === $route ? array( 'count' => 0 ) : array( 'success' => 1, 'total' => 0, 'data' => array() );
            $response = $this->dispatch_v2_get( $route, array( 'include' => (string) $outside ) );
            $this->assertSame( 200, $response->get_status(), $route );
            $this->assertSame( $empty, $response->get_data(), $route );

            $response = $this->dispatch_v2_get( $route, array( 'include' => $allowed . ',' . $outside, 'exclude' => (string) $allowed ) );
            $this->assertSame( 200, $response->get_status(), $route );
            $this->assertSame( $empty, $response->get_data(), $route );
        }
    }

    public function test_rest_sites_empty_scope_returns_original_empty_shapes() {
        $this->set_current_user_as_admin();
        $this->create_test_site();
        $this->restrict_ability_layer_only( array() );
        foreach ( array( '/mainwp/v2/sites', '/mainwp/v2/sites/basic', '/mainwp/v2/sites/count' ) as $route ) {
            $response = $this->dispatch_v2_get( $route, array( 'with_tags' => 'false' ) );
            $this->assertSame( 200, $response->get_status(), $route );
            $expected = '/mainwp/v2/sites/count' === $route ? array( 'count' => 0 ) : array( 'success' => 1, 'total' => 0, 'data' => array() );
            $this->assertSame( $expected, $response->get_data(), $route );
        }
    }

    public function test_rest_updates_legacy_fallback_retains_api_key_scope() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        foreach ( array( $allowed, $outside ) as $site_id ) {
            $this->set_site_plugin_upgrades( $site_id, array( 'scope-test/plugin.php' => array( 'Name' => 'Scope Test', 'Version' => '1.0', 'new_version' => '2.0' ) ) );
        }
        $this->restrict_ability_layer_only( array( $allowed ) );

        $registry = \WP_Abilities_Registry::get_instance();
        $property = new \ReflectionProperty( $registry, 'registered_abilities' );
        $property->setAccessible( true );
        $abilities = $property->getValue( $registry );
        $remaining = $abilities;
        unset( $remaining['mainwp/list-updates-v1'] );
        $property->setValue( $registry, $remaining );
        $this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
        try {
            $response = $this->dispatch_v2_get( '/mainwp/v2/updates', array( 'type' => 'plugins' ) );
            $this->assertSame( 200, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( array( 'success', 'data' ), array_keys( $data ) );
            $this->assertSame( 1, $data['success'] );
            $this->assertSame( array( $allowed ), array_map( 'intval', array_keys( $data['data'] ) ) );
            $this->assertArrayHasKey( 'scope-test/plugin.php', $data['data'][ $allowed ]['plugins'] );

            $excluded = $this->dispatch_v2_get( '/mainwp/v2/updates', array( 'exclude' => (string) $allowed ) );
            $this->assertSame( array( 'success' => 1, 'data' => array() ), $excluded->get_data() );
            $this->restrict_ability_layer_only( array() );
            $empty = $this->dispatch_v2_get( '/mainwp/v2/updates' );
            $this->assertSame( array( 'success' => 1, 'data' => array() ), $empty->get_data() );
        } finally {
            $property->setValue( $registry, $abilities );
        }
    }

    public static function ignored_update_routes() {
        return array( 'global' => array( false ), 'site' => array( true ) );
    }

    /**
     * @dataProvider ignored_update_routes
     */
    public function test_rest_ignored_updates_preserves_legacy_body( $site_route ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $outside = $this->create_test_site();
        $global_plugins = array( 'shared/plugin.php' => array( 'Name' => 'Global Shared' ), 'global/plugin.php' => array( 'Name' => 'Global Plugin' ) );
        $global_themes = array( 'global-theme' => array( 'Name' => 'Global Theme' ) );
        $site_plugins = array( 'shared/plugin.php' => array( 'Name' => 'Site Shared' ), 'site/plugin.php' => array( 'Name' => 'Site Plugin' ) );
        $site_themes = array( 'site-theme' => array( 'Name' => 'Site Theme' ) );
        $this->set_site_ignored_plugins( $site_id, $site_plugins );
        $this->set_site_ignored_themes( $site_id, $site_themes );
        $this->set_site_ignored_plugins( $outside, array( 'outside/plugin.php' => array( 'Name' => 'Outside Plugin' ) ) );
        $extension = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_user_extension();
        \MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
            array( 'userid' => $extension->userid, 'ignored_plugins' => wp_json_encode( $global_plugins ), 'ignored_themes' => wp_json_encode( $global_themes ) )
        );
        $this->restrict_ability_layer_only( array( $site_id ) );
        $route = '/mainwp/v2/updates' . ( $site_route ? '/' . $site_id : '' ) . '/ignored';
        $plugins = $site_route ? $site_plugins + $global_plugins : $global_plugins;
        $themes = $site_route ? $site_themes + $global_themes : $global_themes;
        $expected = array( 'success' => 1, 'data' => array( 'plugins' => $plugins, 'themes' => $themes ) );
        $response = $this->dispatch_v2_get( $route );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $expected, $response->get_data() );
        foreach ( array( 'plugins', 'themes', 'translations', 'plugins,translations' ) as $type ) {
            $data = 'themes' === $type ? array( 'themes' => $themes ) : ( 'translations' === $type ? $expected['data'] : array( 'plugins' => $plugins ) );
            $this->assertSame( array( 'success' => 1, 'data' => $data ), $this->dispatch_v2_get( $route, array( 'type' => $type ) )->get_data() );
        }
        $this->assertSame(
            array( 'success' => 1, 'data' => array( 'plugins' => array( 'global/plugin.php' => $global_plugins['global/plugin.php'] ) ) ),
            $this->dispatch_v2_get( $route, array( 'search' => 'Global Plugin', 'type' => 'plugins' ) )->get_data()
        );
        $this->assertSame( array( 'success' => 1, 'data' => array() ), $this->dispatch_v2_get( $route, array( 'search' => 'no-such-ignored-update' ) )->get_data() );
        $this->without_ability( 'mainwp/list-ignored-updates-v1', function () use ( $route, $expected ) {
            $this->assertSame( $expected, $this->dispatch_v2_get( $route )->get_data() );
        } );
        \MainWP\Dashboard\MainWP_DB_Common::instance()->update_user_extension(
            array( 'userid' => $extension->userid, 'ignored_plugins' => '' )
        );
        $this->assertSame(
            array( 'success' => 1, 'data' => array( 'themes' => $themes ) ),
            $this->dispatch_v2_get( $route, array( 'type' => 'themes' ) )->get_data()
        );
    }

    public static function ignored_error_cases() {
        return array(
            'global denial' => array( false, 'permission_callback', null, 500 ),
            'site denial' => array( true, 'permission_callback', null, 500 ),
            'global status' => array( false, 'execute_callback', array( 'status' => 403 ), 403 ),
            'site status' => array( true, 'execute_callback', array( 'status' => 403 ), 403 ),
            'global default' => array( false, 'execute_callback', null, 500 ),
            'site default' => array( true, 'execute_callback', null, 500 ),
        );
    }

    /**
     * @dataProvider ignored_error_cases
     */
    public function test_rest_ignored_updates_passes_through_ability_error( $site_route, $callback_name, $error_data, $status ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $route = '/mainwp/v2/updates' . ( $site_route ? '/' . $site_id : '' ) . '/ignored';
        $ability = wp_get_ability( 'mainwp/list-ignored-updates-v1' );
        $property = new \ReflectionProperty( $ability, $callback_name );
        $property->setAccessible( true );
        $callback = $property->getValue( $ability );
        $called = false;
        $property->setValue( $ability, static function () use ( &$called, $error_data, $callback_name ) {
            $called = true;
            if ( 'permission_callback' === $callback_name ) {
                return false;
            }
            return new \WP_Error( 'test_ability_denied', 'Ignored updates are unavailable.', $error_data );
        } );
        try {
            $response = $this->dispatch_v2_get( $route, array( 'type' => 'plugins' ) );
            $this->assertTrue( $called );
            $this->assertSame( $status, $response->get_status() );
            $expected = $ability->execute( $site_route ? array( 'site_ids_or_domains' => array( $site_id ) ) : array() );
            $this->assertWPError( $expected );
            $this->assertSame( $expected->get_error_code(), $response->get_data()['code'] );
            $this->assertSame( $expected->get_error_message(), $response->get_data()['message'] );
        } finally {
            $property->setValue( $ability, $callback );
        }
    }

    public static function hidden_site_identifiers() {
        return array( 'id' => array( false, '' ), 'domain' => array( true, '' ), 'unsupported type' => array( false, 'plugins,translations' ) );
    }

    /**
     * @dataProvider hidden_site_identifiers
     */
    public function test_rest_ignored_updates_hides_outside_site( $domain, $type ) {
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site( array( 'url' => 'https://outside.example.com/' ) );
        $this->set_site_ignored_plugins( $outside, array( 'secret/plugin.php' => array( 'Name' => 'Secret Plugin' ) ) );
        $this->restrict_ability_layer_only( array() );
        $identifier = $domain ? 'outside.example.com' : $outside;
        $missing = $domain ? 'missing.example.com' : 99999999;
        $params = array( 'type' => $type );
        $hidden = $this->dispatch_v2_get( '/mainwp/v2/updates/' . $identifier . '/ignored', $params );
        $absent = $this->dispatch_v2_get( '/mainwp/v2/updates/' . $missing . '/ignored', $params );
        $this->assertSame( $absent->get_status(), $hidden->get_status() );
        $this->assertSame( $absent->get_data(), $hidden->get_data() );
        $this->restrict_ability_layer_only( array( $outside ) );
        $this->assertSame( 200, $this->dispatch_v2_get( '/mainwp/v2/updates/' . $identifier . '/ignored', $params )->get_status() );
    }

    private function without_ability( $name, callable $run ) {
        $registry = \WP_Abilities_Registry::get_instance();
        $property = new \ReflectionProperty( $registry, 'registered_abilities' );
        $property->setAccessible( true );
        $abilities = $property->getValue( $registry );
        $remaining = $abilities;
        unset( $remaining[ $name ] );
        $property->setValue( $registry, $remaining );
        $this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
        try {
            return $run();
        } finally {
            $property->setValue( $registry, $abilities );
        }
    }

    public static function rest_batch_routes() {
        return array( 'reconnect' => array( 'reconnect' ), 'disconnect' => array( 'disconnect' ), 'check' => array( 'check' ), 'sync legacy' => array( 'sync' ) );
    }

    /**
     * @dataProvider rest_batch_routes
     */
    public function test_rest_batch_scopes_child_calls_and_results( $action ) {
        $this->set_current_user_as_admin();
        $args = array( 'sync_errors' => 'reconnect' === $action ? 'Disconnected' : '' );
        $allowed = $this->create_test_site( $args + array( 'url' => 'https://a-allowed.example.com/' ) );
        $outside = $this->create_test_site( $args + array( 'url' => 'https://z-outside.example.com/' ) );
        if ( 'check' === $action ) {
            foreach ( array( $allowed, $outside ) as $site_id ) {
                \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( array( 'wpid' => $site_id, 'issub' => 0 ) );
            }
        }
        $hits = array();
        $callback = static function ( $pre, $website ) use ( &$hits ) {
            $hits[] = (int) $website->id;
            return array( 'result' => true, 'wpversion' => '6.4.0', 'siteurl' => $website->url, 'site_info' => array(), 'wp_updates' => array(), 'httpCode' => 200 );
        };
        add_filter( 'mainwp_fetch_url_authed_pre', $callback, 10, 2 );
        // Site checks use the uptime transport rather than authenticated child requests.
        add_filter( 'mainwp_fetch_uptime_monitor_pre', $callback, 10, 2 );
        $run = function () use ( $action, $allowed, &$hits ) {
            $this->restrict_ability_layer_only( array( $allowed ) );
            $response = $this->dispatch_v2_post( '/mainwp/v2/sites/' . $action );
            $this->assertSame( 200, $response->get_status() );
            $this->assertSame( array( $allowed ), array_values( array_unique( $hits ) ) );
            $this->assertSame( array( $allowed ), array_map( 'intval', array_keys( $response->get_data()['data'] ) ) );
            $this->assertSame( 1, $response->get_data()['total'] );
            $hits = array();
            $this->restrict_ability_layer_only( array() );
            $this->assertSame( array( 'total' => 0, 'data' => array() ), $this->dispatch_v2_post( '/mainwp/v2/sites/' . $action )->get_data() );
            $this->assertSame( array(), $hits );
        };
        try {
            if ( 'sync' === $action ) {
                $this->without_ability( 'mainwp/sync-sites-v1', $run );
            } else {
                $run();
            }
        } finally {
            remove_filter( 'mainwp_fetch_url_authed_pre', $callback, 10 );
            remove_filter( 'mainwp_fetch_uptime_monitor_pre', $callback, 10 );
        }
    }

    public static function site_resolvers() {
        return array( 'base' => array( 'MainWP_Rest_Sites_Controller' ), 'posts' => array( 'MainWP_Rest_Posts_Controller' ), 'pages' => array( 'MainWP_Rest_Pages_Controller' ) );
    }

    /**
     * @dataProvider site_resolvers
     */
    public function test_rest_site_resolvers_hide_ids_and_domains( $class ) {
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site( array( 'url' => 'https://hidden.example.com/' ) );
        $this->restrict_ability_layer_only( array() );
        $controller = new $class();
        foreach ( array( 'id' => array( $outside, 99999999 ), 'domain' => array( 'hidden.example.com', 'missing.example.com' ) ) as $by => $values ) {
            $hidden = $controller->get_site_by( $by, $values[0] );
            $missing = $controller->get_site_by( $by, $values[1] );
            $this->assertWPError( $hidden );
            $this->assertSame( $missing->get_error_code(), $hidden->get_error_code() );
            $this->assertSame( $missing->get_error_message(), $hidden->get_error_message() );
            $this->assertSame( $missing->get_error_data(), $hidden->get_error_data() );
            if ( 'MainWP_Rest_Sites_Controller' !== $class ) {
                $suffix = 'MainWP_Rest_Posts_Controller' === $class ? 'post' : 'page';
                foreach ( array( 'get_', 'create_', 'edit_', 'update_status_', 'delete_' ) as $prefix ) {
                    $method = $prefix . $suffix;
                    $request = new \WP_REST_Request();
                    $request->set_param( 'id_domain', (string) $values[0] );
                    $response = $controller->$method( $request );
                    $this->assertWPError( $response, $method );
                    $this->assertSame( $missing->get_error_code(), $response->get_error_code(), $method );
                    $this->assertSame( $missing->get_error_message(), $response->get_error_message(), $method );
                    $this->assertSame( $missing->get_error_data(), $response->get_error_data(), $method );
                }
            }
        }
        $this->restrict_ability_layer_only( array( $outside ) );
        $this->assertSame( $outside, (int) $controller->get_site_by( 'id', $outside )->id );
    }

    public static function related_site_lists() {
        return array( 'tag' => array( 'tag' ), 'client list' => array( 'client' ), 'client count' => array( 'count' ) );
    }

    /**
     * @dataProvider related_site_lists
     */
    public function test_rest_related_site_lists_scope_rows_and_totals( $kind ) {
        $this->set_current_user_as_admin();
        $client = $this->create_test_client();
        $tag = $this->create_test_tag();
        $allowed = $this->create_test_site( array( 'client_id' => $client ) );
        $outside = $this->create_test_site( array( 'client_id' => $client ) );
        $this->assign_site_to_tag( $allowed, $tag );
        $this->assign_site_to_tag( $outside, $tag );
        $route = 'tag' === $kind ? '/mainwp/v2/tags/' . $tag . '/sites' : '/mainwp/v2/clients/' . $client . '/sites' . ( 'count' === $kind ? '/count' : '' );
        $this->restrict_ability_layer_only( array( $allowed ) );
        $response = $this->dispatch_v2_get( $route );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $response->get_data()['total'] );
        if ( 'count' !== $kind ) {
            $this->assertSame( array( $allowed ), array_map( 'intval', array_column( $response->get_data()['data'], 'id' ) ) );
        }
        $this->restrict_ability_layer_only( array() );
        $this->assertSame( 0, $this->dispatch_v2_get( $route )->get_data()['total'] );
    }

    public function test_rest_tag_route_hides_disallowed_tag() {
        $this->set_current_user_as_admin();
        $tag = $this->create_test_tag();
        $this->restrict_current_user_to_groups( array() );
        $hidden = $this->dispatch_v2_get( '/mainwp/v2/tags/' . $tag . '/sites' );
        $missing = $this->dispatch_v2_get( '/mainwp/v2/tags/99999999/sites' );
        $this->assertSame( $missing->get_status(), $hidden->get_status() );
        $this->assertSame( $missing->get_data(), $hidden->get_data() );
        $this->assertSame( array( 'success' => 1, 'total' => 0, 'data' => array() ), $this->dispatch_v2_get( '/mainwp/v2/tags' )->get_data() );
    }

    public static function tag_metadata_routes() {
        return array( 'list' => array( false ), 'item' => array( true ) );
    }

    /**
     * @dataProvider tag_metadata_routes
     */
    public function test_rest_tags_scope_embedded_site_ids_and_count( $single ) {
        $this->set_current_user_as_admin();
        $tag = $this->create_test_tag();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->assign_site_to_tag( $allowed, $tag );
        $this->assign_site_to_tag( $outside, $tag );
        $this->restrict_ability_layer_only( array( $allowed ) );
        $route = '/mainwp/v2/tags' . ( $single ? '/' . $tag : '' );
        $response = $this->dispatch_v2_get( $route );
        $this->assertSame( 200, $response->get_status() );
        $data = $single ? $response->get_data()['data'] : $response->get_data()['data'][ $tag ];
        $this->assertSame( 1, $data['count_sites'] );
        $this->assertSame( array( $allowed ), wp_parse_id_list( $data['sites_ids'] ) );
        $this->restrict_ability_layer_only( array() );
        $empty = $this->dispatch_v2_get( $route )->get_data()['data'];
        $empty = $single ? $empty : $empty[ $tag ];
        $this->assertSame( 0, $empty['count_sites'] );
        $this->assertSame( '', $empty['sites_ids'] );
    }

    public function test_rest_client_selected_sites_response_is_scoped() {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );
        $controller = new \MainWP_Rest_Clients_Controller();
        $item = array( 'client_id' => 1, 'selected_sites' => $allowed . ',' . $outside );
        $data = $controller->filter_response_data_by_allowed_fields( $item, 'simple_view', array( 'selected_sites' ) );
        $this->assertSame( (string) $allowed, $data['selected_sites'] );
        $this->restrict_ability_layer_only( array() );
        $data = $controller->filter_response_data_by_allowed_fields( $item, 'simple_view', array( 'selected_sites' ) );
        $this->assertSame( '', $data['selected_sites'] );
    }

    public function test_rest_settings_jobs_scope_site_selection() {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array( $allowed ) );
        $controller = new \MainWP_Rest_Settings_Controller();
        $method = new \ReflectionMethod( $controller, 'get_websites' );
        $method->setAccessible( true );
        $this->assertSame( array( $allowed ), array_map( 'intval', array_column( $method->invoke( $controller ), 'id' ) ) );
        $lookup = new \ReflectionMethod( $controller, 'get_website_by_id' );
        $lookup->setAccessible( true );
        $process = array( 'failed' => 0, 'processed' => 0, 'errors' => array() );
        $this->assertNull( $lookup->invokeArgs( $controller, array( $outside, &$process ) ) );
        $this->assertSame( 1, $process['failed'] );
        $this->assertSame( 1, $process['processed'] );
        $this->assertSame( 'Website not found.', $process['errors'][0]['error'] );
        $this->restrict_ability_layer_only( array() );
        $this->assertWPError( $method->invoke( $controller ) );
    }

    public function test_rest_update_all_legacy_scopes_queued_sites() {
        $this->set_current_user_as_admin();
        $allowed = $this->create_test_site( array( 'plugin_upgrades' => '[]', 'theme_upgrades' => '[]' ) );
        $outside = $this->create_test_site( array( 'plugin_upgrades' => '[]', 'theme_upgrades' => '[]' ) );
        $this->restrict_ability_layer_only( array( $allowed ) );
        update_option( 'mainwp_batch_updates_is_running', 0 );
        $this->without_ability( 'mainwp/run-updates-v1', function () use ( $allowed, $outside ) {
            $controller = new \MainWP_Rest_Updates_Controller();
            $response = $controller->update_all_items_start( new \WP_REST_Request( 'POST', '/mainwp/v2/updates' ) );
            $this->assertNotWPError( $response );
            $this->assertGreaterThan( 0, (int) MainWP_DB::instance()->get_website_by_id( $allowed )->dtsAutomaticSyncStart );
            $this->assertSame( 0, (int) MainWP_DB::instance()->get_website_by_id( $outside )->dtsAutomaticSyncStart );
        } );
    }

    public static function monitor_list_routes() {
        return array( 'full' => array( '/mainwp/v2/monitors' ), 'basic' => array( '/mainwp/v2/monitors/basic' ), 'count' => array( '/mainwp/v2/monitors/count' ) );
    }

    /**
     * @dataProvider monitor_list_routes
     */
    public function test_rest_monitors_scope_lists_and_counts( $route ) {
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site( array( 'url' => 'https://a-outside.example.com/' ) );
        $allowed = $this->create_test_site( array( 'url' => 'https://z-allowed.example.com/' ) );
        $db = \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::instance();
        foreach ( array( $outside, $allowed ) as $site_id ) {
            $db->update_wp_monitor( array( 'wpid' => $site_id, 'issub' => 0 ) );
        }
        $monitor = $db->get_monitor_by( $allowed, 'issub', 0 );
        $this->restrict_ability_layer_only( array( $allowed ) );
        $response = $this->dispatch_v2_get( $route );
        $this->assertSame( 200, $response->get_status() );
        $count = str_ends_with( $route, '/count' );
        $this->assertSame( 1, $response->get_data()[ $count ? 'count' : 'total' ] );
        if ( ! $count ) {
            $this->assertSame( array( (int) $monitor->monitor_id ), array_map( 'intval', array_column( $response->get_data()['data'], 'id' ) ) );
            $paged = $this->dispatch_v2_get( $route, array( 'page' => 1, 'per_page' => 1 ) );
            $this->assertSame( $response->get_data(), $paged->get_data() );
        }
        $this->restrict_ability_layer_only( array() );
        $this->assertSame( 0, $this->dispatch_v2_get( $route )->get_data()[ $count ? 'count' : 'total' ] );
    }

    public function test_rest_monitor_lookup_hides_outside_id_and_domain() {
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site( array( 'url' => 'https://hidden-monitor.example.com/' ) );
        $db = \MainWP\Dashboard\MainWP_DB_Uptime_Monitoring::instance();
        $db->update_wp_monitor( array( 'wpid' => $outside, 'issub' => 0 ) );
        $monitor = $db->get_monitor_by( $outside, 'issub', 0 );
        $this->restrict_ability_layer_only( array() );
        foreach ( array( array( $monitor->monitor_id, 99999999 ), array( 'hidden-monitor.example.com', 'missing-monitor.example.com' ) ) as $identifiers ) {
            $hidden = $this->dispatch_v2_get( '/mainwp/v2/monitors/' . $identifiers[0] );
            $missing = $this->dispatch_v2_get( '/mainwp/v2/monitors/' . $identifiers[1] );
            $this->assertSame( $missing->get_status(), $hidden->get_status() );
            $this->assertSame( $missing->get_data(), $hidden->get_data() );
        }
    }

    public function test_rest_site_validation_hides_outside_site() {
        $this->set_current_user_as_admin();
        $outside = $this->create_test_site();
        $this->restrict_ability_layer_only( array() );
        $controller = new \MainWP_Rest_Sites_Controller();
        $error = $controller->validate_site_ids( (string) $outside, new \WP_REST_Request() );
        $this->assertWPError( $error );
        $this->assertSame( 'invalid_site', $error->get_error_code() );
    }

    public static function site_selection_sources() {
        return array( 'sites' => array( 'sites' ), 'groups' => array( 'groups' ), 'clients' => array( 'clients' ), 'csv sites' => array( 'select_sites' ), 'csv groups' => array( 'select_groups' ) );
    }

    /**
     * @dataProvider site_selection_sources
     */
    public function test_rest_site_selection_scopes_all_sources( $source ) {
        $this->set_current_user_as_admin();
        $client = $this->create_test_client();
        $tag = $this->create_test_tag( array( 'name' => 'Scope Tag' ) );
        $allowed = $this->create_test_site( array( 'client_id' => $client, 'url' => 'https://allowed.example.com/' ) );
        $outside = $this->create_test_site( array( 'client_id' => $client, 'url' => 'https://outside.example.com/' ) );
        $this->assign_site_to_tag( $allowed, $tag );
        $this->assign_site_to_tag( $outside, $tag );
        $this->restrict_ability_layer_only( array( $allowed ) );
        $controller = new \MainWP_Rest_Users_Controller();
        $csv = str_starts_with( $source, 'select_' );
        $method = new \ReflectionMethod( $controller, $csv ? 'get_websites_for_csv_user' : 'get_db_websites_by_filter' );
        $method->setAccessible( true );
        $input = $csv ? array( array( $source => 'select_sites' === $source ? 'allowed.example.com;outside.example.com' : 'Scope Tag' ) ) : array(
            'sites' === $source ? array( $allowed, $outside ) : array(),
            'groups' === $source ? array( $tag ) : array(),
            'clients' === $source ? array( $client ) : array(),
        );
        $result = $method->invokeArgs( $controller, $input );
        $this->assertSame( array( $allowed ), array_map( 'intval', array_keys( $csv ? $result : $result['db_websites'] ) ) );
        if ( ! $csv ) {
            $this->assertSame( array( $allowed ), array_map( 'intval', array_keys( $result['website_url'] ) ) );
        }
        $this->restrict_ability_layer_only( array() );
        $empty = $method->invokeArgs( $controller, $input );
        $this->assertSame( array(), $csv ? $empty : $empty['db_websites'] );
    }
}
