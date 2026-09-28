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
        global $wp_rest_server;
        $previous_server = $wp_rest_server;
        $auth = \MainWP_REST_Authentication::get_instance();
        $user_property = new \ReflectionProperty( $auth, 'user' );
        $user_property->setAccessible( true );
        $previous_user = $user_property->getValue( $auth );
        $user_property->setValue( $auth, (object) array( 'user_id' => get_current_user_id(), 'permissions' => 'read' ) );

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
            $request = new \WP_REST_Request( 'GET', $route );
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
        return array( 'all' => array( false ), 'site' => array( true ) );
    }

    /**
     * @dataProvider ignored_update_routes
     */
    public function test_rest_ignored_updates_accepts_schema_and_filters_search( $site_route ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $outside = $this->create_test_site();
        $this->set_site_ignored_plugins( $site_id, array( 'scope-test/plugin.php' => 'Scope Test', 'other/plugin.php' => 'Other Plugin' ) );
        $this->set_site_ignored_plugins( $outside, array( 'outside/plugin.php' => 'Outside Plugin' ) );
        $this->set_site_ignored_themes( $site_id, array( 'scope-theme' => 'Scope Theme' ) );
        $this->restrict_ability_layer_only( array( $site_id ) );
        $route = '/mainwp/v2/updates' . ( $site_route ? '/' . $site_id : '' ) . '/ignored';

        $response = $this->dispatch_v2_get( $route );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( array( 'success', 'data' ), array_keys( $data ) );
        $this->assertSame( 1, $data['success'] );
        $this->assertContains( 'scope-test/plugin.php', array_column( $data['data'], 'slug' ) );
        $this->assertNotContains( 'outside/plugin.php', array_column( $data['data'], 'slug' ) );
        $this->assertSame( array( $site_id ), array_values( array_unique( array_column( $data['data'], 'site_id' ) ) ) );

        if ( $site_route ) {
            $this->restrict_ability_layer_only( array( $site_id, $outside ) );
            $specific = $this->dispatch_v2_get( $route );
            $this->assertSame( 200, $specific->get_status() );
            $this->assertNotContains( 'outside/plugin.php', array_column( $specific->get_data()['data'], 'slug' ) );
        }

        foreach ( array( 'Scope Test', 'scope-test/' ) as $search ) {
            $filtered = $this->dispatch_v2_get( $route, array( 'type' => 'plugins', 'search' => $search ) );
            $this->assertSame( 200, $filtered->get_status() );
            $this->assertSame( array( 'scope-test/plugin.php' ), array_column( $filtered->get_data()['data'], 'slug' ) );
        }
        $missing = $this->dispatch_v2_get( $route, array( 'search' => 'no-such-ignored-update' ) );
        $this->assertSame( array( 'success' => 1, 'data' => array() ), $missing->get_data() );
    }


    /**
     * @dataProvider ignored_update_routes
     */
    public function test_rest_ignored_updates_falls_back_on_ability_error( $site_route ) {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $site_id = $this->create_test_site();
        $this->set_site_ignored_plugins( $site_id, array( 'fallback/plugin.php' => 'Fallback Plugin' ) );
        $route = '/mainwp/v2/updates' . ( $site_route ? '/' . $site_id : '' ) . '/ignored';
        $ability = wp_get_ability( 'mainwp/list-ignored-updates-v1' );
        $property = new \ReflectionProperty( $ability, 'execute_callback' );
        $property->setAccessible( true );
        $callback = $property->getValue( $ability );
        $called = false;
        $property->setValue( $ability, static function () use ( &$called ) {
            $called = true;
            return new \WP_Error( 'test_ability_failure', 'Exercise the legacy fallback.', array( 'status' => 500 ) );
        } );
        try {
            $response = $this->dispatch_v2_get( $route, array( 'type' => 'plugins' ) );
            $this->assertSame( 200, $response->get_status() );
            $this->assertTrue( $called, 'The schema-valid input must reach the failing callback.' );
            $data = $response->get_data();
            $this->assertSame( array( 'success', 'data' ), array_keys( $data ) );
            $this->assertSame( 1, $data['success'] );
            if ( $site_route ) {
                $this->assertSame( 'Fallback Plugin', $data['data']['plugins']['fallback/plugin.php'] );
            }
        } finally {
            $property->setValue( $ability, $callback );
        }
    }

}
