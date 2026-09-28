<?php
/**
 * Tests for client access and preservation of sites outside the caller scope.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Class Test_Abilities_Clients_Scoped_Access
 */
class Test_Abilities_Clients_Scoped_Access extends MainWP_Abilities_Test_Case {

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
    // mainwp/list-clients-v1
    // =========================================================================

    /**
     * A restricted admin only sees clients with at least one site in the allowed set.
     *
     * @return void
     */
    public function test_list_clients_returns_only_accessible_clients_for_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_a = $this->create_test_client( array( 'name' => 'Test Client Scoped List A' ) );
        $site_a   = $this->create_test_site( array( 'client_id' => $client_a ) );
        $client_b = $this->create_test_client( array( 'name' => 'Test Client Scoped List B' ) );
        $this->create_test_site( array( 'client_id' => $client_b ) );

        $this->restrict_current_user_to_sites( array( $site_a ) );

        $result = $this->execute_ability( 'mainwp/list-clients-v1', array() );

        $this->assertNotWPError( $result, 'A restricted admin must still be able to list accessible clients.' );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $client_a, $ids, 'A client with an allowed site must be listed.' );
        $this->assertNotContains( $client_b, $ids, 'A client with no allowed site must not be listed.' );

        $count = $this->execute_ability( 'mainwp/count-clients-v1', array() );
        $this->assertNotWPError( $count );
        $this->assertSame( 1, $count['total'] );
    }

    /**
     * Unrestricted control: list-clients returns every client regardless of scope.
     *
     * @return void
     */
    public function test_list_clients_returns_all_clients_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_a = $this->create_test_client( array( 'name' => 'Test Client Scoped List Control A' ) );
        $this->create_test_site( array( 'client_id' => $client_a ) );
        $client_b = $this->create_test_client( array( 'name' => 'Test Client Scoped List Control B' ) );
        $this->create_test_site( array( 'client_id' => $client_b ) );

        $result = $this->execute_ability( 'mainwp/list-clients-v1', array() );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $client_a, $ids );
        $this->assertContains( $client_b, $ids );

        $this->restrict_current_user_to_sites( array() );
        $scoped = $this->execute_ability( 'mainwp/list-clients-v1', array() );
        $this->assertNotWPError( $scoped );
        $this->assertSame( array(), $scoped['items'] );
        $this->assertSame( 0, $scoped['total'] );
    }

    // =========================================================================
    // mainwp/get-client-v1
    // =========================================================================

    /**
     * A restricted admin is granted access on any-site overlap with the client.
     *
     * @return void
     */
    public function test_get_client_allows_on_any_site_overlap() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id    = $this->create_test_client( array( 'name' => 'Test Client Scoped Get Overlap' ) );
        $site_allowed = $this->create_test_site( array( 'client_id' => $client_id ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_allowed ) );

        $result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertNotWPError( $result, 'One accessible site out of several must grant client access.' );
        $this->assertEquals( $client_id, $result['id'] );

        $this->restrict_current_user_to_sites( array() );
        $denied = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    /**
     * A restricted admin is denied when none of the client's sites overlap the allowed set.
     *
     * @return void
     */
    public function test_get_client_denies_without_site_overlap() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Get No Overlap' ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );
        $other_site = $this->create_test_site();

        $this->restrict_current_user_to_sites( array( $other_site ) );

        $result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertWPError( $result, 'No overlap between the client sites and the allowed set must deny access.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
    }

    /**
     * Unrestricted control: get-client still succeeds.
     *
     * @return void
     */
    public function test_get_client_succeeds_for_unrestricted_admin_control() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Get Control' ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );

        $result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertNotWPError( $result );
        $this->assertEquals( $client_id, $result['id'] );

        $this->restrict_current_user_to_sites( array() );
        $denied = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    // =========================================================================
    // mainwp/get-client-sites-v1
    // =========================================================================

    /**
     * A restricted admin only sees the allowed subset of a client's sites.
     *
     * @return void
     */
    public function test_get_client_sites_returns_only_allowed_subset() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Sites' ) );
        $site_x    = $this->create_test_site( array( 'client_id' => $client_id ) );
        $site_y    = $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_x ) );

        $result = $this->execute_ability( 'mainwp/get-client-sites-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertNotWPError( $result, 'A restricted admin with overlap must still read the client\'s allowed sites.' );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $site_x, $ids );
        $this->assertNotContains( $site_y, $ids );
        $this->assertSame( 1, $result['total'] );

        $count = $this->execute_ability( 'mainwp/count-client-sites-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertNotWPError( $count );
        $this->assertSame( 1, $count['total'] );

        $this->restrict_current_user_to_sites( array() );
        $denied = $this->execute_ability( 'mainwp/get-client-sites-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    /**
     * Unrestricted control: get-client-sites returns every site of the client.
     *
     * @return void
     */
    public function test_get_client_sites_returns_all_sites_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Sites Control' ) );
        $site_x    = $this->create_test_site( array( 'client_id' => $client_id ) );
        $site_y    = $this->create_test_site( array( 'client_id' => $client_id ) );

        $result = $this->execute_ability( 'mainwp/get-client-sites-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $site_x, $ids );
        $this->assertContains( $site_y, $ids );
        $this->assertSame( 2, $result['total'] );

        $this->restrict_current_user_to_sites( array() );
        $denied = $this->execute_ability( 'mainwp/get-client-sites-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );
    }

    /**
     * Client site lists and counts exclude staging sites for unrestricted admins.
     *
     * @return void
     */
    public function test_get_and_count_client_sites_exclude_staging_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id    = $this->create_test_client( array( 'name' => 'Test Client Staging Sites' ) );
        $live_site    = $this->create_test_site( array( 'client_id' => $client_id ) );
        $staging_site = $this->create_test_site(
            array(
                'client_id'  => $client_id,
                'is_staging' => 1,
            )
        );

        $result = $this->execute_ability( 'mainwp/get-client-sites-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertNotWPError( $result );
        $ids = array_column( $result['items'], 'id' );
        $this->assertContains( $live_site, $ids );
        $this->assertNotContains( $staging_site, $ids );
        $this->assertSame( 1, $result['total'] );

        $count = $this->execute_ability( 'mainwp/count-client-sites-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertNotWPError( $count );
        $this->assertSame( 1, $count['total'] );
    }

    // =========================================================================
    // mainwp/delete-client-v1 (destructive, full coverage required)
    // =========================================================================

    /**
     * Partial site overlap denies deletion and leaves the client row intact.
     *
     * @return void
     */
    public function test_delete_client_denies_partial_overlap_and_preserves_client() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id    = $this->create_test_client( array( 'name' => 'Test Client Scoped Delete Partial' ) );
        $site_allowed = $this->create_test_site( array( 'client_id' => $client_id ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_allowed ) );

        $result = $this->execute_ability(
            'mainwp/delete-client-v1',
            array(
                'client_id_or_email' => $client_id,
                'confirm'             => true,
            )
        );

        $this->assertWPError( $result, 'Partial site coverage must deny a destructive delete-client call.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $still_there = $this->get_test_client( $client_id );
        $this->assertNotNull( $still_there, 'The client row must not be deleted when access is denied.' );
    }

    /**
     * Full site coverage allows deletion.
     *
     * @return void
     */
    public function test_delete_client_allows_full_coverage() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Delete Full' ) );
        $site_a    = $this->create_test_site( array( 'client_id' => $client_id ) );
        $site_b    = $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_a ) );
        $denied = $this->execute_ability(
            'mainwp/delete-client-v1',
            array( 'client_id_or_email' => $client_id, 'confirm' => true )
        );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_access_denied', $denied->get_error_code() );

        $this->restrict_current_user_to_sites( array( $site_a, $site_b ) );

        $result = $this->execute_ability(
            'mainwp/delete-client-v1',
            array(
                'client_id_or_email' => $client_id,
                'confirm'             => true,
            )
        );

        $this->assertNotWPError( $result, 'Full site coverage must allow a destructive delete-client call.' );

        $deleted = $this->get_test_client( $client_id );
        $this->assertNull( $deleted, 'The client row must be gone after an allowed deletion.' );
    }

    // =========================================================================
    // mainwp/suspend-client-v1 (destructive, full coverage required)
    // =========================================================================

    /**
     * Partial site overlap denies suspension.
     *
     * @return void
     */
    public function test_suspend_client_denies_partial_overlap() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id    = $this->create_test_client( array( 'name' => 'Test Client Scoped Suspend Partial' ) );
        $site_allowed = $this->create_test_site( array( 'client_id' => $client_id ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_allowed ) );

        $result = $this->execute_ability( 'mainwp/suspend-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertWPError( $result, 'Partial site coverage must deny a destructive suspend-client call.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $unchanged = $this->get_test_client( $client_id );
        $this->assertSame( 0, (int) $unchanged->suspended, 'The client must remain unsuspended when access is denied.' );
    }

    // =========================================================================
    // mainwp/add-client-v1
    // =========================================================================

    /**
     * An out-of-scope site in selected_sites denies creation and creates no client.
     *
     * @return void
     */
    public function test_add_client_denies_out_of_scope_selected_site() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $allowed_site = $this->create_test_site();
        $other_site   = $this->create_test_site();

        $this->restrict_current_user_to_sites( array( $allowed_site ) );

        $name = 'Test Client Scoped Add ' . wp_generate_uuid4();

        $result = $this->execute_ability(
            'mainwp/add-client-v1',
            array(
                'name'           => $name,
                'selected_sites' => array( $other_site ),
            )
        );

        $this->assertWPError( $result, 'An out-of-scope site in selected_sites must deny client creation.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_clients WHERE name = %s",
                $name
            )
        );
        $this->assertSame( 0, (int) $count, 'No client row should be created when access is denied.' );
    }

    // =========================================================================
    // mainwp/update-client-v1
    // =========================================================================

    /**
     * An out-of-scope site in selected_sites denies the update and leaves the name unchanged.
     *
     * @return void
     */
    public function test_update_client_denies_out_of_scope_selected_site() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $original_name = 'Test Client Scoped Update Original';
        $client_id     = $this->create_test_client( array( 'name' => $original_name ) );
        $allowed_site  = $this->create_test_site( array( 'client_id' => $client_id ) );
        $other_site    = $this->create_test_site();

        $this->restrict_current_user_to_sites( array( $allowed_site ) );

        $result = $this->execute_ability(
            'mainwp/update-client-v1',
            array(
                'client_id_or_email' => $client_id,
                'name'                => 'Test Client Scoped Update Changed',
                'selected_sites'      => array( $other_site ),
            )
        );

        $this->assertWPError( $result, 'An out-of-scope site in selected_sites must deny the update.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );

        $unchanged = $this->get_test_client( $client_id );
        $this->assertSame( $original_name, $unchanged->name, 'The client name must not change when access is denied.' );
    }

    // =========================================================================
    // mainwp/get-client-costs-v1
    // =========================================================================

    /**
     * A restricted admin with no site overlap is denied client cost data.
     *
     * @return void
     */
    public function test_get_client_costs_denies_without_site_overlap() {
        $this->skip_if_no_abilities_api();

        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            $this->markTestSkipped( 'Cost Tracker module not available.' );
        }

        $this->set_current_user_as_admin();

        $client_id = $this->create_test_client( array( 'name' => 'Test Client Scoped Costs' ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );
        $other_site = $this->create_test_site();

        $this->restrict_current_user_to_sites( array( $other_site ) );

        $result = $this->execute_ability( 'mainwp/get-client-costs-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertWPError( $result, 'No overlap between the client sites and the allowed set must deny cost access.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
    }

    // =========================================================================
    // mainwp/update-client-v1 (reassignment must not detach unseen sites)
    // =========================================================================

    /**
     * Return the site IDs currently assigned to a client, unscoped.
     *
     * @param int $client_id Client ID.
     *
     * @return int[] Assigned site IDs.
     */
    private function assigned_site_ids( int $client_id ): array {
        $sites = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_websites_by_client_ids(
            $client_id,
            array( 'no_perm_check' => true )
        );

        return is_array( $sites ) ? array_map( static fn( $site ) => (int) $site->id, $sites ) : array();
    }

    /**
     * A restricted admin updating a client must not detach sites outside their
     * allowed set, even when selected_sites omits them. Any-overlap grants the
     * update, but the caller can only add or remove sites within their scope.
     *
     * @return void
     */
    public function test_update_client_preserves_unseen_sites_for_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $client_id    = $this->create_test_client( array( 'name' => 'Test Client Scoped Update Preserve' ) );
        $site_allowed = $this->create_test_site( array( 'client_id' => $client_id ) );
        $site_unseen1 = $this->create_test_site( array( 'client_id' => $client_id ) );
        $site_unseen2 = $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array( $site_allowed ) );

        $result = $this->execute_ability(
            'mainwp/update-client-v1',
            array(
                'client_id_or_email' => $client_id,
                'selected_sites'     => array( $site_allowed ),
            )
        );

        $this->assertNotWPError( $result, 'Any-overlap must still allow a restricted admin to update the client.' );

        $assigned = $this->assigned_site_ids( $client_id );
        $this->assertContains( $site_unseen1, $assigned, 'A site outside the caller scope must not be detached by a restricted update.' );
        $this->assertContains( $site_unseen2, $assigned, 'A site outside the caller scope must not be detached by a restricted update.' );
        $this->assertContains( $site_allowed, $assigned, 'The in-scope site must remain attached after the update.' );
    }
}
