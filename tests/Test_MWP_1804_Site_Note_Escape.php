<?php
/**
 * Site notes are stored and shown as typed, including backslashes (MWP-1804).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Notes;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.DB.DirectDatabaseQuery.DirectQuery

/**
 * Class Test_MWP_1804_Site_Note_Escape
 */
class Test_MWP_1804_Site_Note_Escape extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Sites created for this case.
     *
     * @var int[]
     */
    private $site_ids = array();

    /**
     * Note text with quotes and a Windows path.
     *
     * @return string
     */
    private function sample_note() {
        // Single-quoted so \b is a backslash, not a backspace.
        return 'Client said "don\'t" store this at C:\\backup\\site';
    }

    /**
     * Remove the site rows this case inserted.
     *
     * @return void
     */
    public function tearDown(): void {
        global $wpdb;

        foreach ( $this->site_ids as $site_id ) {
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
        }

        MainWP_System_Utility::set_current_wpid( 0 );
        parent::tearDown();
    }

    /**
     * A note with quotes and a backslash is stored exactly as passed in.
     *
     * @return void
     */
    public function test_update_note_stores_quotes_and_backslash_without_added_slashes(): void {
        $note    = $this->sample_note();
        $site_id = $this->create_note_site();

        MainWP_DB_Common::instance()->update_note( $site_id, $note );

        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * Save, render, and save again leave a typed note unchanged.
     *
     * The hidden span is what the edit modal copies into the textarea.
     *
     * @return void
     */
    public function test_new_note_round_trips_through_save_display_and_resave(): void {
        $note    = $this->sample_note();
        $site_id = $this->create_note_site();

        $this->save_posted_note( $site_id, $note );
        $this->assertSame( $note, $this->stored_note( $site_id ) );

        $html    = $this->render_notes_widget( $site_id );
        $visible = $this->visible_note( $html );
        $editor  = $this->editor_note( $html, $site_id );

        $this->assertSame( $note, $visible );
        $this->assertSame( $note, $editor );

        $this->save_posted_note( $site_id, $editor );

        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * An already-escaped note is stripslashed once, then left alone.
     *
     * The value stored by the old write is what esc_sql() returns. The screen
     * used to wp_unslash() that. After the upgrade the column itself is that
     * text, and rendering it without wp_unslash shows the same string.
     *
     * @return void
     */
    public function test_legacy_escaped_note_is_corrected_once_and_displays_as_before(): void {
        $typed  = $this->sample_note();
        $legacy = MainWP_DB_Common::instance()->escape( $typed );
        $this->assertNotSame( $typed, $legacy );
        $this->assertSame( $typed, stripslashes( $legacy ) );

        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        $plain_id = $this->create_note_site( 'MWP-1804 Plain Note' );
        $this->store_raw_note( $plain_id, 'No slashes here' );

        $shown_before = wp_unslash( MainWP_Utility::esc_content( html_entity_decode( $legacy ) ) );

        $this->prepare_legacy_upgrade();
        MainWP_Install::instance()->install();

        $this->assertSame( $shown_before, $this->stored_note( $site_id ) );
        $this->assertSame( $typed, $this->stored_note( $site_id ) );
        $this->assertSame( 'No slashes here', $this->stored_note( $plain_id ) );
        $this->assertSame( '9.0.2.6', get_site_option( 'mainwp_db_version' ) );
        $this->assertNotEmpty( get_site_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        $html = $this->render_notes_widget( $site_id );
        $this->assertSame( $shown_before, $this->visible_note( $html ) );
        $this->assertSame( $shown_before, $this->editor_note( $html, $site_id ) );

        MainWP_Install::instance()->install();
        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $typed, $this->stored_note( $site_id ) );
        $this->assertSame( 'No slashes here', $this->stored_note( $plain_id ) );
    }

    /**
     * Create a site the notes widget can load.
     *
     * @param string $name Site name.
     *
     * @return int
     */
    private function create_note_site( $name = 'MWP-1804 Note Site' ) {
        $site_id          = $this->create_test_site(
            array(
                'name' => $name,
                'url'  => 'https://mwp-1804-' . wp_generate_uuid4() . '.example.com/',
            )
        );
        $this->site_ids[] = $site_id;
        $this->assertGreaterThan( 0, $site_id );

        return $site_id;
    }

    /**
     * Mirror save_note(): WordPress slashes $_POST, the handler unslashes, then stores.
     *
     * @param int    $site_id Site ID.
     * @param string $note    Note as the editor would submit it.
     *
     * @return void
     */
    private function save_posted_note( $site_id, $note ) {
        $posted   = wp_unslash( wp_slash( $note ) );
        $esc_note = MainWP_Utility::esc_content( $posted );
        MainWP_DB_Common::instance()->update_note( $site_id, $esc_note );
    }

    /**
     * Write a column value without a second escape, including a legacy escaped note.
     *
     * @param int    $site_id Site ID.
     * @param string $note    Exact column value.
     *
     * @return void
     */
    private function store_raw_note( $site_id, $note ) {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'mainwp_wp',
            array( 'note' => $note ),
            array( 'id' => $site_id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->assertNotFalse( $updated );
    }

    /**
     * Read the note column.
     *
     * @param int $site_id Site ID.
     *
     * @return string|null
     */
    private function stored_note( $site_id ) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT note FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $site_id
            )
        );
    }

    /**
     * Pretend this database has not yet run the 9.0.2.6 note repair.
     *
     * @return void
     */
    private function prepare_legacy_upgrade() {
        update_site_option( 'mainwp_db_version', '9.0.2.5' );
        delete_site_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION );
    }

    /**
     * Render the individual-site notes widget.
     *
     * @param int $site_id Site ID.
     *
     * @return string
     */
    private function render_notes_widget( $site_id ) {
        MainWP_System_Utility::set_current_wpid( (string) $site_id );
        ob_start();
        MainWP_Notes::render();
        return ob_get_clean();
    }

    /**
     * Visible note inside the widget.
     *
     * @param string $html Widget markup.
     *
     * @return string
     */
    private function visible_note( $html ) {
        $this->assertSame( 1, preg_match( '/<div class="content">\s*(.*?)\s*<\/div>/s', $html, $matches ) );
        return $matches[1];
    }

    /**
     * Note copied into the edit modal from the hidden span.
     *
     * @param string $html    Widget markup.
     * @param int    $site_id Site ID.
     *
     * @return string
     */
    private function editor_note( $html, $site_id ) {
        $id = 'mainwp-notes-' . (int) $site_id . '-note';
        $this->assertSame( 1, preg_match( '/id="' . preg_quote( $id, '/' ) . '">([^<]*)<\/span>/', $html, $matches ) );
        return $matches[1];
    }
}
