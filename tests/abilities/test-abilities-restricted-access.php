<?php
/**
 * Tests for unrestricted access checks and scoped client and tag abilities.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

/**
 * Class Test_Abilities_Restricted_Access
 */
class Test_Abilities_Restricted_Access extends MainWP_Abilities_Test_Case {

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
     * @param array $site_ids Allowed site IDs. An empty array scopes the user
     *                         to nothing; any non-'all' return denies the gate.
     *
     * @return void
     */
    private function restrict_current_user_to_sites( array $site_ids = array( 1 ) ): void {
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
     * Create and switch to an admin whose site access is scoped (restricted).
     *
     * @param array $site_ids Allowed site IDs for the restriction filter.
     *
     * @return int User ID.
     */
    private function set_current_user_as_restricted_admin( array $site_ids = array( 1 ) ): int {
        $user_id = $this->set_current_user_as_admin();
        $this->restrict_current_user_to_sites( $site_ids );
        return $user_id;
    }

    // =========================================================================
    // check_unrestricted_access() TRUTH TABLE
    // =========================================================================

    /**
     * The helper must exist as a public static method, so its accidental
     * removal surfaces as a failure rather than a fatal across this file.
     *
     * @return void
     */
    public function test_check_unrestricted_access_exists() {
        $this->assertTrue(
            method_exists( MainWP_Abilities_Util::class, 'check_unrestricted_access' ),
            'MainWP_Abilities_Util::check_unrestricted_access() must exist as a public static method.'
        );
    }

    /**
     * An unrestricted user (filter returns 'all') passes.
     *
     * @return void
     */
    public function test_check_unrestricted_access_true_for_unrestricted_user() {
        $this->set_current_user_as_admin();

        $this->assertTrue(
            MainWP_Abilities_Util::check_unrestricted_access(),
            'An unscoped allowed-sites filter must pass check_unrestricted_access().'
        );
    }

    /**
     * A user scoped to specific sites is denied with mainwp_restricted_account.
     *
     * @return void
     */
    public function test_check_unrestricted_access_denies_scoped_sites() {
        $this->set_current_user_as_restricted_admin( array( 1, 2 ) );

        $result = MainWP_Abilities_Util::check_unrestricted_access();

        $this->assertWPError( $result, 'A scoped allowed-sites filter must deny check_unrestricted_access().' );
        $this->assertSame( 'mainwp_restricted_account', $result->get_error_code() );
    }

    /**
     * A user scoped to an empty site list is denied with mainwp_restricted_account.
     *
     * @return void
     */
    public function test_check_unrestricted_access_denies_empty_site_list() {
        $this->set_current_user_as_restricted_admin( array() );

        $result = MainWP_Abilities_Util::check_unrestricted_access();

        $this->assertWPError( $result, 'An empty allowed-sites filter must deny check_unrestricted_access().' );
        $this->assertSame( 'mainwp_restricted_account', $result->get_error_code() );
    }

    // =========================================================================
    // CLIENTS ABILITY
    // =========================================================================

    /**
     * A restricted admin gets a scoped client list instead of a denial.
     *
     * @return void
     */
    public function test_list_clients_scopes_results_for_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $client_id = $this->create_test_client( array( 'name' => 'Out Of Scope Client Gate' ) );
        $this->create_test_site( array( 'client_id' => $client_id ) );

        $this->restrict_current_user_to_sites( array() );

        $result = $this->execute_ability( 'mainwp/list-clients-v1', array() );

        $this->assertNotWPError( $result, 'A restricted admin must receive a scoped list, not a blanket denial.' );
        $this->assertIsArray( $result );
        $ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );
        $this->assertNotContains( $client_id, $ids, 'Clients with no accessible site must be filtered out.' );
    }

    /**
     * An unrestricted admin still succeeds on mainwp/list-clients-v1.
     *
     * Happy-path control proving the restricted-user assertion above is
     * meaningful and not a fixture/setup problem.
     *
     * @return void
     */
    public function test_list_clients_succeeds_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/list-clients-v1', array() );

        $this->assertNotWPError( $result, 'An unrestricted admin must still be able to list clients.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'items', $result );

        $client_id = $this->create_test_client();
        $this->restrict_current_user_to_sites( array() );
        $scoped = $this->execute_ability( 'mainwp/list-clients-v1', array() );
        $this->assertNotWPError( $scoped );
        $this->assertNotContains( $client_id, array_column( $scoped['items'], 'id' ) );
    }

    /**
     * A restricted admin is denied mainwp/get-client-v1 for a client with no
     * accessible site.
     *
     * @return void
     */
    public function test_get_client_denies_restricted_admin_without_overlap() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $client_id = $this->create_test_client( array( 'name' => 'Test Client Restricted Gate' ) );

        $this->restrict_current_user_to_sites( array() );

        $result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertWPError( $result, 'A restricted admin without site overlap must be denied mainwp/get-client-v1.' );
        $this->assertSame( 'mainwp_client_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    /**
     * An unrestricted admin still succeeds on mainwp/get-client-v1.
     *
     * Happy-path control.
     *
     * @return void
     */
    public function test_get_client_succeeds_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $client_id = $this->create_test_client( array( 'name' => 'Test Client Unrestricted Gate' ) );

        $result = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );

        $this->assertNotWPError( $result, 'An unrestricted admin must still be able to fetch a client.' );
        $this->assertEquals( $client_id, $result['id'] );

        $this->restrict_current_user_to_sites( array() );
        $denied = $this->execute_ability( 'mainwp/get-client-v1', array( 'client_id_or_email' => $client_id ) );
        $this->assertWPError( $denied );
        $this->assertSame( 'mainwp_client_not_found', $denied->get_error_code() );
        $this->assertSame( 404, $denied->get_error_data()['status'] );
    }

    // =========================================================================
    // TAGS ABILITY
    // =========================================================================

    /**
     * A restricted admin gets a scoped tag list instead of a denial.
     *
     * @return void
     */
    public function test_list_tags_scopes_results_for_restricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();
        $tag_id = $this->create_test_tag();

        $this->set_current_user_as_restricted_admin( array( 1 ) );
        $this->scope_filters['mainwp_currentuserallowedaccessgroups'] = static function () {
            return array();
        };
        add_filter( 'mainwp_currentuserallowedaccessgroups', $this->scope_filters['mainwp_currentuserallowedaccessgroups'] );

        $result = $this->execute_ability( 'mainwp/list-tags-v1', array() );

        $this->assertNotWPError( $result, 'A restricted admin must receive a scoped tag list, not a blanket denial.' );
        $this->assertIsArray( $result );
        $ids = array_map( static fn( $item ) => (int) $item['id'], $result['items'] );
        $this->assertNotContains( $tag_id, $ids, 'Tags outside the allowed groups must be filtered out.' );
    }

    /**
     * An unrestricted admin still succeeds on mainwp/list-tags-v1.
     *
     * Happy-path control.
     *
     * @return void
     */
    public function test_list_tags_succeeds_for_unrestricted_admin() {
        $this->skip_if_no_abilities_api();
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/list-tags-v1', array() );

        $this->assertNotWPError( $result, 'An unrestricted admin must still be able to list tags.' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'items', $result );

        $tag_id = $this->create_test_tag();
        $this->scope_filters['mainwp_currentuserallowedaccessgroups'] = static function () {
            return array();
        };
        add_filter( 'mainwp_currentuserallowedaccessgroups', $this->scope_filters['mainwp_currentuserallowedaccessgroups'] );
        $scoped = $this->execute_ability( 'mainwp/list-tags-v1', array() );
        $this->assertNotWPError( $scoped );
        $this->assertNotContains( $tag_id, array_column( $scoped['items'], 'id' ) );
    }

    /**
     * Equal administrator privileges must not grant access to another owner's job.
     *
     * @return void
     */
    public function test_get_batch_job_status_denies_unrestricted_non_owner() {
        $this->skip_if_no_abilities_api();

        $owner_id = $this->set_current_user_as_admin();
        $job_id   = 'sync_gate_owner_' . wp_generate_uuid4();
        $this->track_sync_job( $job_id );

        set_transient(
            'mainwp_sync_job_' . $job_id,
            array(
                'job_type'  => 'sync',
                'status'    => 'queued',
                'progress'  => 0,
                'processed' => 0,
                'total'     => 1,
                'synced'    => array(),
                'errors'    => array(),
                'created'   => time(),
                'started'   => null,
                'completed' => null,
                'user_id'   => $owner_id,
            ),
            DAY_IN_SECONDS
        );

        // Switch to a second, equally privileged admin who did not queue the job.
        $this->set_current_user_as_admin();

        $result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => $job_id ) );

        $this->assertWPError( $result, 'A non-owner admin must be denied another user\'s batch job status.' );
        $this->assertSame(
            'mainwp_permission_denied',
            $result->get_error_code(),
            'An unrestricted non-owner receives an ownership error.'
        );

        $this->assertSame( 403, $result->get_error_data()['status'] );

        // The owner must still be able to read their own job (happy-path control).
        wp_set_current_user( $owner_id );
        $owner_result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => $job_id ) );

        $this->assertNotWPError( $owner_result, 'The job owner must still be able to read their own batch job status.' );
        $this->assertSame( $job_id, $owner_result['job_id'] );
    }

    /**
     * Foreign and missing jobs must be indistinguishable to restricted callers.
     *
     * @return void
     */
    public function test_get_batch_job_status_hides_job_from_restricted_non_owner() {
        $this->skip_if_no_abilities_api();
        $owner_id = $this->set_current_user_as_admin();
        $job_id   = 'sync_gate_owner_' . wp_generate_uuid4();
        $this->track_sync_job( $job_id );
        set_transient(
            'mainwp_sync_job_' . $job_id,
            array( 'job_type' => 'sync', 'status' => 'queued', 'user_id' => $owner_id ),
            DAY_IN_SECONDS
        );

        $this->set_current_user_as_restricted_admin( array() );
        $result = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => $job_id ) );
        $missing = $this->execute_ability( 'mainwp/get-batch-job-status-v1', array( 'job_id' => 'sync_missing_' . wp_generate_uuid4() ) );

        $this->assertWPError( $result );
        $this->assertWPError( $missing );
        $this->assertSame( 'mainwp_job_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
        $this->assertEquals( $missing, $result );
    }
}
