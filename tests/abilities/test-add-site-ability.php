<?php
/**
 * MainWP AddSite Ability Tests
 *
 * Tests for the mainwp/add-site-v1 ability.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

/**
 * Tests for mainwp/add-site-v1 ability.
 *
 * @group abilities
 * @group abilities-sites
 */
class Test_AddSite_Ability extends MainWP_Abilities_Test_Case {

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

        $ability = wp_get_ability( 'mainwp/add-site-v1' );
        $this->assertNotNull( $ability, 'Ability mainwp/add-site-v1 should be registered.' );
    }

    /**
     * Test successful execution with valid input.
     *
     * @return void
     */
    public function test_add_site_returns_expected_structure() {
        $this->skip_if_no_abilities_api();

        // Skip: add-site requires actual network connectivity to a MainWP Child site.
        // This test cannot be run in isolation without a real child site responding.
        $this->markTestSkipped( 'add-site ability requires actual MainWP Child site connectivity.' );
    }

    /**
     * Test that unauthenticated users are denied.
     *
     * @return void
     */
    public function test_add_site_requires_authentication() {
        $this->skip_if_no_abilities_api();

        wp_set_current_user( 0 );

		// Expect the "doing it wrong" notice from WP_Ability::execute.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => 'https://example.com',
            'name'           => 'Test Site',
            'admin_username' => 'admin',
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
    public function test_add_site_requires_manage_options() {
        $this->skip_if_no_abilities_api();

        $this->set_current_user_as_subscriber();

        $this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => 'https://example.com',
            'name'           => 'Test Site',
            'admin_username' => 'admin',
        ] );

        $this->assertWPError( $result, 'Subscriber should be denied.' );
        $this->assertEquals(
            'ability_invalid_permissions',
            $result->get_error_code(),
            'Should return ability_invalid_permissions error code.'
        );
    }

    /**
     * Test input validation rejects invalid values.
     *
     * @return void
     */
    public function test_add_site_validates_input() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url' => '', // Empty required field
        ] );

        $this->assertWPError( $result, 'Invalid input should return WP_Error.' );
    }

    /**
     * Test that URL normalization catches case-insensitive duplicates.
     *
     * URLs that differ only by case should be treated as duplicates.
     * Per RFC 4343, domain names are case-insensitive.
     *
     * @return void
     */
    public function test_add_site_rejects_case_variant_duplicate() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        // Create a site with lowercase URL.
        $site_id = $this->create_test_site( [
            'name' => 'Original Site',
            'url'  => 'https://test-case-duplicate.example.com/',
        ] );

        // Try to add a site with uppercase URL variation.
        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => 'https://TEST-CASE-DUPLICATE.EXAMPLE.COM/',
            'name'           => 'Duplicate Site',
            'admin_username' => 'admin',
        ] );

        $this->assertWPError( $result, 'Case-variant URL should be rejected as duplicate.' );
        $this->assertEquals(
            'mainwp_site_already_exists',
            $result->get_error_code(),
            'Should return mainwp_site_already_exists error code for case-variant duplicate.'
        );
    }

    /**
     * Test that URL normalization catches trailing slash variants.
     *
     * URLs that differ only by trailing slash should be treated as duplicates.
     *
     * @return void
     */
    public function test_add_site_rejects_trailing_slash_variant_duplicate() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        // Create a site with trailing slash.
        $site_id = $this->create_test_site( [
            'name' => 'Original Site',
            'url'  => 'https://test-slash-duplicate.example.com/',
        ] );

        // Try to add a site without trailing slash.
        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => 'https://test-slash-duplicate.example.com',
            'name'           => 'Duplicate Site',
            'admin_username' => 'admin',
        ] );

        $this->assertWPError( $result, 'Trailing slash variant URL should be rejected as duplicate.' );
        $this->assertEquals(
            'mainwp_site_already_exists',
            $result->get_error_code(),
            'Should return mainwp_site_already_exists error code for trailing slash variant.'
        );
    }

    /**
     * Scope the current user's site access via the allowed-sites filter.
     *
     * @param array $site_ids Allowed site IDs.
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
     * Assert no site row was created for the URL the ability was given.
     *
     * @param string $url Requested URL.
     * @return void
     */
    private function assertNoSiteWasCreated( string $url ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
        $existing = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_by_url( $url );
        $this->assertEmpty( $existing, 'No site row may be created when the requested tag or client is refused.' );
    }

    /**
     * A nonexistent tag id is rejected before the site is created.
     *
     * @return void
     */
    public function test_add_site_rejects_nonexistent_tag_ids() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $url = 'https://test-add-site-bad-tag.example.com/';

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => $url,
            'name'           => 'Bad Tag Site',
            'admin_username' => 'admin',
            'tag_ids'        => [ 999999 ],
        ] );

        $this->assertWPError( $result, 'A nonexistent tag must be rejected.' );
        $this->assertSame( 'mainwp_tag_not_found', $result->get_error_code() );
        $this->assertNoSiteWasCreated( $url );
    }

    /**
     * A tag outside the caller's group scope is rejected before the site is
     * created: permission to add a site is not permission to file it under
     * someone else's tag.
     *
     * @return void
     */
    public function test_add_site_rejects_out_of_scope_tag_ids() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $allowed_tag = $this->create_test_tag( [ 'name' => 'Add Site Allowed Tag' ] );
        $denied_tag  = $this->create_test_tag( [ 'name' => 'Add Site Denied Tag' ] );

        $this->restrict_current_user_to_groups( [ $allowed_tag ] );

        $url = 'https://test-add-site-scoped-tag.example.com/';

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => $url,
            'name'           => 'Scoped Tag Site',
            'admin_username' => 'admin',
            'tag_ids'        => [ $denied_tag ],
        ] );

        $this->assertWPError( $result, 'An out-of-scope tag must be rejected.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
        $this->assertNoSiteWasCreated( $url );
    }

    /**
     * A nonexistent client_id is rejected before the site is created.
     *
     * @return void
     */
    public function test_add_site_rejects_nonexistent_client() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $url = 'https://test-add-site-bad-client.example.com/';

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => $url,
            'name'           => 'Bad Client Site',
            'admin_username' => 'admin',
            'client_id'      => 999999,
        ] );

        $this->assertWPError( $result, 'A nonexistent client must be rejected.' );
        $this->assertSame( 'mainwp_client_not_found', $result->get_error_code() );
        $this->assertNoSiteWasCreated( $url );
    }

    /**
     * A client whose sites all sit outside the caller's site scope is rejected
     * before the site is created. Otherwise the attachment would manufacture
     * the very overlap check_client_access() grants access by.
     *
     * @return void
     */
    public function test_add_site_rejects_out_of_scope_client() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $denied_client = $this->create_test_client( [ 'name' => 'Add Site Denied Client' ] );
        $client_site   = $this->create_test_site( [ 'client_id' => $denied_client ] );
        $allowed_site  = $this->create_test_site();

        $this->restrict_current_user_to_sites( [ $allowed_site ] );

        $url = 'https://test-add-site-scoped-client.example.com/';

        $result = $this->execute_ability( 'mainwp/add-site-v1', [
            'url'            => $url,
            'name'           => 'Scoped Client Site',
            'admin_username' => 'admin',
            'client_id'      => $denied_client,
        ] );

        $this->assertWPError( $result, 'An out-of-scope client must be rejected.' );
        $this->assertSame( 'mainwp_access_denied', $result->get_error_code() );
        $this->assertNoSiteWasCreated( $url );
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
