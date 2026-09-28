<?php
/**
 * Tests for tag access and scoped member data.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;

/**
 * Class Test_Abilities_Tags_Scoped_Access
 */
class Test_Abilities_Tags_Scoped_Access extends MainWP_Abilities_Test_Case {

    /**
     * Keep unrelated callbacks on shared hooks intact during cleanup.
     *
     * @var array<string, callable>
     */
    private $scope_filters = array();

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

    // =========================================================================
    // FIXTURE HELPERS
    // =========================================================================

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

    // =========================================================================
    // mainwp/list-tags-v1
    // =========================================================================

    /**
     * A groups-restricted admin only sees tags in the allowed group set.
     *
     * @return void
     */
    public function test_list_tags_returns_only_accessible_tags_for_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_a = $this->create_test_tag( array( 'name' => 'Test Tag Scoped List A' ) );
        $tag_b = $this->create_test_tag( array( 'name' => 'Test Tag Scoped List B' ) );

        $this->restrict_current_user_to_groups( array( $tag_a ) );

        $result = $this->execute_ability( 'mainwp/list-tags-v1', array() );

        $this->assertNotWPError( $result, 'A groups-restricted admin must still be able to list accessible tags.' );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $tag_a, $ids, 'A tag in the allowed group set must be listed.' );
        $this->assertNotContains( $tag_b, $ids, 'A tag outside the allowed group set must not be listed.' );
    }

    /**
     * Unrestricted control: list-tags returns every tag regardless of scope.
     *
     * @return void
     */
    public function test_list_tags_returns_all_tags_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_a = $this->create_test_tag( array( 'name' => 'Test Tag Scoped List Control A' ) );
        $tag_b = $this->create_test_tag( array( 'name' => 'Test Tag Scoped List Control B' ) );

        $result = $this->execute_ability( 'mainwp/list-tags-v1', array() );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $tag_a, $ids );
        $this->assertContains( $tag_b, $ids );

        $this->restrict_current_user_to_groups( array() );
        $scoped = $this->execute_ability( 'mainwp/list-tags-v1', array() );
        $this->assertNotWPError( $scoped );
        $this->assertSame( array(), $scoped['items'] );
        $this->assertSame( 0, $scoped['total'] );
    }

    // =========================================================================
    // mainwp/get-tag-v1
    // =========================================================================

    /**
     * A tag inside the allowed group set is accessible.
     *
     * @return void
     */
    public function test_get_tag_allows_when_tag_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Get Allowed' ) );

        $this->restrict_current_user_to_groups( array( $tag_id ) );

        $result = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $tag_id ) );

        $this->assertNotWPError( $result, 'A tag inside the allowed group set must be accessible.' );
        $this->assertEquals( $tag_id, $result['id'] );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $tag_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    /**
     * A tag outside the allowed group set is denied.
     *
     * @return void
     */
    public function test_get_tag_denies_when_tag_not_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Get Denied' ) );
        $other  = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Get Denied Other' ) );

        $this->restrict_current_user_to_groups( array( $other ) );

        $result = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $tag_id ) );

        $this->assertWPError( $result, 'A tag outside the allowed group set must deny access.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
    }

    /**
     * Unrestricted control: get-tag still succeeds.
     *
     * @return void
     */
    public function test_get_tag_succeeds_for_unrestricted_admin_control() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Get Control' ) );

        $result = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $tag_id ) );

        $this->assertNotWPError( $result );
        $this->assertEquals( $tag_id, $result['id'] );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $tag_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    // =========================================================================
    // mainwp/add-tag-v1
    // =========================================================================

    /**
     * Tag creation stays open for a groups-restricted admin.
     *
     * @return void
     */
    public function test_add_tag_succeeds_for_groups_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $unrelated_group = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Add Unrelated' ) );
        $this->restrict_current_user_to_groups( array( $unrelated_group ) );

        $name = 'Test Tag Scoped Add ' . wp_generate_uuid4();

        $result = $this->execute_ability( 'mainwp/add-tag-v1', array( 'name' => $name ) );

        $this->assertNotWPError( $result, 'Tag creation must stay open for a groups-restricted admin.' );
        $this->assertSame( $name, $result['name'] );

        $denied = $this->execute_ability( 'mainwp/get-tag-v1', array( 'tag_id' => $result['id'] ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    // =========================================================================
    // mainwp/update-tag-v1
    // =========================================================================

    /**
     * A tag outside the allowed group set denies the update and leaves the name unchanged.
     *
     * @return void
     */
    public function test_update_tag_denies_when_tag_not_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $original_name = 'Test Tag Scoped Update Original';
        $tag_id        = $this->create_test_tag( array( 'name' => $original_name ) );
        $other         = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Update Other' ) );

        $this->restrict_current_user_to_groups( array( $other ) );

        $result = $this->execute_ability(
            'mainwp/update-tag-v1',
            array(
                'tag_id' => $tag_id,
                'name'   => 'Test Tag Scoped Update Changed',
            )
        );

        $this->assertWPError( $result, 'A tag outside the allowed group set must deny the update.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $unchanged = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
        $this->assertSame( $original_name, $unchanged->name, 'The tag name must not change when access is denied.' );
    }

    /**
     * A tag inside the allowed group set allows the update.
     *
     * @return void
     */
    public function test_update_tag_allows_when_tag_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Update Allowed Original' ) );

        $this->restrict_current_user_to_groups( array( $tag_id ) );

        $result = $this->execute_ability(
            'mainwp/update-tag-v1',
            array(
                'tag_id' => $tag_id,
                'name'   => 'Test Tag Scoped Update Allowed Changed',
            )
        );

        $this->assertNotWPError( $result, 'A tag inside the allowed group set must allow the update.' );
        $this->assertSame( 'Test Tag Scoped Update Allowed Changed', $result['name'] );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/update-tag-v1', array( 'tag_id' => $tag_id, 'name' => 'Denied Change' ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    /**
     * The update response trims member-site data to the allowed site set.
     *
     * @return void
     */
    public function test_update_tag_scopes_member_sites_in_output() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Update Output' ) );
        $site_x = $this->create_test_site();
        $site_y = $this->create_test_site();
        $this->assign_site_to_tag( $site_x, $tag_id );
        $this->assign_site_to_tag( $site_y, $tag_id );

        $this->restrict_current_user_to_groups( array( $tag_id ) );
        $this->restrict_current_user_to_sites( array( $site_x ) );

        $result = $this->execute_ability(
            'mainwp/update-tag-v1',
            array(
                'tag_id' => $tag_id,
                'name'   => 'Test Tag Scoped Update Output Changed',
            )
        );

        $this->assertNotWPError( $result, 'A tag inside the allowed group set must allow the update.' );
        $this->assertContains( $site_x, $result['sites_ids'], 'The allowed member site must stay in the update response.' );
        $this->assertNotContains( $site_y, $result['sites_ids'], 'The disallowed member site must be trimmed from the update response.' );
        $this->assertSame( 1, $result['sites_count'], 'sites_count must reflect only the allowed slice.' );
    }

    // =========================================================================
    // mainwp/delete-tag-v1
    // =========================================================================

    /**
     * A tag outside the allowed group set denies deletion and the tag still exists.
     *
     * @return void
     */
    public function test_delete_tag_denies_when_tag_not_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Delete Denied' ) );
        $other  = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Delete Denied Other' ) );

        $this->restrict_current_user_to_groups( array( $other ) );

        $result = $this->execute_ability(
            'mainwp/delete-tag-v1',
            array(
                'tag_id'  => $tag_id,
                'confirm' => true,
            )
        );

        $this->assertWPError( $result, 'A tag outside the allowed group set must deny deletion.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $still_there = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
        $this->assertNotEmpty( $still_there, 'The tag must not be deleted when access is denied.' );
    }

    /**
     * A tag inside the allowed group set allows deletion.
     *
     * @return void
     */
    public function test_delete_tag_allows_when_tag_in_allowed_groups() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Delete Allowed' ) );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/delete-tag-v1', array( 'tag_id' => $tag_id, 'confirm' => true ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );

        $this->restrict_current_user_to_groups( array( $tag_id ) );

        $result = $this->execute_ability(
            'mainwp/delete-tag-v1',
            array(
                'tag_id'  => $tag_id,
                'confirm' => true,
            )
        );

        $this->assertNotWPError( $result, 'A tag inside the allowed group set must allow deletion.' );

        $gone = MainWP_DB_Common::instance()->get_group_by_id( $tag_id );
        $this->assertEmpty( $gone, 'The tag must be gone after an allowed deletion.' );
    }

    /**
     * The delete dry-run reports impact from the caller's slice, not global counts.
     *
     * @return void
     */
    public function test_delete_tag_dry_run_scopes_counts_to_allowed_sites() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id   = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Dry Run' ) );
        $client_a = $this->create_test_client( array( 'name' => 'Test Client Scoped Dry Run A' ) );
        $site_x   = $this->create_test_site( array( 'client_id' => $client_a ) );
        $client_b = $this->create_test_client( array( 'name' => 'Test Client Scoped Dry Run B' ) );
        $site_y   = $this->create_test_site( array( 'client_id' => $client_b ) );
        $this->assign_site_to_tag( $site_x, $tag_id );
        $this->assign_site_to_tag( $site_y, $tag_id );

        $this->restrict_current_user_to_groups( array( $tag_id ) );
        $this->restrict_current_user_to_sites( array( $site_x ) );

        $result = $this->execute_ability(
            'mainwp/delete-tag-v1',
            array(
                'tag_id'  => $tag_id,
                'dry_run' => true,
            )
        );

        $this->assertNotWPError( $result, 'A dry-run on an allowed tag must succeed for a restricted caller.' );
        $this->assertSame( 1, $result['would_affect']['sites_count'], 'Dry-run sites_count must reflect only the allowed slice.' );
        $this->assertSame( 1, $result['would_affect']['clients_count'], 'Dry-run clients_count must reflect only reachable clients.' );
    }

    // =========================================================================
    // mainwp/get-tag-sites-v1 (double-scoping: groups AND sites)
    // =========================================================================

    /**
     * A tag inside the allowed groups still trims member sites to the allowed site set.
     *
     * @return void
     */
    public function test_get_tag_sites_double_scopes_by_allowed_sites() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Sites' ) );
        $site_x = $this->create_test_site();
        $site_y = $this->create_test_site();
        $this->assign_site_to_tag( $site_x, $tag_id );
        $this->assign_site_to_tag( $site_y, $tag_id );

        $this->restrict_current_user_to_groups( array( $tag_id ) );
        $this->restrict_current_user_to_sites( array( $site_x ) );

        $result = $this->execute_ability( 'mainwp/get-tag-sites-v1', array( 'tag_id' => $tag_id ) );

        $this->assertNotWPError( $result, 'A tag inside the allowed groups must still be readable.' );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $site_x, $ids, 'The allowed member site must be included.' );
        $this->assertNotContains( $site_y, $ids, 'The disallowed member site must be trimmed out.' );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/get-tag-sites-v1', array( 'tag_id' => $tag_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    // =========================================================================
    // mainwp/get-tag-clients-v1 (double-scoping: groups AND sites)
    // =========================================================================

    /**
     * A tag inside the allowed groups only surfaces clients reachable via allowed sites.
     *
     * @return void
     */
    public function test_get_tag_clients_filters_clients_by_allowed_sites() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $tag_id   = $this->create_test_tag( array( 'name' => 'Test Tag Scoped Clients' ) );
        $client_a = $this->create_test_client( array( 'name' => 'Test Client Scoped Tag Clients A' ) );
        $site_a   = $this->create_test_site( array( 'client_id' => $client_a ) );
        $this->assign_site_to_tag( $site_a, $tag_id );
        $client_b = $this->create_test_client( array( 'name' => 'Test Client Scoped Tag Clients B' ) );
        $site_b   = $this->create_test_site( array( 'client_id' => $client_b ) );
        $this->assign_site_to_tag( $site_b, $tag_id );

        $this->restrict_current_user_to_groups( array( $tag_id ) );
        $this->restrict_current_user_to_sites( array( $site_a ) );

        $result = $this->execute_ability( 'mainwp/get-tag-clients-v1', array( 'tag_id' => $tag_id ) );

        $this->assertNotWPError( $result, 'A tag inside the allowed groups must still be readable.' );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $client_a, $ids, 'A client reachable via an allowed site must be included.' );
        $this->assertNotContains( $client_b, $ids, 'A client reachable only via a disallowed site must be filtered out.' );

        $this->restrict_current_user_to_groups( array() );
        $denied = $this->execute_ability( 'mainwp/get-tag-clients-v1', array( 'tag_id' => $tag_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }
}
