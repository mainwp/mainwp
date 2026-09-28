<?php
/**
 * MainWP UpdateSite Ability Tests
 *
 * Tests for the mainwp/update-site-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/update-site-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_UpdateSite_Ability extends MainWP_Abilities_Test_Case {

    /**
     * Keep unrelated callbacks on shared hooks intact during cleanup.
     *
     * @var array<string, callable>
     */
    private $scope_filters = array();

    /**
     * Test that the ability is registered.
     *
     * @return void
     */
    public function test_ability_is_registered() {
        $this->skip_if_no_abilities_api();

        $ability = wp_get_ability( 'mainwp/update-site-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/update-site-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_update_site_returns_expected_structure() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site( [
            'name' => 'Test Site',
            'url'  => 'https://test-update-site.example.com/',
        ] );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'name'              => 'Updated Site Name',
        ] );

        $this->assertNotWPError( $result, 'Should return successful result.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('Updated Site Name', $result['name']);
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_update_site_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => 1,
        ] );

        $this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test that users without manage_options capability are denied.
     *
     * @return void
     */
    public function test_update_site_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => 1,
        ] );

        $this->assertWPError( $result, 'Subscriber should be denied.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test that update-site returns error for non-existent site.
     *
     * @return void
     */
    public function test_update_site_returns_error_for_nonexistent_site() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => 999999, // Non-existent site
        ] );

        $this->assertWPError( $result, 'Non-existent site should return WP_Error.' );
    }

    /**
     * Scope the current user's site access via the allowed-sites filter.
     *
     * @param array $site_ids Allowed site IDs.
     *
     * @return void
     */
    private function restrict_current_user_to_sites( array $site_ids ): void {
        if ( isset( $this->scope_filters['mainwp_currentuserallowedaccesssites'] ) ) {
            remove_filter( 'mainwp_currentuserallowedaccesssites', $this->scope_filters['mainwp_currentuserallowedaccesssites'] );
        }
        $callback = static function () use ( $site_ids ) {
            return $site_ids;
        };
        $this->scope_filters['mainwp_currentuserallowedaccesssites'] = $callback;
        add_filter( 'mainwp_currentuserallowedaccesssites', $callback );
    }

    /**
     * Scope the current user's group access via the allowed-groups filter.
     *
     * @param array $group_ids Allowed group (tag) IDs.
     *
     * @return void
     */
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

    /**
     * Test that a nonexistent client_id is rejected before any mutation.
     *
     * @return void
     */
    public function test_update_site_rejects_nonexistent_client() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $site_id = $this->create_test_site();

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'client_id'         => 999999,
        ] );

        $this->assertWPError( $result, 'A nonexistent client must be rejected.' );
        $this->assertSame( 'mainwp_client_not_found', $result->get_error_code() );
    }

    /**
     * Test that assigning a site to a client outside the caller's scope is denied.
     *
     * @return void
     */
    public function test_update_site_rejects_out_of_scope_client() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_b = $this->create_test_client( [ 'name' => 'Test Client Update Site Out Of Scope' ] );
        $site_y   = $this->create_test_site( [ 'client_id' => $client_b ] );
        $site_x   = $this->create_test_site();

        $this->restrict_current_user_to_sites( [ $site_x ] );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_x,
            'client_id'         => $client_b,
        ] );

        $this->assertWPError( $result, 'Assigning a site to an out-of-scope client must be denied.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_x );
        $this->assertSame( 0, (int) $site->client_id, 'The site must not be attached to the denied client.' );
    }

    /**
     * Test that a nonexistent tag id in tag_ids is rejected before any mutation.
     *
     * @return void
     */
    public function test_update_site_rejects_nonexistent_tag_ids() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id  = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Existing' ] );
        $site_id = $this->create_test_site();
        $this->assign_site_to_tag( $site_id, $tag_id );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'tag_ids'           => [ 999999 ],
        ] );

        $this->assertWPError( $result, 'A nonexistent tag must be rejected.' );
        $this->assertSame( 'mainwp_tag_not_found', $result->get_error_code() );

        $groups = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_groups_by_website_id( $site_id );
        $this->assertArrayHasKey( $tag_id, (array) $groups, 'Existing tag associations must survive a rejected update.' );
    }

    /**
     * Test that tag_ids containing a tag outside the caller's allowed groups is denied.
     *
     * @return void
     */
    public function test_update_site_rejects_out_of_scope_tag_ids() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_allowed  = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Allowed' ] );
        $tag_denied   = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Denied' ] );
        $site_id      = $this->create_test_site();

        $this->restrict_current_user_to_sites( [ $site_id ] );
        $this->restrict_current_user_to_groups( [ $tag_allowed ] );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'tag_ids'           => [ $tag_denied ],
        ] );

        $this->assertWPError( $result, 'A tag outside the allowed group set must be denied.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
    }

    /**
     * Test that out-of-scope tag associations survive a scoped tag update.
     *
     * @return void
     */
    public function test_update_site_preserves_out_of_scope_tag_associations() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_in_scope     = $this->create_test_tag( [ 'name' => 'Test Tag Update Site In Scope' ] );
        $tag_out_of_scope = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Unseen' ] );
        $tag_new          = $this->create_test_tag( [ 'name' => 'Test Tag Update Site New' ] );
        $site_id          = $this->create_test_site();
        $this->assign_site_to_tag( $site_id, $tag_in_scope );
        $this->assign_site_to_tag( $site_id, $tag_out_of_scope );

        $this->restrict_current_user_to_sites( [ $site_id ] );
        $this->restrict_current_user_to_groups( [ $tag_in_scope, $tag_new ] );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'tag_ids'           => [ $tag_new ],
        ] );

        $this->assertNotWPError( $result, 'A scoped tag update within the allowed groups must succeed.' );

        $groups    = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_groups_by_website_id( $site_id );
        $group_ids = array_map( 'intval', array_keys( (array) $groups ) );
        $this->assertContains( $tag_new, $group_ids, 'The requested in-scope tag must be attached.' );
        $this->assertContains( $tag_out_of_scope, $group_ids, 'An association the caller cannot see must be preserved.' );
        $this->assertNotContains( $tag_in_scope, $group_ids, 'An in-scope tag omitted from the request must be detached.' );
    }

    /**
     * Test that an unrestricted caller still replaces the tag set wholesale.
     *
     * @return void
     */
    public function test_update_site_replaces_tags_wholesale_for_unrestricted_caller() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_old = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Wholesale Old' ] );
        $tag_new = $this->create_test_tag( [ 'name' => 'Test Tag Update Site Wholesale New' ] );
        $site_id = $this->create_test_site();
        $this->assign_site_to_tag( $site_id, $tag_old );

        $result = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'tag_ids'           => [ $tag_new ],
        ] );

        $this->assertNotWPError( $result );

        $groups    = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_groups_by_website_id( $site_id );
        $group_ids = array_map( 'intval', array_keys( (array) $groups ) );
        $this->assertContains( $tag_new, $group_ids );
        $this->assertNotContains( $tag_old, $group_ids );

        $this->restrict_current_user_to_groups( array( $tag_old ) );
        $scoped = $this->execute_ability( 'mainwp/update-site-v1', [
            'site_id_or_domain' => $site_id,
            'tag_ids'           => [ $tag_old ],
        ] );
        $this->assertNotWPError( $scoped );
        $groups = \MainWP\Dashboard\MainWP_DB_Common::instance()->get_groups_by_website_id( $site_id );
        $this->assertArrayHasKey( $tag_old, (array) $groups );
        $this->assertArrayHasKey( $tag_new, (array) $groups );
    }
    /**
     * Remove only callbacks installed by this test.
     *
     * @return void
     */
    public function tearDown(): void {
        foreach ( $this->scope_filters as $hook => $callback ) {
            remove_filter( $hook, $callback );
        }
        $this->scope_filters = array();
        parent::tearDown();
    }
}
