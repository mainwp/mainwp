<?php
/**
 * Site notes are stored and shown as typed, including backslashes (MWP-1804).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Manage_Groups;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler;
use MainWP\Dashboard\MainWP_Manage_Sites_List_Table;
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
     * Repair SELECTs seen by the partial-batch filter.
     *
     * @var int
     */
    private $repair_selects = 0;

    /**
     * Repair note updates seen by the mid-batch filter.
     *
     * @var int
     */
    private $repair_writes = 0;

    /**
     * Site whose note write must follow its skip row.
     *
     * @var int
     */
    private $order_site_id = 0;

    /**
     * Whether the note UPDATE was seen after the skip row.
     *
     * @var bool
     */
    private $saw_note_write = false;

    /**
     * Site saved from inside the repair select.
     *
     * @var int
     */
    private $during_id = 0;

    /**
     * Guards the repair-select filter from its own note write.
     *
     * @var bool
     */
    private $inside_save = false;

    /**
     * Reset the repair markers before each case.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->repair_selects = 0;
        $this->repair_writes  = 0;
        $this->order_site_id  = 0;
        $this->saw_note_write = false;
        $this->during_id      = 0;
        $this->inside_save    = false;
        $this->clear_repair_options();
    }

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

        remove_filter( 'query', array( $this, 'fail_note_reads' ) );
        remove_filter( 'query', array( $this, 'fail_second_note_read' ) );
        remove_filter( 'query', array( $this, 'fail_note_writes' ) );
        remove_filter( 'query', array( $this, 'fail_second_note_write' ) );
        remove_filter( 'query', array( $this, 'assert_no_skip_before_note_write' ) );
        remove_filter( 'query', array( $this, 'save_note_during_repair_select' ) );
        remove_filter( 'query', array( $this, 'fail_checkpoint_after_note_write' ) );
        remove_filter( 'query', array( $this, 'complete_repair_before_skip_readback' ) );
        remove_filter( 'query', array( $this, 'steal_lock_on_second_repair_select' ) );
        remove_filter( 'query', array( $this, 'takeover_during_first_note_update' ) );
        remove_filter( 'query', array( $this, 'crash_takeover_before_site_three_checkpoint' ) );
        remove_filter( 'wp_doing_ajax', '__return_true' );
        remove_filter( 'wp_doing_cron', '__return_true' );
        remove_filter( 'mainwp_site_note_slash_repair_time_budget', array( $this, 'repair_budget_of_zero' ) );
        remove_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_one' ) );
        remove_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_ten' ) );
        remove_filter( 'mainwp_sitestable_getcolumns', array( $this, 'notes_column_only' ) );
        remove_filter( 'mainwp_sitestable_display_row_columns', array( $this, 'skip_site_actions' ) );
        remove_all_actions( 'mainwp_site_note_slash_repair_before_update' );

        foreach ( $this->site_ids as $site_id ) {
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
        }

        $this->clear_repair_options();
        MainWP_System_Utility::set_current_wpid( 0 );
        wp_set_current_user( 0 );
        parent::tearDown();
        wp_cache_flush();
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
     * An already-escaped note is left byte for byte, and the screen shows that stored text.
     *
     * @return void
     */
    public function test_legacy_escaped_note_is_left_as_stored(): void {
        $typed   = $this->sample_note();
        $legacy  = MainWP_DB_Common::instance()->escape( $typed );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        $plain_id = $this->create_note_site( 'MWP-1804 Plain Note' );
        $this->store_raw_note( $plain_id, 'No slashes here' );

        $this->assertNotFalse( has_action( 'admin_init', array( MainWP_Install::instance(), 'maybe_repair_site_note_slashes' ) ) );

        // admin-ajax.php runs admin_init with no user. That request must not rewrite notes.
        MainWP_Install::instance()->maybe_repair_site_note_slashes();
        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION, false ) );

        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        MainWP_Install::instance()->maybe_repair_site_note_slashes();

        $shown = MainWP_Utility::esc_content( html_entity_decode( $legacy ) );
        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( $typed, $this->stored_note( $site_id ) );
        $this->assertSame( 'No slashes here', $this->stored_note( $plain_id ) );
        $this->assertSame( '9.0.2.5', get_site_option( 'mainwp_db_version' ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        $html = $this->render_notes_widget( $site_id );
        $this->assertSame( $shown, $this->visible_note( $html ) );
        $this->assertSame( $shown, $this->editor_note( $html, $site_id ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'No slashes here', $this->stored_note( $plain_id ) );
    }

    /**
     * A new install records the repair as done and does not rewrite existing notes.
     *
     * @return void
     */
    public function test_fresh_install_sets_flag_and_changes_no_data(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( $this->sample_note() );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        delete_site_option( 'mainwp_db_version' );
        $this->clear_repair_options();

        MainWP_Install::instance()->install();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertSame( '9.0.2.5', get_site_option( 'mainwp_db_version' ) );
    }

    /**
     * A failed note read cannot block completion, because the repair does not read notes.
     *
     * @return void
     */
    public function test_repair_completes_without_reading_notes(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( $this->sample_note() );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        add_filter( 'query', array( $this, 'fail_note_reads' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'fail_note_reads' ) );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
    }

    /**
     * A failed note update cannot rewrite a row, because the repair does not update notes.
     *
     * @return void
     */
    public function test_repair_completes_without_updating_notes(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( $this->sample_note() );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        add_filter( 'query', array( $this, 'fail_note_writes' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'fail_note_writes' ) );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
    }

    /**
     * Notes the old writer could not have stored are left exactly as they are.
     *
     * @return void
     */
    public function test_new_format_backslash_and_bare_quote_survive_unchanged(): void {
        $path     = 'C:\\backup';
        $quoted   = 'say "hi"';
        $path_id  = $this->create_note_site( 'MWP-1804 Path' );
        $quote_id = $this->create_note_site( 'MWP-1804 Quote' );
        $this->store_raw_note( $path_id, $path );
        $this->store_raw_note( $quote_id, $quoted );

        $legacy = MainWP_DB_Common::instance()->escape( 'old \\ path' );
        $old_id = $this->create_note_site( 'MWP-1804 Old' );
        $this->store_raw_note( $old_id, $legacy );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $path, $this->stored_note( $path_id ) );
        $this->assertSame( $quoted, $this->stored_note( $quote_id ) );
        $this->assertSame( $legacy, $this->stored_note( $old_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * A held lock does not make the repair rewrite a note.
     *
     * @return void
     */
    public function test_repair_ignores_a_held_lock_and_does_not_rewrite(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( $this->sample_note() );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        $this->hold_repair_lock();
        MainWP_Install::instance()->repair_site_note_slashes();
        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION );
        wp_cache_delete( 'alloptions', 'options' );

        MainWP_Install::instance()->repair_site_note_slashes();
        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * The repair does not replace a note it never reads.
     *
     * @return void
     */
    public function test_repair_does_not_replace_a_note(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( $this->sample_note() );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * Stored escape sequences stay as text. They are not turned into control characters.
     *
     * @return void
     */
    public function test_escape_sequences_are_not_turned_into_control_characters(): void {
        $newline  = "line1\nline2";
        $stored_n = MainWP_DB_Common::instance()->escape( $newline );
        $this->assertSame( 'line1\\nline2', $stored_n );

        $newline_id = $this->create_note_site( 'MWP-1804 Newline' );
        $this->store_raw_note( $newline_id, $stored_n );

        $typed   = "pre\\mid'q\"d\nline\r\0\x1Aend";
        $legacy  = MainWP_DB_Common::instance()->escape( $typed );
        $full_id = $this->create_note_site( 'MWP-1804 Escapes' );
        $this->store_raw_note( $full_id, $legacy );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $stored_n, $this->stored_note( $newline_id ) );
        $this->assertSame( $legacy, $this->stored_note( $full_id ) );
        $this->assertNotSame( $newline, $this->stored_note( $newline_id ) );
        $this->assertNotSame( $typed, $this->stored_note( $full_id ) );
    }

    /**
     * A partial-batch marker does not make the repair rewrite either note.
     *
     * @return void
     */
    public function test_repair_does_not_resume_by_rewriting_rows(): void {
        $first_legacy  = MainWP_DB_Common::instance()->escape( 'first C:\\backup' );
        $second_legacy = MainWP_DB_Common::instance()->escape( 'second C:\\backup' );
        $first_id      = $this->create_note_site( 'MWP-1804 First' );
        $second_id     = $this->create_note_site( 'MWP-1804 Second' );
        $this->store_raw_note( $first_id, $first_legacy );
        $this->store_raw_note( $second_id, $second_legacy );

        add_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, (string) ( $first_id - 1 ), '', 'no' );
        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $first_legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $second_legacy, $this->stored_note( $second_id ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $first_legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $second_legacy, $this->stored_note( $second_id ) );
    }

    /**
     * Escape-alphabet rows such as C:\new and \\nas stay byte for byte.
     *
     * @return void
     */
    public function test_repair_leaves_escape_alphabet_rows_untouched(): void {
        $legacy        = MainWP_DB_Common::instance()->escape( 'C:\\new' );
        $second_legacy = MainWP_DB_Common::instance()->escape( '\\nas' );
        $first_id      = $this->create_note_site( 'MWP-1804 New' );
        $second_id     = $this->create_note_site( 'MWP-1804 Nas' );
        $this->store_raw_note( $first_id, $legacy );
        $this->store_raw_note( $second_id, $second_legacy );

        add_filter( 'query', array( $this, 'fail_note_writes' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'fail_note_writes' ) );

        $this->assertSame( $legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $second_legacy, $this->stored_note( $second_id ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $second_legacy, $this->stored_note( $second_id ) );
    }

    /**
     * A note saved on an existing site before the flag is complete is not rewritten.
     *
     * @return void
     */
    public function test_note_saved_before_repair_finishes_is_unchanged(): void {
        $note    = 'C:\\new';
        $site_id = $this->create_note_site( 'MWP-1804 Saved Early' );
        MainWP_DB_Common::instance()->update_note( $site_id, $note );
        $this->assertSame( $note, $this->stored_note( $site_id ) );

        $legacy = MainWP_DB_Common::instance()->escape( 'old \\ path' );
        $old_id = $this->create_note_site( 'MWP-1804 Still Old' );
        $this->store_raw_note( $old_id, $legacy );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $note, $this->stored_note( $site_id ) );
        $this->assertSame( $legacy, $this->stored_note( $old_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertNull( $this->skip_row_value( $site_id ) );
    }

    /**
     * A note save does not insert a skip row before or after the update.
     *
     * @return void
     */
    public function test_update_note_does_not_insert_a_skip_row(): void {
        $site_id = $this->create_note_site( 'MWP-1804 Order' );
        $this->store_raw_note( $site_id, 'before' );
        $this->order_site_id = $site_id;

        add_filter( 'query', array( $this, 'assert_no_skip_before_note_write' ) );
        $saved = MainWP_DB_Common::instance()->update_note( $site_id, 'C:\\new' );
        remove_filter( 'query', array( $this, 'assert_no_skip_before_note_write' ) );

        $this->assertTrue( $saved );
        $this->assertTrue( $this->saw_note_write );
        $this->assertSame( 'C:\\new', $this->stored_note( $site_id ) );
        $this->assertNull( $this->skip_row_value( $site_id ) );
    }

    /**
     * The repair does not select notes, so a row stored as escaped text stays escaped.
     *
     * @return void
     */
    public function test_repair_does_not_select_or_rewrite_notes(): void {
        $stored       = MainWP_DB_Common::instance()->escape( 'old \\ path' );
        $sibling_note = MainWP_DB_Common::instance()->escape( 'keep \\ this' );
        $site_id      = $this->create_note_site( 'MWP-1804 During' );
        $this->store_raw_note( $site_id, $stored );

        $sibling = $this->create_note_site( 'MWP-1804 During Sibling' );
        $this->store_raw_note( $sibling, $sibling_note );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $stored, $this->stored_note( $site_id ) );
        $this->assertSame( $sibling_note, $this->stored_note( $sibling ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * Two saves store each note and leave no skip rows behind.
     *
     * @return void
     */
    public function test_two_saves_store_notes_without_skip_rows(): void {
        $first  = $this->create_note_site( 'MWP-1804 Skip A' );
        $second = $this->create_note_site( 'MWP-1804 Skip B' );

        MainWP_DB_Common::instance()->update_note( $first, 'C:\\new' );
        MainWP_DB_Common::instance()->update_note( $second, '\\\\nas' );

        $this->assertNull( $this->skip_row_value( $first ) );
        $this->assertNull( $this->skip_row_value( $second ) );
        $this->assertSame( 'C:\\new', $this->stored_note( $first ) );
        $this->assertSame( '\\\\nas', $this->stored_note( $second ) );
    }

    /**
     * A cached "missing" skip option does not cause the stored note to change.
     *
     * @return void
     */
    public function test_repair_reads_skip_rows_from_the_database(): void {
        $note    = 'C:\\new';
        $site_id = $this->create_note_site( 'MWP-1804 Cache' );
        MainWP_DB_Common::instance()->update_note( $site_id, $note );

        $legacy = MainWP_DB_Common::instance()->escape( 'old \\ path' );
        $old_id = $this->create_note_site( 'MWP-1804 Cache Sibling' );
        $this->store_raw_note( $old_id, $legacy );

        $option     = MainWP_Install::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . $site_id;
        $notoptions = wp_cache_get( 'notoptions', 'options' );
        if ( ! is_array( $notoptions ) ) {
            $notoptions = array();
        }
        $notoptions[ $option ] = true;
        wp_cache_set( 'notoptions', $notoptions, 'options' );
        wp_cache_delete( $option, 'options' );
        $this->assertFalse( get_option( $option, false ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $note, $this->stored_note( $site_id ) );
        $this->assertNotSame( "C:\new", $this->stored_note( $site_id ) );
        $this->assertSame( $legacy, $this->stored_note( $old_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * A dropped checkpoint write does not unescape a stored C:\new escape layer.
     *
     * @return void
     */
    public function test_repair_does_not_unescape_when_a_checkpoint_write_fails(): void {
        $legacy  = MainWP_DB_Common::instance()->escape( 'C:\\new' );
        $site_id = $this->create_note_site( 'MWP-1804 Crash' );
        $this->store_raw_note( $site_id, $legacy );

        add_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, (string) ( $site_id - 1 ), '', 'no' );
        add_filter( 'query', array( $this, 'fail_checkpoint_after_note_write' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'fail_checkpoint_after_note_write' ) );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( 'C:\\new', $this->stored_note( $site_id ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
        $this->assertNull( $this->inflight_payload() );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNull( $this->inflight_payload() );
    }

    /**
     * Manage Sites and Groups put the note tooltip through esc_attr().
     *
     * @return void
     */
    public function test_manage_sites_and_groups_tooltips_are_attribute_escaped(): void {
        $note    = 'Say "hi" & more';
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $note );
        update_option( 'mainwp_use_favicon', 0 );

        $expected = esc_attr( substr( wp_strip_all_tags( MainWP_Utility::esc_content( html_entity_decode( $note ) ) ), 0, 100 ) );
        $tooltip  = 'data-tooltip="' . $expected . '"';
        $row      = $this->website_row( $site_id );

        add_filter( 'mainwp_sitestable_getcolumns', array( $this, 'notes_column_only' ) );
        add_filter( 'mainwp_sitestable_display_row_columns', array( $this, 'skip_site_actions' ), 10, 2 );
        $ajax        = new MainWP_Manage_Sites_List_Table();
        $ajax->items = array( $row );
        $ajax_rows   = $ajax->ajax_get_datatable_rows();
        $ajax_notes  = $ajax_rows['data'][0]['notes'];
        remove_filter( 'mainwp_sitestable_getcolumns', array( $this, 'notes_column_only' ) );
        remove_filter( 'mainwp_sitestable_display_row_columns', array( $this, 'skip_site_actions' ) );

        $grid = new class() extends MainWP_Manage_Sites_List_Table {

            /**
             * Render the notes column and nothing else.
             *
             * @return void
             */
            public function use_notes_column_only() {
                $this->column_headers = array( array( 'notes' => 'Notes' ) );
            }
        };
        $grid->use_notes_column_only();
        ob_start();
        $grid->single_row( $row );
        $grid_html = ob_get_clean();

        ob_start();
        MainWP_Manage_Groups::get_website_list_content();
        $groups_html = ob_get_clean();

        $this->assertStringContainsString( $tooltip, $ajax_notes );
        $this->assertStringContainsString( $tooltip, $grid_html );
        $this->assertStringContainsString( $tooltip, $groups_html );
        $this->assertStringContainsString( '>' . MainWP_Utility::esc_content( $note ) . '</span>', $ajax_notes );
        $this->assertStringContainsString( '>' . MainWP_Utility::esc_content( $note ) . '</span>', $grid_html );
        $this->assertStringContainsString( '>' . MainWP_Utility::esc_content( $note ) . '</span>', $groups_html );
    }

    /**
     * A note save still stores the text when no skip row is written.
     *
     * @return void
     */
    public function test_save_survives_when_completion_deletes_the_skip_row(): void {
        $site_id = $this->create_note_site( 'MWP-1804 Deleted Skip' );
        $this->store_raw_note( $site_id, 'before' );
        $this->during_id = $site_id;

        add_filter( 'query', array( $this, 'complete_repair_before_skip_readback' ) );
        MainWP_DB_Common::instance()->update_note( $site_id, 'C:\\new' );
        remove_filter( 'query', array( $this, 'complete_repair_before_skip_readback' ) );

        $this->assertSame( 'C:\\new', $this->stored_note( $site_id ) );
        $this->assertNull( $this->skip_row_value( $site_id ) );
    }

    /**
     * A save after the flag is complete writes the note and does not leave a skip row.
     *
     * @return void
     */
    public function test_skip_row_inserted_after_completion_is_removed(): void {
        global $wpdb;

        $site_id = $this->create_note_site( 'MWP-1804 Late Skip' );
        $this->store_raw_note( $site_id, 'before' );
        $flag = MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION;

        delete_option( $flag );
        get_option( $flag );
        $wpdb->insert(
            $wpdb->options,
            array(
                'option_name'  => $flag,
                'option_value' => 'complete',
                'autoload'     => 'yes',
            ),
            array( '%s', '%s', '%s' )
        );

        MainWP_DB_Common::instance()->update_note( $site_id, 'C:\\new' );

        $this->assertSame( 'C:\\new', $this->stored_note( $site_id ) );
        $this->assertNull( $this->skip_row_value( $site_id ) );
    }

    /**
     * Stolen-lock filters do not make the repair turn \n or D:\reports into control characters.
     *
     * @return void
     */
    public function test_repair_does_not_unescape_when_a_lock_is_stolen(): void {
        $newline        = 'use \n as newline';
        $reports        = 'D:\\reports';
        $newline_legacy = MainWP_DB_Common::instance()->escape( $newline );
        $reports_legacy = MainWP_DB_Common::instance()->escape( $reports );
        $first_id       = $this->create_note_site( 'MWP-1804 Newline' );
        $second_id      = $this->create_note_site( 'MWP-1804 Reports' );
        $this->store_raw_note( $first_id, $newline_legacy );
        $this->store_raw_note( $second_id, $reports_legacy );

        add_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, (string) ( $first_id - 1 ), '', 'no' );
        add_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_one' ) );
        add_filter( 'query', array( $this, 'steal_lock_on_second_repair_select' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'steal_lock_on_second_repair_select' ) );
        remove_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_one' ) );

        $this->assertSame( $newline_legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $reports_legacy, $this->stored_note( $second_id ) );
        $this->assertNotSame( "use \n as newline", $this->stored_note( $first_id ) );
        $this->assertNotSame( 'D:' . "\r" . 'eports', $this->stored_note( $second_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertFalse( get_transient( MainWP_Install::SITE_NOTE_SLASH_REPAIR_RETRY ) );
    }

    /**
     * The repair finishes without pausing, and it does not unescape the stored note.
     *
     * @return void
     */
    public function test_repair_completes_without_a_time_budget_pause(): void {
        $typed   = 'use \n as newline';
        $legacy  = MainWP_DB_Common::instance()->escape( $typed );
        $site_id = $this->create_note_site( 'MWP-1804 Budget' );
        $this->store_raw_note( $site_id, $legacy );

        add_filter( 'mainwp_site_note_slash_repair_time_budget', array( $this, 'repair_budget_of_zero' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'mainwp_site_note_slash_repair_time_budget', array( $this, 'repair_budget_of_zero' ) );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
        $this->assertFalse( get_transient( MainWP_Install::SITE_NOTE_SLASH_REPAIR_RETRY ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK, false ) );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( $typed, $this->stored_note( $site_id ) );
    }

    /**
     * A takeover during a note update cannot rewind stored escape-alphabet notes.
     *
     * @return void
     */
    public function test_repair_does_not_rewind_or_rewrite_notes(): void {
        $newline        = 'use \n as newline';
        $reports        = 'D:\\reports';
        $nas            = '\\nas';
        $newline_legacy = MainWP_DB_Common::instance()->escape( $newline );
        $reports_legacy = MainWP_DB_Common::instance()->escape( $reports );
        $nas_legacy     = MainWP_DB_Common::instance()->escape( $nas );
        $first_id       = $this->create_note_site( 'MWP-1804 Rewind Newline' );
        $second_id      = $this->create_note_site( 'MWP-1804 Rewind Reports' );
        $third_id       = $this->create_note_site( 'MWP-1804 Rewind Nas' );
        $this->store_raw_note( $first_id, $newline_legacy );
        $this->store_raw_note( $second_id, $reports_legacy );
        $this->store_raw_note( $third_id, $nas_legacy );

        add_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, (string) ( $first_id - 1 ), '', 'no' );
        add_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_one' ) );
        add_filter( 'query', array( $this, 'takeover_during_first_note_update' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'takeover_during_first_note_update' ) );
        remove_filter( 'mainwp_site_note_slash_repair_batch_size', array( $this, 'repair_batch_of_one' ) );

        $this->assertSame( $newline_legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $reports_legacy, $this->stored_note( $second_id ) );
        $this->assertSame( $nas_legacy, $this->stored_note( $third_id ) );
        $this->assertFalse( get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, false ) );
        $this->assertNull( $this->inflight_payload() );

        delete_transient( MainWP_Install::SITE_NOTE_SLASH_REPAIR_RETRY );
        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $newline_legacy, $this->stored_note( $first_id ) );
        $this->assertSame( $reports_legacy, $this->stored_note( $second_id ) );
        $this->assertSame( $nas_legacy, $this->stored_note( $third_id ) );
        $this->assertNotSame( "use \n as newline", $this->stored_note( $first_id ) );
        $this->assertNotSame( 'D:' . "\r" . 'eports', $this->stored_note( $second_id ) );
        $this->assertNotSame( "\nas", $this->stored_note( $third_id ) );
        $this->assertNull( $this->inflight_payload() );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * Ajax, heartbeat, and cron requests leave the repair for a normal admin page load.
     *
     * @return void
     */
    public function test_background_requests_do_not_run_the_repair(): void {
        $typed   = 'use \n as newline';
        $legacy  = MainWP_DB_Common::instance()->escape( $typed );
        $site_id = $this->create_note_site( 'MWP-1804 Background' );
        $this->store_raw_note( $site_id, $legacy );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        add_filter( 'wp_doing_ajax', '__return_true' );
        MainWP_Install::instance()->maybe_repair_site_note_slashes();
        remove_filter( 'wp_doing_ajax', '__return_true' );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION, false ) );

        add_filter( 'wp_doing_cron', '__return_true' );
        MainWP_Install::instance()->maybe_repair_site_note_slashes();
        remove_filter( 'wp_doing_cron', '__return_true' );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );

        MainWP_Install::instance()->maybe_repair_site_note_slashes();

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( $typed, $this->stored_note( $site_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * Pre-2020 notes that were stored correctly stay byte for byte after the repair.
     *
     * @return void
     */
    public function test_pre_2020_notes_are_unchanged_by_the_repair(): void {
        $reports = 'D:\\reports';
        $share   = '\\\\server\\share';
        $literal = 'See \\n in the note';
        $windows = 'C:\\new';

        $reports_id = $this->create_note_site( 'MWP-1804 Pre Reports' );
        $share_id   = $this->create_note_site( 'MWP-1804 Pre Share' );
        $literal_id = $this->create_note_site( 'MWP-1804 Pre Literal' );
        $windows_id = $this->create_note_site( 'MWP-1804 Pre Windows' );
        $this->store_raw_note( $reports_id, $reports );
        $this->store_raw_note( $share_id, $share );
        $this->store_raw_note( $literal_id, $literal );
        $this->store_raw_note( $windows_id, $windows );

        MainWP_Install::instance()->repair_site_note_slashes();

        $this->assertSame( $reports, $this->stored_note( $reports_id ) );
        $this->assertSame( $share, $this->stored_note( $share_id ) );
        $this->assertSame( $literal, $this->stored_note( $literal_id ) );
        $this->assertSame( $windows, $this->stored_note( $windows_id ) );
        $this->assertSame( 'complete', get_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION ) );
    }

    /**
     * A failed note UPDATE is reported as an error and leaves the old note and no skip row.
     *
     * @return void
     */
    public function test_failed_note_update_returns_an_error_and_leaves_no_skip_marker(): void {
        $site_id = $this->create_note_site( 'MWP-1804 Failed Save' );
        $this->store_raw_note( $site_id, 'before' );

        $_POST['websiteid'] = $site_id;
        $_POST['note']      = wp_slash( 'after' );

        add_filter( 'query', array( $this, 'fail_note_writes' ) );

        $message = '';
        try {
            MainWP_Manage_Sites_Handler::save_note();
            $this->fail( 'A failed note save must not report success.' );
        } catch ( \WPDieException $exception ) {
            $message = $exception->getMessage();
        } finally {
            unset( $_POST['websiteid'], $_POST['note'] );
            remove_filter( 'query', array( $this, 'fail_note_writes' ) );
        }

        $payload = json_decode( $message, true );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'error', $payload );
        $this->assertNotSame( 'SUCCESS', isset( $payload['result'] ) ? $payload['result'] : null );
        $this->assertSame( 'before', $this->stored_note( $site_id ) );
        $this->assertNull( $this->skip_row_value( $site_id ) );
    }

    /**
     * Drop repair reads so the caller can see the failure.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_note_reads( $query ) {
        if ( $this->is_repair_select( $query ) ) {
            return '';
        }
        return $query;
    }

    /**
     * Fail the second repair read so the first batch can commit its last id.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_second_note_read( $query ) {
        if ( $this->is_repair_select( $query ) ) {
            ++$this->repair_selects;
            if ( $this->repair_selects >= 2 ) {
                return '';
            }
        }
        return $query;
    }

    /**
     * Drop repair updates so the caller can see the failure.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_note_writes( $query ) {
        if ( is_string( $query ) && false !== stripos( $query, 'UPDATE' ) && false !== stripos( $query, 'SET note=' ) ) {
            return '';
        }
        return $query;
    }

    /**
     * Fail the second note update inside one batch.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_second_note_write( $query ) {
        if ( is_string( $query ) && false !== stripos( $query, 'UPDATE' ) && false !== stripos( $query, 'SET note=' ) ) {
            ++$this->repair_writes;
            if ( $this->repair_writes >= 2 ) {
                return '';
            }
        }
        return $query;
    }

    /**
     * Assert no skip row exists when the note UPDATE runs.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function assert_no_skip_before_note_write( $query ) {
        if ( is_string( $query ) && false !== stripos( $query, 'UPDATE' ) && false !== stripos( $query, 'SET note=' ) ) {
            $this->saw_note_write = true;
            $this->assertNull( $this->skip_row_value( $this->order_site_id ) );
            $this->assertSame( 'before', $this->stored_note( $this->order_site_id ) );
        }
        return $query;
    }

    /**
     * Save C:\new while the repair is selecting notes.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function save_note_during_repair_select( $query ) {
        if ( $this->inside_save || ! $this->is_repair_select( $query ) ) {
            return $query;
        }

        $this->inside_save = true;
        MainWP_DB_Common::instance()->update_note( $this->during_id, 'C:\\new' );
        return $query;
    }

    /**
     * Let the note update succeed, then drop the finished-id write.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_checkpoint_after_note_write( $query ) {
        if ( ! is_string( $query ) ) {
            return $query;
        }
        if ( false !== stripos( $query, 'UPDATE' ) && false !== stripos( $query, 'SET note=' ) ) {
            ++$this->repair_writes;
        }
        $writes_last_id = false !== stripos( $query, MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID );
        $is_write       = false !== stripos( $query, 'UPDATE' ) || false !== stripos( $query, 'INSERT' );
        if ( $this->repair_writes >= 1 && $writes_last_id && $is_write ) {
            return '';
        }
        return $query;
    }

    /**
     * Delete the new skip row and mark the repair complete before its read-back runs.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function complete_repair_before_skip_readback( $query ) {
        if ( $this->inside_save || ! is_string( $query ) ) {
            return $query;
        }
        if ( false === stripos( $query, 'SELECT option_id' ) || false === stripos( $query, MainWP_Install::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX ) ) {
            return $query;
        }

        global $wpdb;

        $this->inside_save = true;
        $flag              = MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION;
        $skip              = MainWP_Install::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . (int) $this->during_id;

        $wpdb->delete( $wpdb->options, array( 'option_name' => $flag ), array( '%s' ) );
        $wpdb->insert(
            $wpdb->options,
            array(
                'option_name'  => $flag,
                'option_value' => 'complete',
                'autoload'     => 'yes',
            ),
            array( '%s', '%s', '%s' )
        );
        $wpdb->delete( $wpdb->options, array( 'option_name' => $skip ), array( '%s' ) );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( $flag, 'options' );

        return $query;
    }

    /**
     * Expire the lock on the second note read and let another repair take over.
     *
     * The cached last id is set to 0. The database checkpoint is left as the first site.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function steal_lock_on_second_repair_select( $query ) {
        if ( $this->inside_save || ! $this->is_repair_select( $query ) ) {
            return $query;
        }

        ++$this->repair_selects;
        if ( $this->repair_selects < 2 ) {
            return $query;
        }

        global $wpdb;

        $this->inside_save = true;
        $option            = MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK;
        $current           = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $option
            )
        );
        $token             = is_string( $current ) ? explode( '|', $current, 2 )[0] : '';
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $token . '|1' ),
            array( 'option_name' => $option ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $option, 'options' );
        wp_cache_set( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, '0', 'options' );
        MainWP_Install::instance()->repair_site_note_slashes();

        return $query;
    }

    /**
     * Let a second repair take over during the first note update, then stop that repair.
     *
     * The new holder is crashed after it fixes the third site and before it saves that id.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function takeover_during_first_note_update( $query ) {
        if ( $this->inside_save || ! is_string( $query ) ) {
            return $query;
        }
        if ( false === stripos( $query, 'UPDATE' ) || false === stripos( $query, 'SET note=' ) ) {
            return $query;
        }

        global $wpdb;

        $this->inside_save   = true;
        $this->repair_writes = 0;
        $option              = MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK;
        $current             = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $option
            )
        );
        $token               = is_string( $current ) ? explode( '|', $current, 2 )[0] : '';
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $token . '|1' ),
            array( 'option_name' => $option ),
            array( '%s' ),
            array( '%s' )
        );
        wp_cache_delete( $option, 'options' );
        add_filter( 'query', array( $this, 'crash_takeover_before_site_three_checkpoint' ) );
        MainWP_Install::instance()->repair_site_note_slashes();
        remove_filter( 'query', array( $this, 'crash_takeover_before_site_three_checkpoint' ) );

        return $query;
    }

    /**
     * Drop the checkpoint write after the takeover has updated the third note.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function crash_takeover_before_site_three_checkpoint( $query ) {
        if ( ! is_string( $query ) ) {
            return $query;
        }
        if ( false !== stripos( $query, 'UPDATE' ) && false !== stripos( $query, 'SET note=' ) ) {
            ++$this->repair_writes;
        }
        $writes_last_id = false !== stripos( $query, MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID );
        $is_write       = false !== stripos( $query, 'UPDATE' ) || false !== stripos( $query, 'INSERT' );
        if ( $this->repair_writes >= 3 && $writes_last_id && $is_write ) {
            return '';
        }
        return $query;
    }

    /**
     * One site row per repair batch.
     *
     * @return int
     */
    public function repair_batch_of_one() {
        return 1;
    }

    /**
     * Several site rows per repair batch.
     *
     * @return int
     */
    public function repair_batch_of_ten() {
        return 10;
    }

    /**
     * Stop the repair before it writes a note.
     *
     * @return int
     */
    public function repair_budget_of_zero() {
        return 0;
    }

    /**
     * Leave only the notes column in the ajax table.
     *
     * @return array
     */
    public function notes_column_only() {
        return array( 'notes' => 'Notes' );
    }

    /**
     * Skip the actions column the table always appends.
     *
     * @param mixed  $content     Column HTML, or false to render the default.
     * @param string $column_name Column key.
     *
     * @return mixed
     */
    public function skip_site_actions( $content, $column_name ) {
        if ( 'site_actions' === $column_name ) {
            return '';
        }
        return $content;
    }

    /**
     * Whether this query is the repair's note select.
     *
     * @param mixed $query SQL.
     *
     * @return bool
     */
    private function is_repair_select( $query ) {
        return is_string( $query ) && false !== stripos( $query, 'SELECT id, note FROM' );
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
     * Read one per-site skip row from the database.
     *
     * @param int $site_id Site ID.
     *
     * @return string|null
     */
    private function skip_row_value( $site_id ) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                MainWP_Install::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX . (int) $site_id
            )
        );
    }

    /**
     * Read the in-progress repair marker from the database.
     *
     * @return array|null
     */
    private function inflight_payload() {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                MainWP_Install::SITE_NOTE_SLASH_REPAIR_INFLIGHT
            )
        );
        if ( null === $value ) {
            return null;
        }

        $payload = maybe_unserialize( $value );
        return is_array( $payload ) ? $payload : null;
    }

    /**
     * Delete the repair option, resume marker, and lock.
     *
     * @return void
     */
    private function clear_repair_options() {
        global $wpdb;

        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION );
        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID );
        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_MAX_ID );
        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_INFLIGHT );
        delete_option( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK );
        $like = $wpdb->esc_like( MainWP_Install::SITE_NOTE_SLASH_REPAIR_SKIP_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        delete_transient( MainWP_Install::SITE_NOTE_SLASH_REPAIR_RETRY );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( MainWP_Install::SITE_NOTE_SLASH_REPAIR_OPTION, 'options' );
        wp_cache_delete( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LAST_ID, 'options' );
        wp_cache_delete( MainWP_Install::SITE_NOTE_SLASH_REPAIR_MAX_ID, 'options' );
        wp_cache_delete( MainWP_Install::SITE_NOTE_SLASH_REPAIR_INFLIGHT, 'options' );
        wp_cache_delete( MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK, 'options' );
    }

    /**
     * Hold the repair lock the way a concurrent admin request would.
     *
     * @return void
     */
    private function hold_repair_lock() {
        global $wpdb;

        $option = MainWP_Install::SITE_NOTE_SLASH_REPAIR_LOCK;
        delete_option( $option );
        $wpdb->insert(
            $wpdb->options,
            array(
                'option_name'  => $option,
                'option_value' => 'held|' . ( time() + 300 ),
                'autoload'     => 'no',
            ),
            array( '%s', '%s', '%s' )
        );
        wp_cache_delete( $option, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /**
     * Site array the manage-sites table can render a notes cell from.
     *
     * @param int $site_id Site ID.
     *
     * @return array
     */
    private function website_row( $site_id ) {
        global $wpdb;

        $row                            = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $site_id
            ),
            ARRAY_A
        );
        $row['sync_errors']             = '';
        $row['suspended']               = '0';
        $row['is_ignoreCoreUpdates']    = 0;
        $row['is_ignorePluginUpdates']  = 0;
        $row['is_ignoreThemeUpdates']   = 0;
        $row['plugin_upgrades']         = '';
        $row['theme_upgrades']          = '';
        $row['ignored_plugins']         = '';
        $row['ignored_themes']          = '';
        $row['wp_upgrades']             = '';
        $row['ignored_wp_upgrades']     = '';
        $row['premium_upgrades']        = '';
        $row['primary_lasttime_backup'] = '';
        $row['securityIssues']          = '';
        $row['http_response_code']      = 0;

        return $row;
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
