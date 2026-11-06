<?php
/**
 * Stored sync response privacy regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Connect_Failure_Ux;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Existing test convention.
class Test_MWP_1888_Stored_Sync_Errors extends \WP_UnitTestCase {
    use Site_Fixture;

    public function test_fixed_diagnostic_survives_without_excerpt(): void {
        $diagnostic = MainWP_Connect_Failure_Ux::describe_received_response( 'SYNTHETIC_RESPONSE_MARKER' );
        $this->assertStringContainsString( 'Body:', $diagnostic );
        $this->assertStringNotContainsString( 'SYNTHETIC_RESPONSE_MARKER', $diagnostic );
        $this->assertStringNotContainsString( 'Excerpt:', $diagnostic );
    }

    public function test_stored_dump_is_removed_and_fixed_guidance_kept(): void {
        $safe = __( 'Body: the site returned text instead of the MainWP response. Ask the host to check the registration request to wp-admin/admin-ajax.php.', 'mainwp' );
        $prefix = '<div><pre>Status: 403' . "\n\n";
        $this->assertSame( $prefix . $safe . '</pre></div>', MainWP_Connect_Failure_Ux::sanitize_sync_error( $prefix . $safe . "\nExcerpt: SYNTHETIC_RESPONSE_MARKER</pre></div>" ) );
        $this->assertSame( $prefix . '</pre></div>', MainWP_Connect_Failure_Ux::sanitize_sync_error( $prefix . 'Body: SYNTHETIC_RESPONSE_MARKER</pre></div>' ) );
        $this->assertSame( $safe, MainWP_Connect_Failure_Ux::sanitize_sync_error( $safe ) );
    }

    public function test_upgrade_cleans_existing_rows_and_persistence_stays_clean(): void {
        global $wpdb;
        $id = $this->create_test_site( array( 'sync_errors' => '<pre>Status: 403' . "\nBody: SYNTHETIC_RESPONSE_MARKER\nExcerpt: SYNTHETIC_EXCERPT</pre>" ) );
        delete_site_option( 'mainwp_sync_error_excerpts_cleaned' );
        MainWP_Install::instance()->install();
        $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT sync_errors FROM %i WHERE wpid = %d', $wpdb->prefix . 'mainwp_wp_sync', $id ) );
        $this->assertSame( "<pre>Status: 403\n</pre>", $stored );
        $this->assertSame( '1', (string) get_site_option( 'mainwp_sync_error_excerpts_cleaned' ) );
        MainWP_DB::instance()->update_website_sync_values( $id, array( 'sync_errors' => "Connection failed.\nExcerpt: SYNTHETIC_EXCERPT" ) );
        $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT sync_errors FROM %i WHERE wpid = %d', $wpdb->prefix . 'mainwp_wp_sync', $id ) );
        $this->assertStringNotContainsString( 'SYNTHETIC_EXCERPT', $stored );
    }

    public function test_cleanup_retries_after_failed_write(): void {
        $id = $this->create_test_site( array( 'sync_errors' => 'Body: SYNTHETIC_RESPONSE_MARKER' ) );
        delete_site_option( 'mainwp_sync_error_excerpts_cleaned' );
        $block = static function ( $query ) {
            return str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'mainwp_wp_sync' ) ? 'SELECT * FROM mwp1888_missing_synthetic_table' : $query;
        };
        global $wpdb;
        $previous = $wpdb->suppress_errors();
        add_filter( 'query', $block );
        try {
            MainWP_Install::instance()->install();
        } finally {
            remove_filter( 'query', $block );
            $wpdb->suppress_errors( $previous );
        }
        $this->assertFalse( get_site_option( 'mainwp_sync_error_excerpts_cleaned' ) );
        MainWP_Install::instance()->install();
        $this->assertSame( '1', (string) get_site_option( 'mainwp_sync_error_excerpts_cleaned' ) );
        $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT sync_errors FROM %i WHERE wpid = %d', $wpdb->prefix . 'mainwp_wp_sync', $id ) );
        $this->assertNotSame( '', $stored );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $stored );
    }

    public function test_rest_formatter_hides_legacy_excerpt_before_cleanup(): void {
        $id = $this->create_test_site( array( 'sync_errors' => '<pre>Status: 403' . "\nBody: SYNTHETIC_RESPONSE_MARKER\nExcerpt: SYNTHETIC_EXCERPT</pre>" ) );
        $controller = new \MainWP_Rest_Sites_Controller();
        $method = new \ReflectionMethod( $controller, 'get_pre_formatted_item_data' );
        $method->setAccessible( true );
        $site = MainWP_DB::instance()->get_website_by_id( $id );
        $site->security_stats = '';
        $data = $method->invoke( $controller, $site, array( 'full_data' => true ) );
        $this->assertStringContainsString( 'Status: 403', $data['sync_errors'] );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $data['sync_errors'] );
    }

    public function test_translated_escaped_guidance_is_preserved(): void {
        $translate = static function ( $translation, $original, $domain ) {
            if ( 'mainwp' === $domain && str_starts_with( $original, 'Body:' ) ) {
                return "Corps: l'hôte & proxy";
            }
            if ( 'mainwp' === $domain && 'Excerpt: %s' === $original ) {
                return "Extrait d'hôte: %s";
            }
            return $translation;
        };
        add_filter( 'gettext', $translate, 10, 3 );
        try {
            $english = 'Body: the site returned text instead of the MainWP response. Ask the host to check the registration request to wp-admin/admin-ajax.php.';
            $this->assertSame( $english, MainWP_Connect_Failure_Ux::sanitize_sync_error( $english ) );
            $safe = esc_html( MainWP_Connect_Failure_Ux::describe_received_response( 'text' ) );
            $this->assertSame( '<pre>' . $safe . '</pre>', MainWP_Connect_Failure_Ux::sanitize_sync_error( '<pre>' . $safe . "\n" . esc_html( sprintf( __( 'Excerpt: %s', 'mainwp' ), 'SYNTHETIC_EXCERPT' ) ) . '</pre>' ) );
        } finally {
            remove_filter( 'gettext', $translate, 10 );
        }
    }

    public function test_overview_hides_stored_dump_before_cleanup(): void {
        $id = $this->create_test_site( array( 'sync_errors' => '<pre>Status: 403' . "\nBody: SYNTHETIC_RESPONSE_MARKER\nExcerpt: SYNTHETIC_EXCERPT</pre>" ) );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        wp_get_current_user()->current_site_id = $id;
        delete_option( 'mainwp_run_quick_setup' );
        set_current_screen( 'mainwp_page_MainWP' );
        ob_start();
        try {
            \MainWP\Dashboard\MainWP_Overview::render_dashboard_body( array( MainWP_DB::instance()->get_website_by_id( $id ) ) );
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            wp_get_current_user()->current_site_id = 0;
        }
        $this->assertStringContainsString( 'Status: 403', $html );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $html );
    }

    public function test_previous_locale_excerpt_is_removed_but_single_line_guidance_remains(): void {
        $prefix = "<pre>Status: 403\n\nHeaders: none\n\n";
        $this->assertSame( $prefix . '</pre>', MainWP_Connect_Failure_Ux::sanitize_sync_error( $prefix . "Corps : diagnostic fixe\nExtrait : SYNTHETIC_EXCERPT</pre>" ) );
        $this->assertSame( $prefix . 'Corps : diagnostic fixe</pre>', MainWP_Connect_Failure_Ux::sanitize_sync_error( $prefix . 'Corps : diagnostic fixe</pre>' ) );
    }

    public function test_ordinary_body_words_do_not_remove_failure_guidance(): void {
        foreach ( array( 'Invalid request body: missing field', 'Nobody: available', 'Somebody: unavailable' ) as $text ) {
            $message = $text . '<div><pre>Status: 403</pre></div>';
            $this->assertSame( $message, MainWP_Connect_Failure_Ux::sanitize_sync_error( $message ) );
        }
    }

    public function test_removed_dump_remains_a_disconnected_error(): void {
        $clean = MainWP_Connect_Failure_Ux::sanitize_sync_error( 'Body: SYNTHETIC_RESPONSE_MARKER' );
        $this->assertNotSame( '', $clean );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $clean );
    }

    public function test_regex_failure_does_not_mark_site_connected(): void {
        $previous = ini_set( 'pcre.backtrack_limit', '1' );
        try {
            $clean = MainWP_Connect_Failure_Ux::sanitize_sync_error( '<pre>' . str_repeat( 'SYNTHETIC_', 500 ) . '</pre>' );
        } finally {
            ini_set( 'pcre.backtrack_limit', $previous );
        }
        $this->assertNotSame( '', $clean );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $clean );
    }

    public function test_live_rest_response_hides_stale_excerpt(): void {
        $id = $this->create_test_site( array( 'sync_errors' => "<pre>Status: 403\n\nBody: SYNTHETIC_RESPONSE_MARKER\nExcerpt: SYNTHETIC_EXCERPT</pre>" ) );
        $site = MainWP_DB::instance()->get_website_by_id( $id );
        $request = new \WP_REST_Request( 'GET', '/mainwp/v2/sites/' . $id );
        $request->set_param( '_fields', 'id,sync_errors' );
        $data = ( new \MainWP_Rest_Sites_Controller() )->prepare_item_for_response( $site, $request );
        $this->assertStringContainsString( 'Status: 403', $data['sync_errors'] );
        $this->assertStringNotContainsString( 'SYNTHETIC_', $data['sync_errors'] );
    }

    public function test_upgrade_cleans_more_than_one_batch(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'mainwp_wp_sync';
        for ( $id = 100001; $id <= 100201; $id++ ) {
            $this->assertNotFalse( $wpdb->insert( $table, array( 'wpid' => $id, 'sync_errors' => 'Body: SYNTHETIC_RESPONSE_MARKER' ) ) );
        }
        delete_site_option( 'mainwp_sync_error_excerpts_cleaned' );
        MainWP_Install::instance()->install();
        $this->assertSame( '1', (string) get_site_option( 'mainwp_sync_error_excerpts_cleaned' ) );
        $remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE sync_errors LIKE %s', $table, '%SYNTHETIC_RESPONSE_MARKER%' ) );
        $this->assertSame( '0', (string) $remaining );
    }
}
