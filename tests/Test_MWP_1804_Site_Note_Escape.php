<?php
/**
 * Site notes must be stored once, without prepare() seeing an already-escaped value (MWP-1804).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.DB.DirectDatabaseQuery.DirectQuery

/**
 * Class Test_MWP_1804_Site_Note_Escape
 */
class Test_MWP_1804_Site_Note_Escape extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Site created for this case.
     *
     * @var int
     */
    private $site_id = 0;

    /**
     * Remove the site row this case inserted.
     *
     * @return void
     */
    public function tearDown(): void {
        global $wpdb;

        if ( $this->site_id ) {
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $this->site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $this->site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $this->site_id ), array( '%d' ) );
        }

        parent::tearDown();
    }

    /**
     * A note with quotes and a backslash is stored exactly as passed in.
     *
     * Reads the column with a direct SELECT. The notes widget's wp_unslash()
     * would hide a stored \' and must not be part of this assertion.
     *
     * @return void
     */
    public function test_update_note_stores_quotes_and_backslash_without_added_slashes(): void {
        global $wpdb;

        $this->site_id = $this->create_test_site(
            array(
                'name' => 'MWP-1804 Note Site',
                'url'  => 'https://mwp-1804-note.example.com/',
            )
        );
        $this->assertGreaterThan( 0, $this->site_id );

        // Single-quoted so \b is a backslash, not a backspace.
        $note = 'Client said "don\'t" store this at C:\\backup\\site';

        MainWP_DB_Common::instance()->update_note( $this->site_id, $note );

        $stored = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT note FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $this->site_id
            )
        );

        $this->assertSame( $note, $stored );
    }
}
