<?php
/**
 * Regression tests for Dashboard tag writes and Team Control grants.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\Group_Write_Post_Handler;

require_once __DIR__ . '/fixtures/Refusal_UI.php';

/**
 * Exercise UI writes with independent site and group restrictions.
 */
class Test_Group_Write_Scope extends \WP_UnitTestCase {

    use Site_Fixture;
    use Refusal_UI;

    /** @var array Hook callbacks owned by this test. */
    private $filters = array();

    /** @var array Mutation hook counts. */
    private $hook_calls = array();

    /** @var int Child registration attempts. */
    private $register_calls = 0;

    /**
     * Legacy raw exits would kill PHPUnit before it can inspect the database.
     * Test copies get distinct class names so the shared suite keeps its original
     * controllers. Authorization and writes run unchanged from the source files.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Manage_Groups'     => 'Group_Write_Controller',
            'MainWP_Post_Site_Handler' => 'Group_Write_Post_Handler',
        );
        foreach ( array( 'pages/page-mainwp-manage-groups.php', 'class/class-mainwp-post-site-handler.php' ) as $file ) {
            $source = file_get_contents( dirname( __DIR__ ) . '/' . $file );
            $code   = '';
            foreach ( token_get_all( $source ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits without altering production files for the red check.
        }
    }

    /**
     * Administrators must still obey the Team Control stand-in filters.
     */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        foreach ( array( 'mainwp_added_new_group', 'mainwp_site_tag_action', 'mainwp_update_site' ) as $hook ) {
            $this->hook_calls[ $hook ] = 0;
            add_action( $hook, array( $this, 'count_mutation' ) );
        }
    }

    /**
     * Leave callbacks from other tests intact.
     */
    public function tearDown(): void {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        foreach ( array_keys( $this->hook_calls ) as $hook ) {
            remove_action( $hook, array( $this, 'count_mutation' ) );
        }
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** Count mutation hooks after fixture creation. */
    public function count_mutation(): void {
        ++$this->hook_calls[ current_filter() ];
    }

    /**
     * @dataProvider invalid_site_inputs
     * @param string $case Input case.
     */
    public function test_create_refuses_invalid_submitted_sites( $case ): void {
        $allowed = $this->create_test_site();
        $denied  = $this->create_test_site();
        $this->scope( array( $allowed ), array() );
        $sites = 'missing' === $case ? array( $allowed, 99999999 ) : array( $allowed, $denied );
        $before = $this->before_write();
        $body   = $this->dispatch( 'mainwp_group_sites_add', array( 'newName' => 'Refused create', 'selected_sites' => $sites ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( 'mainwp_group_sites_add', $body );
    }

    /** @return array Invalid submitted site cases. */
    public static function invalid_site_inputs(): array {
        return array( 'mixed scope' => array( 'mixed' ), 'missing site' => array( 'missing' ) );
    }

    /** Empty tag names keep the original validation response. */
    public function test_empty_group_name_keeps_original_error(): void {
        $site = $this->create_test_site();
        $this->scope( array( $site ), array() );
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_group_sites_add', array( 'newName' => '', 'selected_sites' => array( $site ) ) );
        $this->assert_unchanged( $before );
        $this->assertSame( array( 'error' => 'Unexpected error occurred. Please try again.' ), json_decode( $body, true ) );
    }

    /**
     * @dataProvider invalid_site_inputs
     * @param string $case Input case.
     */
    public function test_replace_refuses_invalid_submitted_sites( $case ): void {
        $allowed = $this->create_test_site();
        $denied  = $this->create_test_site();
        $group   = $this->group( array( $allowed ) );
        $this->scope( array( $allowed ), array( $group ) );
        $sites  = 'missing' === $case ? array( $allowed, 99999999 ) : array( $allowed, $denied );
        $before = $this->before_write();
        $body   = $this->dispatch( 'mainwp_group_updategroup', array( 'groupId' => $group, 'websiteIds' => $sites ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( 'mainwp_group_updategroup', $body );
    }

    /**
     * @dataProvider hidden_member_operations
     * @param string $action AJAX action.
     * @param string $input  Selection type.
     */
    public function test_hidden_current_member_refuses_whole_write( $action, $input ): void {
        $allowed = $this->create_test_site();
        $hidden  = $this->create_test_site();
        $group   = $this->group( array( $allowed, $hidden ) );
        $this->scope( array( $allowed ), array( $group ) );
        $post = array( 'groupId' => $group );
        if ( 'omitted' !== $input ) {
            $post['websiteIds'] = 'empty' === $input ? array() : array( $allowed );
        }
        $before = $this->before_write();
        $body   = $this->dispatch( $action, $post );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( $action, $body );
    }

    /** @return array Operations that affect the full roster. */
    public static function hidden_member_operations(): array {
        return array(
            'replace' => array( 'mainwp_group_updategroup', 'selected' ),
            'empty'   => array( 'mainwp_group_updategroup', 'empty' ),
            'omitted' => array( 'mainwp_group_updategroup', 'omitted' ),
            'delete'  => array( 'mainwp_group_delete', 'omitted' ),
        );
    }

    /**
     * @dataProvider existing_group_actions
     * @param string $action AJAX action.
     */
    public function test_group_grant_is_required_even_with_all_sites( $action ): void {
        $site  = $this->create_test_site();
        $group = $this->group( array( $site ) );
        $this->scope( 'all', array() );
        $before = $this->before_write();
        $body   = $this->dispatch( $action, array( 'groupId' => $group, 'newName' => 'Denied rename', 'newColor' => '#123456', 'websiteIds' => array( $site ) ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( $action, $body );
    }

    /** @return array Actions on an existing group. */
    public static function existing_group_actions(): array {
        return array(
            array( 'mainwp_group_rename' ),
            array( 'mainwp_group_updategroup' ),
            array( 'mainwp_group_delete' ),
        );
    }

    /** @return array Group refusal handlers. */
    public static function refusal_handlers(): array {
        return array(
            array( 'mainwp_group_updategroup', '#mainwp-save-sites-groups-selection-button' ),
            array( 'mainwp_group_delete', '#mainwp-delete-group-button' ),
            array( 'mainwp_group_rename', '#mainwp-update-new-group-button' ),
        );
    }

    /**
     * @dataProvider refusal_handlers
     * @param string $action AJAX action.
     * @param string $handler Click handler.
     */
    public function test_group_refusal_reaches_the_ui_as_an_error( $action, $handler ): void {
        $site = $this->create_test_site();
        $group = $this->group( array( $site ) );
        $this->scope( array( $site ), array() );
        $before = $this->before_write();
        $body = $this->dispatch( $action, array( 'groupId' => $group, 'newName' => 'Refused rename', 'websiteIds' => array( $site ) ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( $action, $body );
        $this->assert_refusal_ui( 'mainwp-groups.js', $handler, $body, $action );
    }

    /**
     * @dataProvider existing_group_actions
     * @param string $action AJAX action.
     */
    public function test_missing_group_is_refused( $action ): void {
        $before = $this->before_write();
        $body   = $this->dispatch( $action, array( 'groupId' => 99999999, 'newName' => 'Missing', 'websiteIds' => array() ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( $action, $body );
    }

    /** Empty granted groups need no site overlap. */
    public function test_granted_empty_group_can_be_renamed_filled_and_deleted(): void {
        $site  = $this->create_test_site();
        $group = $this->group();
        $this->scope( array( $site ), array( $group ) );
        $this->before_write();
        $body = $this->dispatch( 'mainwp_group_rename', array( 'groupId' => $group, 'newName' => 'Granted empty', 'newColor' => '#123456' ) );
        $this->assertSame( 'Granted empty', json_decode( $body, true )['result'] );
        $this->assertSame( '#123456', MainWP_DB_Common::instance()->get_group_by_id( $group )->color );
        $body = $this->dispatch( 'mainwp_group_updategroup', array( 'groupId' => $group, 'websiteIds' => array( $site ) ) );
        $this->assertTrue( json_decode( $body, true )['result'] );
        $this->assertSame( array( $site ), $this->members( $group ) );
        $this->assertTrue( json_decode( $this->dispatch( 'mainwp_group_updategroup', array( 'groupId' => $group ) ), true )['result'] );
        $this->assertSame( array(), $this->members( $group ) );
        $this->assertSame( 'OK', $this->dispatch( 'mainwp_group_delete', array( 'groupId' => $group ) ) );
        $this->assertNull( MainWP_DB_Common::instance()->get_group_by_id( $group ) );
        $this->assertSame( 2, $this->hook_calls['mainwp_site_tag_action'] );
    }

    /** Rename only requires the group grant, even with hidden members. */
    public function test_rename_does_not_require_site_coverage(): void {
        $hidden = $this->create_test_site();
        $group  = $this->group( array( $hidden ) );
        $this->scope( array(), array( $group ) );
        $body = $this->dispatch( 'mainwp_group_rename', array( 'groupId' => $group, 'newName' => 'Renamed hidden roster', 'newColor' => '#123456' ) );
        $this->assertSame( 'Renamed hidden roster', json_decode( $body, true )['result'] );
        $this->assertSame( array( $hidden ), $this->members( $group ) );
    }

    /** Create requires no grant for a group that does not exist yet. */
    public function test_create_allows_accessible_sites_without_a_group_grant(): void {
        $site = $this->create_test_site();
        $this->scope( array( $site ), array() );
        $this->before_write();
        $body  = $this->dispatch( 'mainwp_group_sites_add', array( 'newName' => 'New granted tag', 'selected_sites' => array( $site ) ) );
        $group = (int) json_decode( $body, true )['success'];
        $this->assertGreaterThan( 0, $group );
        $this->assertSame( array( $site ), $this->members( $group ) );
        $this->assertSame( 1, $this->hook_calls['mainwp_added_new_group'] );
        $this->assertSame( 1, $this->hook_calls['mainwp_site_tag_action'] );
    }

    /**
     * @dataProvider group_actions
     * @param string $action AJAX action.
     */
    public function test_missing_manage_groups_refuses_every_action( $action ): void {
        $site  = $this->create_test_site();
        $group = $this->group( array( $site ) );
        $this->filter( 'mainwp_currentusercan', static function ( $can, $type, $right ) {
            return 'manage_groups' === $right ? false : $can;
        } );
        $before = $this->before_write();
        $body   = $this->dispatch( $action, array( 'groupId' => $group, 'newName' => 'No right', 'newColor' => '#123456', 'websiteIds' => array(), 'selected_sites' => array( $site ) ) );
        $this->assert_unchanged( $before );
        $this->assert_permission_response( $action, $body );
    }

    /**
     * @dataProvider group_actions
     * @param string $action AJAX action.
     */
    public function test_unrestricted_admin_keeps_each_action_working( $action ): void {
        $site  = $this->create_test_site();
        $other = $this->create_test_site();
        $group = $this->group( array( $site ) );
        $this->scope( 'all', 'all' );
        $this->before_write();
        $body = $this->dispatch( $action, array( 'groupId' => $group, 'newName' => 'Unrestricted write', 'newColor' => '#123456', 'websiteIds' => array( $other ), 'selected_sites' => array( $other ) ) );
        if ( 'mainwp_group_delete' === $action ) {
            $this->assertSame( 'OK', $body );
            $this->assertNull( MainWP_DB_Common::instance()->get_group_by_id( $group ) );
            $this->assertSame( 1, $this->hook_calls['mainwp_site_tag_action'] );
        } elseif ( 'mainwp_group_updategroup' === $action ) {
            $this->assertTrue( json_decode( $body, true )['result'] );
            $this->assertSame( array( $other ), $this->members( $group ) );
        } elseif ( 'mainwp_group_rename' === $action ) {
            $this->assertSame( 'Unrestricted write', json_decode( $body, true )['result'] );
            $this->assertSame( 1, $this->hook_calls['mainwp_site_tag_action'] );
        } else {
            $created = MainWP_DB_Common::instance()->get_group_by_name( 'Unrestricted write' );
            $this->assertIsObject( $created );
            if ( 'mainwp_group_sites_add' === $action ) {
                $this->assertSame( (int) $created->id, (int) json_decode( $body, true )['success'] );
                $this->assertSame( array( $other ), $this->members( $created->id ) );
            } else {
                $this->assertStringContainsString( 'Unrestricted write', $body );
                $this->assertSame( array(), $this->members( $created->id ) );
            }
            $this->assertSame( 1, $this->hook_calls['mainwp_added_new_group'] );
            $this->assertSame( 1, $this->hook_calls['mainwp_site_tag_action'] );
        }
    }

    /** @return array All five group write actions. */
    public static function group_actions(): array {
        return array_merge( self::existing_group_actions(), array( array( 'mainwp_group_sites_add' ), array( 'mainwp_group_add' ) ) );
    }

    /**
     * @dataProvider invalid_groups
     * @param string $case Group case.
     */
    public function test_site_edit_refuses_ungranted_or_missing_posted_group( $case ): void {
        $site    = $this->create_test_site();
        $current = $this->group( array( $site ) );
        $denied  = $this->group();
        $this->scope( array( $site ), array( $current ) );
        $before = $this->before_write();
        $result = $this->edit_site( $site, array( $current, 'missing' === $case ? 99999999 : $denied ) );
        $this->assert_unchanged( $before );
        $this->assertFalse( $result );
    }

    /** @return array Invalid numeric group cases. */
    public static function invalid_groups(): array {
        return array( 'denied' => array( 'denied' ), 'missing' => array( 'missing' ) );
    }

    /**
     * @dataProvider edit_selections
     * @param bool $empty Whether to omit all granted memberships.
     */
    public function test_site_edit_preserves_hidden_current_memberships( $empty ): void {
        $site   = $this->create_test_site();
        $old    = $this->group( array( $site ) );
        $hidden = $this->group( array( $site ) );
        $new    = $this->group();
        $this->scope( array( $site ), array( $old, $new ) );
        $this->filter( 'mainwp_currentusercan', static function ( $can, $type, $right ) {
            return 'manage_groups' === $right ? false : $can;
        } );
        $this->before_write();
        $this->assertTrue( $this->edit_site( $site, $empty ? array() : array( $new ) ) );
        $this->assertSame( array( $site ), $this->members( $hidden ) );
        $this->assertSame( array(), $this->members( $old ) );
        $this->assertSame( $empty ? array() : array( $site ), $this->members( $new ) );
        $this->assertSame( 1, $this->hook_calls['mainwp_update_site'] );
    }

    /** @return array Editable membership selections. */
    public static function edit_selections(): array {
        return array( 'replacement' => array( false ), 'empty' => array( true ) );
    }

    /** Unrestricted site edits still replace the entire membership list. */
    public function test_unrestricted_site_edit_replaces_memberships(): void {
        $site = $this->create_test_site();
        $old  = $this->group( array( $site ) );
        $new  = $this->group();
        $this->assertTrue( $this->edit_site( $site, array( $new ) ) );
        $this->assertSame( array(), $this->members( $old ) );
        $this->assertSame( array( $site ), $this->members( $new ) );
    }

    /**
     * @dataProvider invalid_groups
     * @param string $case Group case.
     */
    public function test_add_site_refuses_ungranted_or_missing_numeric_group( $case ): void {
        $allowed = $this->group();
        $denied  = $this->group();
        $this->scope( 'all', array( $allowed ) );
        $before = $this->before_write();
        $result = $this->add_site( array( $allowed, 'missing' === $case ? 99999999 : $denied ) );
        $this->assertSame( 0, $this->register_calls, 'Refused groups must not reach Child registration.' );
        $this->assert_unchanged( $before );
        $this->assertSame( 0, (int) $result[2] );
        $this->assertStringContainsString( 'permission', strtolower( $result[1] ) );
    }

    /** Numeric grants and group creation by name retain the existing add path. */
    public function test_add_site_allows_granted_numeric_group_and_new_name(): void {
        $group = $this->group();
        $this->scope( 'all', array( $group ) );
        $this->filter( 'mainwp_currentusercan', static function ( $can, $type, $right ) {
            return 'manage_groups' === $right ? false : $can;
        } );
        $result = $this->add_site( array( $group, 'Named group' ) );
        $site   = (int) $result[2];
        $this->assertSame( '', $result[1] );
        $this->assertGreaterThan( 0, $site );
        $this->assertSame( array( $site ), $this->members( $group ) );
        global $wpdb;
        $named_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_group WHERE name = %s", 'Named group' ) );
        $named = MainWP_DB_Common::instance()->get_group_by_id( $named_id );
        $this->assertIsObject( $named );
        $this->assertSame( array( $site ), $this->members( $named->id ) );
    }

    /** @param mixed $sites Site grants. @param mixed $groups Group grants. */
    private function scope( $sites, $groups ): void {
        $this->filter( 'mainwp_currentuserallowedaccesssites', static function () use ( $sites ) { return $sites; } );
        $this->filter( 'mainwp_currentuserallowedaccessgroups', static function () use ( $groups ) { return $groups; } );
    }

    /** @param string $hook Hook name. @param callable $callback Test callback. */
    private function filter( $hook, $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /** @param array $sites Initial members. @return int Group ID. */
    private function group( $sites = array() ): int {
        $id = (int) MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'Fixture ' . wp_generate_uuid4(), '#cce2ff' );
        $this->assertGreaterThan( 0, $id );
        foreach ( $sites as $site ) {
            MainWP_DB_Common::instance()->update_group_site( $id, $site );
        }
        return $id;
    }

    /** @param int $group Group ID. @return array Complete roster. */
    private function members( $group ): array {
        $ids = array_map( 'intval', wp_list_pluck( MainWP_DB::instance()->get_websites_by_group_ids( array( $group ) ), 'wpid' ) );
        sort( $ids );
        return $ids;
    }

    /** @return array Database state before the request. */
    private function before_write(): array {
        $this->hook_calls = array_fill_keys( array_keys( $this->hook_calls ), 0 );
        return $this->snapshot();
    }

    /** @return array Unscoped rows, including site data for P7/P8 atomicity. */
    private function snapshot(): array {
        global $wpdb;
        return array(
            'groups' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_group ORDER BY id", ARRAY_A ),
            'links'  => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp_group ORDER BY groupid, wpid", ARRAY_A ),
            'sites'  => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mainwp_wp ORDER BY id", ARRAY_A ),
        );
    }

    /** @param array $before Original rows. */
    private function assert_unchanged( $before ): void {
        $this->assertSame( $before, $this->snapshot(), 'A refused write must leave all group, membership and site rows unchanged.' );
        $this->assertSame( array_fill_keys( array_keys( $this->hook_calls ), 0 ), $this->hook_calls, 'A refused write must not fire mutation hooks.' );
    }

    /** @param string $action AJAX action. @param string $body Response. */
    private function assert_permission_response( $action, $body ): void {
        if ( 'mainwp_group_delete' === $action ) {
            $this->assertStringStartsWith( 'ERROR', $body );
            $this->assertStringContainsString( 'permission', strtolower( $body ) );
            return;
        }
        $json = json_decode( $body, true );
        $this->assertIsArray( $json, 'Expected JSON refusal: ' . $body );
        $this->assertArrayHasKey( 'error', $json );
        $this->assertStringContainsString( 'permission', strtolower( $json['error'] ) );
        if ( 'mainwp_group_updategroup' === $action ) {
            $this->assertFalse( $json['result'] );
        }
    }

    /** @param string $action AJAX action. @param array $post Request fields. @return string Response. */
    private function dispatch( $action, $post ): string {
        $nonce    = wp_create_nonce( $action );
        $_POST    = array_merge( $post, array( 'security' => $nonce ) );
        $_REQUEST = $_POST;
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $method = 'mainwp_group_sites_add' === $action ? 'ajax_group_sites_add' : $action;
            Group_Write_Post_Handler::instance()->$method();
        } catch ( \WPDieException $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /** @return callable Captures AJAX termination. */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new \WPDieException( (string) $message );
        };
    }

    /** @param int $site Site ID. @param array $groups Posted group IDs. @return bool Save result. */
    private function edit_site( $site, $groups ): bool {
        $_POST = array(
            'submit'                              => 'Save',
            'wp_nonce'                            => wp_create_nonce( 'UpdateWebsite' . $site ),
            'mainwp_managesites_edit_siteadmin'    => 'admin',
            'mainwp_managesites_edit_sitename'     => 'Edited site',
            'mainwp_managesites_edit_addgroups'    => implode( ',', $groups ),
            'mainwp_managesites_edit_siteurl_protocol' => 'https',
        );
        $_REQUEST = array( 'id' => $site );
        $method = new \ReflectionMethod( MainWP_Manage_Sites::class, 'update_site_handle' );
        $method->setAccessible( true );
        return $method->invoke( null, MainWP_DB::instance()->get_website_by_id( $site ) );
    }

    /** @param array $groups Group IDs or names. @return array Existing add-site response tuple. */
    private function add_site( $groups ): array {
        $this->filter( 'mainwp_fetch_url_not_authed_pre', function () { ++$this->register_calls; return array( 'register' => 'OK' ); } );
        $this->filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
        $this->filter( 'mainwp_fetch_url_authed_pre', static function () { return array(); } );
        $_POST = array(
            'managesites_add_wpurl'   => 'https://group-write-' . wp_generate_uuid4() . '.example.com/',
            'managesites_add_wpname'  => 'Group write site',
            'managesites_add_wpadmin' => 'admin',
            'groupids'               => implode( ',', $groups ),
        );
        return MainWP_Manage_Sites_View::add_site();
    }
}
