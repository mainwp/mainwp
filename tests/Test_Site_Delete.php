<?php
/**
 * Site deletion tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Site_Actions;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Tests site deletion and cleanup of saved widget filters.
 */
class Test_Site_Delete extends \WP_UnitTestCase {

	use Site_Fixture;

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
}

