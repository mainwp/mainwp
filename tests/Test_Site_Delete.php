<?php
/**
 * Site deletion tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Encrypt_Data_Lib;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_Site_Actions;
use MainWP\Dashboard\MainWP_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests site deletion and cleanup of saved widget filters.
 */
class Test_Site_Delete extends \WP_UnitTestCase {

	use Site_Fixture;

	/**
	 * Site-row DELETE statements rewritten by the query filters.
	 *
	 * @var int
	 */
	private $site_row_deletes_rewritten = 0;

	/**
	 * Clean up test fixtures.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Test that saved widget filters are removed for all users when a site
	 * or client is deleted.
	 *
	 * @return void
	 */
    public function test_delete_saved_widget_filters_when_site_and_client_are_deleted() {
        $widget    = MainWP_Site_Actions::instance();
        $client_db = MainWP_DB_Client::instance();
        $db        = MainWP_DB::instance();

        // Create two users to verify that filters are removed for all users.
        $user_1 = self::factory()->user->create();
        $user_2 = self::factory()->user->create();

        // Create the client.
        $client = $client_db->update_client(
            array(
                'name'    => 'Test Client',
                'created' => time(),
            ),
            true
        );

        $this->assertNotFalse( $client );
        $this->assertNotEmpty( $client->client_id );

        $client_id = (int) $client->client_id;

        // Create a site assigned to the client.
        $site_id = $this->create_test_site(
            array(
                'name'      => 'Test Site',
                'url'       => 'https://test-site-delete.example.com/',
                'client_id' => $client_id,
            )
        );

        // Create another client to verify that deletion is scoped correctly.
        $other_client = $client_db->update_client(
            array(
                'name'    => 'Other Test Client',
                'created' => time(),
            ),
            true
        );

        $this->assertNotFalse( $other_client );
        $this->assertNotEmpty( $other_client->client_id );

        $other_client_id = (int) $other_client->client_id;

        // Create another site assigned to the other client.
        $other_site_id = $this->create_test_site(
            array(
                'name'      => 'Other Test Site',
                'url'       => 'https://other-test-site-delete.example.com/',
                'client_id' => $other_client_id,
            )
        );

        /*
        * Save filters for the first user.
        */
        wp_set_current_user( $user_1 );

        $widget->get_or_set_widget_saved_filters(
            'individual',
            array(
                'sites'  => array( $site_id ),
                'events' => array( 'sync' ),
            ),
            $site_id
        );

        $widget->get_or_set_widget_saved_filters(
            'client',
            array(
                'sites'  => array( $site_id ),
                'events' => array( 'sync' ),
            ),
            0,
            $client_id
        );

        $widget->get_or_set_widget_saved_filters(
            'individual',
            array(
                'sites'  => array( $other_site_id ),
                'events' => array( 'sync' ),
            ),
            $other_site_id
        );

        $widget->get_or_set_widget_saved_filters(
            'client',
            array(
                'sites'  => array( $other_site_id ),
                'events' => array( 'sync' ),
            ),
            0,
            $other_client_id
        );

        /*
        * Save filters for the second user.
        */
        wp_set_current_user( $user_2 );

        $widget->get_or_set_widget_saved_filters(
            'individual',
            array(
                'sites'  => array( $site_id ),
                'events' => array( 'backup' ),
            ),
            $site_id
        );

        $widget->get_or_set_widget_saved_filters(
            'client',
            array(
                'sites'  => array( $site_id ),
                'events' => array( 'backup' ),
            ),
            0,
            $client_id
        );

        $widget->get_or_set_widget_saved_filters(
            'individual',
            array(
                'sites'  => array( $other_site_id ),
                'events' => array( 'backup' ),
            ),
            $other_site_id
        );

        $widget->get_or_set_widget_saved_filters(
            'client',
            array(
                'sites'  => array( $other_site_id ),
                'events' => array( 'backup' ),
            ),
            0,
            $other_client_id
        );

        /*
        * Verify all filters exist before deletion.
        */
        wp_set_current_user( $user_1 );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $site_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $other_site_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        wp_set_current_user( $user_2 );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $site_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $other_site_id
            )
        );

        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        /*
        * Delete the site.
        *
        * This should remove the individual filters for all users,
        * while leaving client filters and other site filters untouched.
        */
        $result = $db->remove_website( $site_id );

        $this->assertGreaterThan( 0, $result );

        wp_set_current_user( $user_1 );

        // The deleted site's individual filters must be removed.
        $this->assertEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $site_id
            )
        );

        // The deleted site's client filters must still exist.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        // The other site's individual filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $other_site_id
            )
        );

        // The other client's filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        wp_set_current_user( $user_2 );

        // The deleted site's individual filters must be removed.
        $this->assertEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $site_id
            )
        );

        // The deleted site's client filters must still exist.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        // The other site's individual filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'individual',
                null,
                $other_site_id
            )
        );

        // The other client's filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        /*
        * Delete the client.
        *
        * This should remove the client filters for all users,
        * while leaving the other client's filters untouched.
        */
        $result = $client_db->delete_client( $client_id );

        $this->assertNotFalse( $result );

        wp_set_current_user( $user_1 );

        // The deleted client's filters must be removed.
        $this->assertEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        // The other client's filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        wp_set_current_user( $user_2 );

        // The deleted client's filters must be removed.
        $this->assertEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $client_id
            )
        );

        // The other client's filters must remain.
        $this->assertNotEmpty(
            $widget->get_or_set_widget_saved_filters(
                'client',
                null,
                0,
                $other_client_id
            )
        );

        // Clean up the remaining test data.
        $db->remove_website( $other_site_id );
        $client_db->delete_client( $other_client_id );
    }

    /**
     * A failed site-row delete leaves every related row and file in place.
     *
     * The query filter blanks only DELETE FROM `{prefix}mainwp_wp`. Without the
     * early return, that failure still removes the sync row and the other
     * related rows and files.
     *
     * @return void
     */
    public function test_failed_site_row_delete_leaves_related_rows_and_files() {
        $this->site_row_deletes_rewritten = 0;
        $seed                             = null;

        try {
            $seed = $this->seed_site_delete_fixtures();
            $this->assert_site_delete_fixtures( $seed );

            add_filter( 'query', array( $this, 'fail_site_row_delete' ) );
            $result = MainWP_DB::instance()->remove_website( $seed['site_id'] );

            $this->assertSame( false, $result );
            $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The failure filter blanks exactly one site-row DELETE.' );
            $this->assertSame( 1, $this->count_rows( 'mainwp_wp', 'id', $seed['site_id'] ) );
            $this->assertSame( 1, $this->count_rows( 'mainwp_wp_sync', 'wpid', $seed['site_id'] ) );
            $this->assertSame( 1, $this->count_rows( 'mainwp_wp_options', 'wpid', $seed['site_id'] ) );
            $this->assertSame( 1, $this->count_rows( 'mainwp_wp_group', 'wpid', $seed['site_id'] ) );
            $this->assertSame( 1, $this->count_rows( 'mainwp_monitors', 'wpid', $seed['site_id'] ) );
            $this->assertSame( 1, $this->count_knowledge_rows( $seed['site_id'] ) );
            $this->assertFileExists( $seed['opaque_key'] );
            $this->assertFileExists( $seed['legacy_key'] );
            $this->assertNotEmpty(
                MainWP_Site_Actions::instance()->get_or_set_widget_saved_filters( 'individual', null, $seed['site_id'] )
            );
        } finally {
            remove_filter( 'query', array( $this, 'fail_site_row_delete' ) );
            if ( is_array( $seed ) ) {
                $this->cleanup_site_delete_fixtures( $seed );
            }
        }
    }

    /**
     * A site-row delete that matches nothing still returns 0 and removes the sync row.
     *
     * Zero rows is not a database error. The filter appends AND 1=0 to that one
     * DELETE, so the statement runs and matches no site row.
     *
     * @return void
     */
    public function test_zero_row_site_delete_still_removes_the_sync_row() {
        $this->site_row_deletes_rewritten = 0;
        $seed                             = null;

        try {
            $seed = $this->seed_site_delete_fixtures();
            $this->assert_site_delete_fixtures( $seed );

            add_filter( 'query', array( $this, 'zero_site_row_delete' ) );
            $result = MainWP_DB::instance()->remove_website( $seed['site_id'] );

            $this->assertSame( 0, $result );
            $this->assertSame( 1, $this->site_row_deletes_rewritten, 'The zero-row filter rewrites exactly one site-row DELETE.' );
            $this->assertSame( 1, $this->count_rows( 'mainwp_wp', 'id', $seed['site_id'] ) );
            $this->assertSame( 0, $this->count_rows( 'mainwp_wp_sync', 'wpid', $seed['site_id'] ) );
        } finally {
            remove_filter( 'query', array( $this, 'zero_site_row_delete' ) );
            if ( is_array( $seed ) ) {
                $this->cleanup_site_delete_fixtures( $seed );
            }
        }
    }

    /**
     * Rewrite the child-site DELETE into SQL that $wpdb->delete() rejects.
     *
     * An empty query returns false before MySQL runs. The closing backtick keeps
     * mainwp_wp_sync, mainwp_wp_options and mainwp_wp_group from matching.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_site_row_delete( $query ) {
        global $wpdb;

        $site_table = '`' . $wpdb->prefix . 'mainwp_wp`';
        if ( is_string( $query ) && false !== stripos( $query, 'DELETE FROM ' . $site_table ) ) {
            ++$this->site_row_deletes_rewritten;
            return '';
        }

        return $query;
    }

    /**
     * Make the child-site DELETE match no row.
     *
     * $wpdb->delete() then returns 0. Other deletes are left alone.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function zero_site_row_delete( $query ) {
        global $wpdb;

        $site_table = '`' . $wpdb->prefix . 'mainwp_wp`';
        if ( is_string( $query ) && false !== stripos( $query, 'DELETE FROM ' . $site_table ) ) {
            ++$this->site_row_deletes_rewritten;
            return $query . ' AND 1=0';
        }

        return $query;
    }

    /**
     * Seed the rows and files remove_website() would otherwise delete.
     *
     * @return array Seeded ids and key paths.
     */
    private function seed_site_delete_fixtures() {
        global $wpdb;

        $user_id = self::factory()->user->create();
        wp_set_current_user( $user_id );

        $site_id = $this->create_test_site(
            array(
                'name' => 'Failed Delete Site',
                'url'  => 'https://test-failed-site-delete-' . wp_generate_uuid4() . '.example.com/',
            )
        );

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_wp_group',
            array(
                'wpid'    => $site_id,
                'groupid' => 1,
            ),
            array( '%d', '%d' )
        );

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_monitors',
            array(
                'wpid'            => $site_id,
                'active'          => -1,
                'type'            => 'useglobal',
                'issub'           => 0,
                'timeout'         => -1,
                'interval'        => -1,
                'lasttime_check'  => time(),
                'method'          => 'get',
                'up_status_codes' => 'useglobal',
            )
        );

        $wpdb->insert(
            $wpdb->prefix . 'mainwp_knowledge',
            array(
                'scope_type' => 'site',
                'scope_id'   => $site_id,
                'type'       => 'context',
                'title'      => 'Site delete guard',
                'body'       => 'Keep this row when the site delete fails.',
                'source'     => 'ui',
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        $key_dir = MainWP_Keys_Manager::get_keys_dir();
        if ( ! is_dir( $key_dir ) ) {
            wp_mkdir_p( $key_dir );
        }
        $opaque = $key_dir . MainWP_Encrypt_Data_Lib::get_key_file( $site_id );
        $legacy = $key_dir . MainWP_Encrypt_Data_Lib::legacy_key_filename( $site_id );
        file_put_contents( $opaque, 'opaque-key' );
        file_put_contents( $legacy, 'legacy-key' );

        MainWP_Site_Actions::instance()->get_or_set_widget_saved_filters(
            'individual',
            array(
                'sites'  => array( $site_id ),
                'events' => array( 'sync' ),
            ),
            $site_id
        );

        return array(
            'user_id'    => $user_id,
            'site_id'    => $site_id,
            'opaque_key' => $opaque,
            'legacy_key' => $legacy,
        );
    }

    /**
     * Assert the fixture rows and files exist before the delete under test.
     *
     * @param array $seed Values from seed_site_delete_fixtures().
     *
     * @return void
     */
    private function assert_site_delete_fixtures( $seed ) {
        $this->assertGreaterThan( 0, $seed['site_id'] );
        $this->assertSame( 1, $this->count_rows( 'mainwp_wp', 'id', $seed['site_id'] ) );
        $this->assertSame( 1, $this->count_rows( 'mainwp_wp_sync', 'wpid', $seed['site_id'] ) );
        $this->assertSame( 1, $this->count_rows( 'mainwp_wp_options', 'wpid', $seed['site_id'] ) );
        $this->assertSame( 1, $this->count_rows( 'mainwp_wp_group', 'wpid', $seed['site_id'] ) );
        $this->assertSame( 1, $this->count_rows( 'mainwp_monitors', 'wpid', $seed['site_id'] ) );
        $this->assertSame( 1, $this->count_knowledge_rows( $seed['site_id'] ) );
        $this->assertFileExists( $seed['opaque_key'] );
        $this->assertFileExists( $seed['legacy_key'] );
        $this->assertNotEmpty(
            MainWP_Site_Actions::instance()->get_or_set_widget_saved_filters( 'individual', null, $seed['site_id'] )
        );
    }

    /**
     * Remove the filter's fixtures, including rows the delete under test left behind.
     *
     * @param array $seed Values from seed_site_delete_fixtures().
     *
     * @return void
     */
    private function cleanup_site_delete_fixtures( $seed ) {
        global $wpdb;

        if ( ! empty( $seed['user_id'] ) ) {
            wp_set_current_user( $seed['user_id'] );
            $option = 'mainwp_widget_actions_logs_individual_filters_saved_' . intval( $seed['site_id'] );
            MainWP_Utility::delete_user_option( $option );
        }

        foreach ( array( 'opaque_key', 'legacy_key' ) as $path_key ) {
            if ( ! empty( $seed[ $path_key ] ) && file_exists( $seed[ $path_key ] ) ) {
                wp_delete_file( $seed[ $path_key ] );
            }
        }

        $site_id = isset( $seed['site_id'] ) ? (int) $seed['site_id'] : 0;
        if ( $site_id < 1 ) {
            return;
        }

        $wpdb->delete( $wpdb->prefix . 'mainwp_knowledge', array( 'scope_type' => 'site', 'scope_id' => $site_id ), array( '%s', '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'mainwp_monitors', array( 'wpid' => $site_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp_group', array( 'wpid' => $site_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
    }

    /**
     * Count rows in one MainWP table for this site.
     *
     * @param string $suffix Table name after the WordPress prefix.
     * @param string $column id or wpid.
     * @param int    $id     Site id.
     *
     * @return int
     */
    private function count_rows( $suffix, $column, $id ) {
        global $wpdb;

        switch ( $suffix . '.' . $column ) {
            case 'mainwp_wp.id':
                $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp WHERE id = %d";
                break;
            case 'mainwp_wp_sync.wpid':
                $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid = %d";
                break;
            case 'mainwp_wp_options.wpid':
                $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d";
                break;
            case 'mainwp_wp_group.wpid':
                $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_group WHERE wpid = %d";
                break;
            case 'mainwp_monitors.wpid':
                $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_monitors WHERE wpid = %d";
                break;
            default:
                $this->fail( 'Unexpected site-delete count.' );
                return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each query above is a fixed literal.
    }

    /**
     * Count knowledge rows stored for this site.
     *
     * @param int $site_id Site id.
     *
     * @return int
     */
    private function count_knowledge_rows( $site_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_knowledge WHERE scope_type = %s AND scope_id = %d",
                'site',
                $site_id
            )
        );
    }
}

