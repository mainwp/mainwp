<?php
/**
 * Site notes are stored and shown as typed, including backslashes (MWP-1804).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Common;
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
     * Remove the site rows this case inserted.
     *
     * @return void
     */
    public function tearDown(): void {
        global $wpdb;

        remove_filter( 'query', array( $this, 'fail_note_writes' ) );
        remove_filter( 'query', array( $this, 'fail_note_existence_select' ) );
        unset( $_POST['websiteid'], $_POST['note'] );

        foreach ( $this->site_ids as $site_id ) {
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp', array( 'id' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_options', array( 'wpid' => $site_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', array( 'wpid' => $site_id ), array( '%d' ) );
        }

        MainWP_System_Utility::set_current_wpid( 0 );
        wp_set_current_user( 0 );
        parent::tearDown();
        wp_cache_flush();
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
     * A note with quotes and a backslash is stored exactly as passed in.
     *
     * @return void
     */
    public function test_update_note_stores_quotes_and_backslash_without_added_slashes(): void {
        $note    = $this->sample_note();
        $site_id = $this->create_note_site();

        $this->assert_update_note_result( $site_id, $note, true );

        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * Save, render, and save again leave a typed note unchanged.
     *
     * The second save is the same text. It must report success, not a failed update.
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
     * Saving the note that is already stored succeeds.
     *
     * @return void
     */
    public function test_identical_resave_succeeds(): void {
        $note    = 'C:\\new';
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $note );

        $this->assert_update_note_result( $site_id, $note, true );

        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * Saving an empty note over an empty note succeeds.
     *
     * @return void
     */
    public function test_empty_note_resave_succeeds(): void {
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, '' );

        $this->assert_update_note_result( $site_id, '', true );

        $this->assertSame( '', $this->stored_note( $site_id ) );
    }

    /**
     * A note update for a site that is not in the table fails.
     *
     * @return void
     */
    public function test_missing_site_update_fails(): void {
        $this->assert_update_note_result( 2147483646, 'missing', false );
    }

    /**
     * An already-escaped note stays byte for byte, and the screen shows that stored text.
     *
     * @return void
     */
    public function test_legacy_escaped_note_stays_stored_and_displays_as_stored(): void {
        $typed   = $this->sample_note();
        $legacy  = MainWP_DB_Common::instance()->escape( $typed );
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $legacy );

        $shown = MainWP_Utility::esc_content( html_entity_decode( $legacy ) );
        $html  = $this->render_notes_widget( $site_id );

        $this->assertSame( $legacy, $this->stored_note( $site_id ) );
        $this->assertNotSame( $typed, $this->stored_note( $site_id ) );
        $this->assertSame( $shown, $this->visible_note( $html ) );
        $this->assertSame( $shown, $this->editor_note( $html, $site_id ) );
    }

    /**
     * Paths, a literal backslash-n, and quotes stay typed text on every note surface.
     *
     * Each value is saved, shown, and saved again. The second save is the same text.
     *
     * @param string $note Note as the user typed it.
     *
     * @return void
     *
     * @dataProvider typed_note_provider
     */
    public function test_typed_note_round_trips_through_save_and_display( $note ): void {
        $site_id = $this->create_note_site();

        $this->assert_update_note_result( $site_id, $note, true );
        $this->assertSame( $note, $this->stored_note( $site_id ) );

        $widget = $this->render_notes_widget( $site_id );
        $this->assertSame( $note, $this->visible_note( $widget ) );
        $this->assertSame( $note, $this->editor_note( $widget, $site_id ) );

        foreach ( $this->list_and_groups_note_html( $site_id ) as $html ) {
            $this->assertSame( $note, $this->editor_note( $html, $site_id ) );
            $this->assertSame( $note, $this->tooltip_note( $html, $site_id ) );
        }

        $this->assert_update_note_result( $site_id, $note, true );
        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * Notes whose backslashes must survive a save and every display path.
     *
     * @return array
     */
    public function typed_note_provider() {
        return array(
            'reports'                => array( 'D:\\reports' ),
            'share'                  => array( '\\\\server\\share' ),
            'literal n'              => array( 'See \\n in the note' ),
            'windows'                => array( 'C:\\new' ),
            'nas'                    => array( '\\\\nas' ),
            'quotes and backslashes' => array( 'Client said "don\'t" store this at C:\\backup\\site' ),
        );
    }

    /**
     * Saving the note that is already stored returns SUCCESS from the handler.
     *
     * @return void
     */
    public function test_identical_resave_returns_success(): void {
        $note    = 'C:\\new';
        $site_id = $this->create_note_site();
        $this->store_raw_note( $site_id, $note );

        $_POST['websiteid'] = $site_id;
        $_POST['note']      = wp_slash( $note );

        $message = '';
        try {
            MainWP_Manage_Sites_Handler::save_note();
            $this->fail( 'An identical note save must return SUCCESS.' );
        } catch ( \WPDieException $exception ) {
            $message = $exception->getMessage();
        } finally {
            unset( $_POST['websiteid'], $_POST['note'] );
        }

        $payload = json_decode( $message, true );
        $this->assertIsArray( $payload );
        $this->assertSame( 'SUCCESS', isset( $payload['result'] ) ? $payload['result'] : null );
        $this->assertArrayNotHasKey( 'error', $payload );
        $this->assertSame( $note, $this->stored_note( $site_id ) );
    }

    /**
     * A failed existence check is an AJAX error and leaves the stored note alone.
     *
     * The note is already stored, so the UPDATE changes no rows and the SELECT runs.
     *
     * @return void
     */
    public function test_failed_existence_select_returns_an_error_and_leaves_the_note(): void {
        $note    = 'C:\\new';
        $site_id = $this->create_note_site( 'MWP-1804 Select Fail' );
        $this->store_raw_note( $site_id, $note );

        $_POST['websiteid'] = $site_id;
        $_POST['note']      = wp_slash( $note );

        add_filter( 'query', array( $this, 'fail_note_existence_select' ) );
        $message = '';
        try {
            MainWP_Manage_Sites_Handler::save_note();
            $this->fail( 'A failed existence check must not report success.' );
        } catch ( \WPDieException $exception ) {
            $message = $exception->getMessage();
        } finally {
            unset( $_POST['websiteid'], $_POST['note'] );
            remove_filter( 'query', array( $this, 'fail_note_existence_select' ) );
        }

        $payload = json_decode( $message, true );
        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'error', $payload );
        $this->assertNotSame( 'SUCCESS', isset( $payload['result'] ) ? $payload['result'] : null );
        $this->assertSame( $note, $this->stored_note( $site_id ) );
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
     * A failed note UPDATE is reported as an error and leaves the old note in place.
     *
     * @return void
     */
    public function test_failed_note_update_returns_an_error_and_leaves_the_old_note(): void {
        $site_id = $this->create_note_site( 'MWP-1804 Failed Save' );
        $this->store_raw_note( $site_id, 'before' );

        $_POST['websiteid'] = $site_id;
        $_POST['note']      = wp_slash( 'after' );

        add_filter( 'query', array( $this, 'fail_note_writes' ) );
        $this->assert_update_note_result( $site_id, 'after', false );

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
        $this->assertFalse( get_option( 'mainwp_site_note_slash_repair_skip_' . $site_id, false ) );
        $this->assertFalse( get_option( 'mainwp_site_note_slash_repair_skip', false ) );
    }

    /**
     * Drop note updates so the caller can see the failure.
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
     * Blank only the existence SELECT that follows an unchanged note UPDATE.
     *
     * @param string $query SQL.
     *
     * @return string
     */
    public function fail_note_existence_select( $query ) {
        if ( is_string( $query ) && false !== stripos( $query, 'SELECT id FROM' ) && false !== stripos( $query, 'WHERE id' ) ) {
            return '';
        }
        return $query;
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
     * Save a note and require update_note() to report that outcome.
     *
     * @param int    $site_id  Site id. May be an id that is not in the table.
     * @param string $note     Note text.
     * @param bool   $expected Expected return from update_note().
     *
     * @return void
     */
    private function assert_update_note_result( $site_id, $note, $expected ) {
        $saved = MainWP_DB_Common::instance()->update_note( $site_id, $note );
        $this->assertSame( $expected, $saved );
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
        $this->assert_update_note_result( $site_id, $esc_note, true );
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
     * Write a column value without a second escape.
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

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT note FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
                $site_id
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || ! array_key_exists( 'note', $row ) ) {
            return null;
        }

        return $row['note'];
    }

    /**
     * Notes HTML from the ajax table, the grid row, and the groups page.
     *
     * @param int $site_id Site ID.
     *
     * @return string[]
     */
    private function list_and_groups_note_html( $site_id ) {
        update_option( 'mainwp_use_favicon', 0 );
        $row = $this->website_row( $site_id );

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

        return array( $ajax_notes, $grid_html, $groups_html );
    }

    /**
     * Visible tooltip text on a manage-sites or groups note control.
     *
     * @param string $html    Row markup.
     * @param int    $site_id Site ID.
     *
     * @return string
     */
    private function tooltip_note( $html, $site_id ) {
        $id = 'mainwp-notes-' . (int) $site_id;
        $this->assertSame( 1, preg_match( '/id="' . preg_quote( $id, '/' ) . '"[^>]*data-tooltip="([^"]*)"/', $html, $matches ) );
        return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
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
