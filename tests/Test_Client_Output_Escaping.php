<?php
/**
 * Stored client values can contain markup from older or other write paths.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_Client_List_Table;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Updates_Per_Item;
use const MainWP\Dashboard\MAINWP_VIEW_PER_PLUGIN_THEME;

class Test_Client_Output_Escaping extends \WP_UnitTestCase {

    use Site_Fixture;

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        set_current_screen( 'dashboard' );
    }

    public function tearDown(): void {
        $_GET = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public static function header_fields(): array {
        return array( array( 'name', '<img src=x onerror=alert(1)> O\'Brien' ), array( 'client_email', '"><script>alert(1)</script>@example.com' ) );
    }

    /**
     * @dataProvider header_fields
     * @param string $field Stored field.
     * @param string $value Stored markup.
     */
    public function test_client_header_preserves_markup_as_text( $field, $value ): void {
        $client = MainWP_DB_Client::instance()->update_client( array( 'name' => 'Client', 'client_email' => 'header@example.com', $field => $value ), true );
        $this->assertIsObject( $client );
        $_GET['client_id'] = $client->client_id;
        ob_start();
        try {
            MainWP_Client::render_header( 'overview' );
        } finally {
            $html = ob_get_clean();
        }
        $this->assertStringContainsString( esc_html( $value ), $html );
        $xpath = $this->xpath( $html );
        $header = $xpath->query( '//h1[contains(@class,"mainwp-page-title")]' )->item( 0 );
        $this->assertNotNull( $header );
        $this->assertStringContainsString( $value, $header->textContent );
        $this->assertSame( 0, $xpath->query( './/img[@onerror] | .//script', $header )->length );
        if ( 'client_email' === $field ) {
            $this->assertStringContainsString( 'href="' . esc_url( 'mailto:' . $value ) . '"', $html );
        }
    }

    public static function update_types(): array {
        return array( array( 'plugins' ), array( 'themes' ) );
    }

    /**
     * @dataProvider update_types
     * @param string $type Update renderer.
     */
    public function test_updates_client_column_preserves_markup_as_text( $type ): void {
        $name = '<img src=x onerror=alert(1)> O\'Brien';
        $client = MainWP_DB_Client::instance()->update_client( array( 'name' => $name, 'client_email' => 'updates@example.com' ), true );
        $slug = 'plugins' === $type ? 'sample/sample.php' : 'sample';
        $this->create_test_site( array( 'client_id' => $client->client_id, ( 'plugins' === $type ? 'plugin_upgrades' : 'theme_upgrades' ) => wp_json_encode( array( $slug => array( 'Name' => 'Sample', 'Version' => '1.0', 'update' => array( 'slug' => 'sample', 'new_version' => '2.0' ) ) ) ) ) );
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $extension = (object) array( 'site_view' => MAINWP_VIEW_PER_PLUGIN_THEME );
        $items = array( $slug => array( 'cnt' => 1 ) );
        $info = array( $slug => array( 'slug' => 'sample', 'name' => 'Sample', 'premium' => false ) );
        ob_start();
        try {
            if ( 'plugins' === $type ) {
                MainWP_Updates_Per_Item::render_plugins_updates( $websites, 1, $extension, $items, $info, array() );
            } else {
                MainWP_Updates_Per_Item::render_themes_updates( $websites, 1, $extension, $items, $info, array() );
            }
        } finally {
            $html = ob_get_clean();
            MainWP_DB::free_result( $websites );
        }
        $xpath = $this->xpath( $html );
        $links = $xpath->query( '//td/a[contains(@href,"page=ManageClients")]' );
        $this->assertSame( 1, $links->length, 'The stored client must reach the Client column.' );
        $this->assertStringContainsString( esc_html( $name ), $html );
        $this->assertSame( $name, $links->item( 0 )->textContent );
        $this->assertSame( 0, $xpath->query( './/img | .//script', $links->item( 0 ) )->length );
        $this->assertSame( 'admin.php?page=ManageClients&client_id=' . $client->client_id, $links->item( 0 )->getAttribute( 'href' ) );
    }

    /** Legacy values must be escaped by the enabled Clients table columns. */
    public function test_clients_list_escapes_core_text_and_social_columns(): void {
        $text_fields = array( 'address_1', 'address_2', 'city', 'zip', 'state', 'country' );
        $social_fields = array( 'client_facebook', 'client_twitter', 'client_instagram', 'client_linkedin' );
        $data = array( 'name' => 'Client', 'client_email' => 'list@example.com' );
        foreach ( $text_fields as $field ) {
            $data[ $field ] = '<img src=x onerror=alert(1)>' . $field;
        }
        foreach ( $social_fields as $field ) {
            $data[ $field ] = 'javascript:alert(1)';
        }
        $client = MainWP_DB_Client::instance()->update_client( $data, true );
        $contact_name = '<script>alert(1)</script>Contact';
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $client->client_id, 'contact_name' => $contact_name, 'contact_email' => 'list-contact@example.com' ) );
        MainWP_DB_Client::instance()->update_client( array( 'client_id' => $client->client_id, 'primary_contact_id' => $contact->contact_id ) );
        $enabled = array_merge( array( 'contact_name' ), $text_fields, $social_fields );
        $columns = static function ( $columns ) use ( $enabled ) { return array_intersect_key( $columns, array_flip( $enabled ) ); };
        add_filter( 'mainwp_clients_sitestable_getcolumns', $columns );
        $table = new MainWP_Client_List_Table();
        ob_start();
        try {
            $table->prepare_items();
            $this->assertCount( 1, $table->items );
            $this->assertSame( $contact_name, $table->items[0]['contact_name'] );
            $table->display_rows();
        } finally {
            $html = ob_get_clean();
            remove_filter( 'mainwp_clients_sitestable_getcolumns', $columns );
            remove_action( 'mainwp_manageclients_tabletop', array( $table, 'generate_tabletop' ) );
        }
        $xpath = $this->xpath( '<table>' . $html . '</table>' );
        foreach ( array_merge( array( 'contact_name' => $contact_name ), array_intersect_key( $data, array_flip( $text_fields ) ) ) as $field => $value ) {
            $cell = $xpath->query( '//td[contains(@class,"column-' . $field . '")]' )->item( 0 );
            $this->assertNotNull( $cell, $field );
            $this->assertStringContainsString( esc_html( $value ), $html, $field );
            $this->assertStringContainsString( $value, $cell->textContent, $field );
            $this->assertSame( 0, $xpath->query( './/img | .//script', $cell )->length, $field );
        }
        foreach ( $social_fields as $field ) {
            $link = $xpath->query( '//td[contains(@class,"column-' . $field . '")]/a' )->item( 0 );
            $this->assertNotNull( $link, $field );
            $this->assertSame( '', $link->getAttribute( 'href' ), $field );
        }
    }

    /** Extension columns retain their HTML contract. */
    public function test_clients_list_keeps_extension_column_html(): void {
        $markup = '<strong>Extension</strong>';
        $filter = static function ( $item ) use ( $markup ) { $item['extension'] = $markup; return $item; };
        add_filter( 'mainwp_clients_sitestable_item', $filter );
        $table = new MainWP_Client_List_Table();
        try {
            $this->assertSame( $markup, $table->column_default( array(), 'extension' ) );
        } finally {
            remove_filter( 'mainwp_clients_sitestable_item', $filter );
            remove_action( 'mainwp_manageclients_tabletop', array( $table, 'generate_tabletop' ) );
        }
    }

    /** @param string $html Rendered markup. @return \DOMXPath Parsed output. */
    private function xpath( $html ): \DOMXPath {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        try {
            $document->loadHTML( '<?xml encoding="UTF-8">' . $html );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
        return new \DOMXPath( $document );
    }
}
