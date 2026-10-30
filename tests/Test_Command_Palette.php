<?php
/**
 * Command palette search ranking and entity authorization regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Command_Palette;

/** Search the real MainWP tables through the production palette helpers. */
class Test_Command_Palette extends MainWP_Abilities_Test_Case {

    /** @var array|string Team Control grants for the current test. */
    private $allowed = 'all';

    /** Configure an administrator and predictable, test-owned scope filters. */
    public function setUp(): void {
        parent::setUp();
        $this->set_current_user_as_admin();
        $this->allowed = 'all';
        add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
        add_filter( 'mainwp_is_single_user', '__return_true', 999 );
        add_filter( 'mainwp_currentusercan', '__return_true', 999 );
    }

    /** Restore the shared suite's authorization filters. */
    public function tearDown(): void {
        remove_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
        remove_filter( 'mainwp_is_single_user', '__return_true', 999 );
        remove_filter( 'mainwp_currentusercan', '__return_true', 999 );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** @return array|string Test-specific site grants. */
    public function allowed_sites() {
        return $this->allowed;
    }

    /** Exact and prefix matches must survive a five-result limit. */
    public function test_site_name_ranking_happens_before_the_limit(): void {
        $query = 'palette-' . wp_generate_uuid4();
        for ( $i = 0; $i < 5; ++$i ) {
            $this->create_test_site( array( 'name' => 'Other ' . $query . ' match ' . $i, 'url' => 'https://test-aa' . $i . '.example.com/' ) );
        }
        $prefix = $this->create_test_site( array( 'name' => $query . ' prefix', 'url' => 'https://test-yy.example.com/' ) );
        $exact  = $this->create_test_site( array( 'name' => $query, 'url' => 'https://test-zz.example.com/' ) );

        $commands = MainWP_Command_Palette::get_site_commands( $query );

        $this->assertCount( 5, $commands );
        $this->assertSame( 'mainwp/site/' . $exact, $commands[0]['name'] );
        $this->assertSame( 'mainwp/site/' . $prefix, $commands[1]['name'] );
    }

    /** A full URL resolves the exact host ahead of URLs containing that host. */
    public function test_normalized_url_ranking_happens_before_the_limit(): void {
        $host = 'test-zz-' . wp_generate_uuid4() . '.example.com';
        for ( $i = 0; $i < 6; ++$i ) {
            $this->create_test_site( array( 'url' => 'https://test-aa' . $i . '.example.com/' . $host . '/' ) );
        }
        $exact = $this->create_test_site( array( 'url' => 'https://www.' . $host . '/' ) );

        $commands = MainWP_Command_Palette::get_site_commands( 'http://www.' . $host . '/' );

        $this->assertCount( 5, $commands );
        $this->assertSame( 'mainwp/site/' . $exact, $commands[0]['name'] );
    }

    /** Hidden exact matches cannot displace an allowed result. */
    public function test_site_scope_is_applied_before_ranking_and_recent_resolution(): void {
        $this->skip_when_capabilities_bypassed();
        $query = 'palette-' . wp_generate_uuid4();
        $hidden = $this->create_test_site( array( 'name' => $query ) );
        $visible = $this->create_test_site( array( 'name' => 'Other ' . $query ) );
        $staging = $this->create_test_site( array( 'name' => $query, 'is_staging' => 1 ) );
        $this->allowed = array( $visible, $staging );

        $commands = MainWP_Command_Palette::get_site_commands( $query );
        $this->assertSame( array( 'mainwp/site/' . $visible ), array_column( $commands, 'name' ) );
        $recent = MainWP_Command_Palette::get_recent_commands( array( 'mainwp/site/' . $hidden, 'mainwp/site/' . $visible, 'mainwp/site/' . $staging ) );
        $this->assertSame( array( 'mainwp/site/' . $visible ), array_column( $recent, 'name' ) );
        $this->allowed = array();
        $this->assertSame( array(), MainWP_Command_Palette::get_site_commands( $query ) );
    }

    /** Client lookup uses integer-only grants and cannot expose another client. */
    public function test_client_scope_filters_search_and_recent(): void {
        $query   = 'Test Client palette-' . wp_generate_uuid4();
        $hidden  = $this->create_test_client( array( 'name' => $query ) );
        $visible = $this->create_test_client( array( 'name' => $query . ' allowed' ) );
        $this->create_test_site( array( 'client_id' => $hidden ) );
        $allowed_site = $this->create_test_site( array( 'client_id' => $visible ) );
        $this->allowed = array( (string) $allowed_site, '0) OR 1=1 --', -1 );

        $commands = MainWP_Command_Palette::get_client_commands( $query );
        $this->assertSame( array( 'mainwp/client/' . $visible ), array_column( $commands, 'name' ) );
        $this->assertSame( admin_url( 'admin.php?page=ManageClients&client_id=' . $visible ), $commands[0]['url'] );
        $recent = MainWP_Command_Palette::get_recent_commands( array( 'mainwp/client/' . $hidden, 'mainwp/client/' . $visible ) );
        $this->assertSame( array( 'mainwp/client/' . $visible ), array_column( $recent, 'name' ) );
        $this->allowed = array();
        $this->assertSame( array(), MainWP_Command_Palette::get_client_commands( $query ) );
    }

    /** Recent accepts only bounded native entity IDs, without duplicates. */
    public function test_recent_rejects_malformed_foreign_and_overflow_ids(): void {
        $site = $this->create_test_site();
        $name = 'mainwp/site/' . $site;
        $recent = MainWP_Command_Palette::get_recent_commands( array( $name, 'mainwp/site/0', $name, 'other/site/' . $site, 'mainwp/site/999999999999999999999999999', 'mainwp/client/1' ) );
        $this->assertSame( array( $name ), array_column( $recent, 'name' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_recent_commands( 'mainwp/site/1' ) );
    }

    /** No entity data is returned outside an administrator session. */
    public function test_non_admin_cannot_resolve_entities(): void {
        $site = $this->create_test_site();
        $this->set_current_user_as_subscriber();
        $this->assertSame( array(), MainWP_Command_Palette::get_site_commands( 'Test Site' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_client_commands( 'Test Client' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_recent_commands( array( 'mainwp/site/' . $site ) ) );
    }
}
