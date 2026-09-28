<?php
/**
 * API-key requests must retain the key owner's site scope.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;
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
}
