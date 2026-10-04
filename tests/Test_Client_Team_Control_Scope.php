<?php
/**
 * Dashboard client authorization with Team Control site grants.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_Client_List_Table;
use MainWP\Dashboard\MainWP_Clients;
use MainWP\Dashboard\MainWP_Client_Overview;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\Client_Scope_Controller;
use MainWP\Dashboard\Client_Scope_Post_Handler;

require_once __DIR__ . '/fixtures/Refusal_UI.php';

/** AJAX termination must escape the import handlers' Exception catch blocks. */
class Client_Scope_Response_Stop extends \Error {}

/** Exercise UI authorization without the REST manage_options requirement. */
class Test_Client_Team_Control_Scope extends \WP_UnitTestCase {

    use Site_Fixture;
    use Refusal_UI;

    /** @var array Test-owned filters. */
    private $filters = array();

    /** @var array Mutation hook counts. */
    private $hooks = array();

    /** Capture legacy exits while running the source authorization and writes. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array( 'MainWP_Client' => 'Client_Scope_Controller', 'MainWP_Post_Handler' => 'Client_Scope_Post_Handler' );
        foreach ( array( 'pages/page-mainwp-client.php', 'class/class-mainwp-post-handler.php' ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits for baseline tests without editing production.
        }
    }

    /** Legacy secure_request accepts level_10; manage_options remains absent. */
    public function setUp(): void {
        parent::setUp();
        $user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user );
        wp_get_current_user()->add_cap( 'level_10' );
        $this->assertFalse( current_user_can( 'manage_options' ) );
        $_GET = array();
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        foreach ( array( 'mainwp_client_deleted', 'mainwp_client_suspend', 'mainwp_client_updated', 'mainwp_insert_update_client', 'mainwp_update_site' ) as $hook ) {
            $this->hooks[ $hook ] = 0;
            add_action( $hook, array( $this, 'count_mutation' ) );
        }
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        set_current_screen( 'dashboard' );
    }

    /** Restore the shared suite's callbacks and request state. */
    public function tearDown(): void {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        foreach ( array_keys( $this->hooks ) as $hook ) {
            remove_action( $hook, array( $this, 'count_mutation' ) );
        }
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** Count hooks after resetting fixture writes. */
    public function count_mutation(): void {
        ++$this->hooks[ current_filter() ];
    }

    /** @return array Render callbacks. */
    public static function renders(): array {
        return array( array( 'overview' ), array( 'dispatch' ), array( 'edit' ), array( 'header' ) );
    }

    /**
     * @dataProvider renders
     * @param string $render Render entry point.
     */
    public function test_hidden_client_render_refused( $render ): void {
        $client = $this->client();
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $client->client_id, 'contact_name' => 'Secret contact', 'contact_email' => 'secret@example.com' ) );
        $this->scope( array() );
        $_GET['client_id'] = $client->client_id;
        $before = $this->before_write();
        $html = $this->capture( function () use ( $render ) {
            if ( 'overview' === $render ) {
                MainWP_Client_Overview::instance()->on_show_page();
            } elseif ( 'dispatch' === $render ) {
                MainWP_Client::render_manage_clients();
            } elseif ( 'edit' === $render ) {
                MainWP_Client::render_add_client();
            } else {
                MainWP_Client::render_header();
            }
        } );
        $this->assert_unchanged( $before );
        $this->assertStringNotContainsString( $client->name, $html );
        $this->assertStringNotContainsString( $client->client_email, $html );
        $this->assertStringNotContainsString( wp_create_nonce( 'editing-client-' . $client->client_id ), $html );
        $this->assertStringNotContainsString( wp_create_nonce( 'editing-' . $client->client_id . '-contact-' . $contact->contact_id ), $html );
        $this->assertStringContainsString( 'mainwp-permission-error', $html );
    }

    /** No extension widgets or contacts are exposed by the load callback. */
    public function test_hidden_load_registers_no_widgets_or_contact_reads(): void {
        $client = $this->client();
        $this->scope( array() );
        $_GET['client_id'] = $client->client_id;
        $calls = 0;
        $this->filter( 'mainwp_clients_getmetaboxes', static function ( $boxes ) use ( &$calls ) { ++$calls; return $boxes; } );
        $this->filter( 'query', static function ( $sql ) use ( &$calls ) {
            if ( false !== strpos( $sql, 'mainwp_wp_clients_contacts' ) ) {
                ++$calls;
            }
            return $sql;
        } );
        global $wp_meta_boxes;
        $page = 'client-scope-test';
        unset( $wp_meta_boxes[ $page ] );
        $before = $this->before_write();
        $calls = 0;
        MainWP_Client_Overview::on_load_page( $page );
        $this->assertSame( 0, $calls );
        $this->assertEmpty( $wp_meta_boxes[ $page ] ?? array() );
        $this->assert_unchanged( $before );
    }

    /** Partial clients remain visible to authorized users without manage_options. */
    public function test_partial_overview_and_edit_render_allowed(): void {
        $client = $this->client();
        $allowed = $this->site( $client );
        $this->site( $client );
        $this->scope( array( $allowed ) );
        $_GET['client_id'] = $client->client_id;
        $html = $this->capture( static function () { MainWP_Client_Overview::instance()->on_show_page(); } );
        $this->assertStringContainsString( $client->name, $html );
        $html = $this->capture( static function () { MainWP_Client::render_add_client(); } );
        $this->assertStringContainsString( wp_create_nonce( 'editing-client-' . $client->client_id ), $html );
    }

    /** Filter client rows before totals and extension hooks, preserving partial clients. */
    public function test_client_list_filters_scope_before_totals_and_hooks(): void {
        $visible = $this->client();
        $allowed = $this->site( $visible );
        $this->site( $visible );
        $hidden = $this->client();
        $this->site( $hidden );
        $empty = $this->client();
        $this->scope( array( $allowed ) );
        $prepared = array();
        $ids = array();
        $this->filter( 'mainwp_clientstable_prepared_items', static function ( $clients, $client_ids ) use ( &$prepared, &$ids ) { $prepared = $clients; $ids = $client_ids; } );
        $table = new MainWP_Client_List_Table();
        try {
            $table->prepare_items();
            $this->assertSame( array( (int) $visible->client_id ), array_map( 'intval', wp_list_pluck( $table->items, 'client_id' ) ) );
            $this->assertSame( 1, $table->total_items );
            $this->assertSame( $table->items, $prepared );
            $this->assertSame( array( (int) $visible->client_id ), array_map( 'intval', $ids ) );
            $this->assertSame( $this->members( $visible ), array_map( 'intval', explode( ',', $table->items[0]['selected_sites'] ) ) );

            $this->scope( 'all' );
            $expected = MainWP_DB_Client::instance()->get_wp_client_by( 'all', null, ARRAY_A, array( 'with_selected_sites' => true, 'with_tags' => true ) );
            $table->prepare_items();
            $this->assertSame( $expected, $table->items );
            $this->assertSame( count( $expected ), $table->total_items );
            $this->assertSame( $expected, $prepared );
        } finally {
            remove_action( 'mainwp_manageclients_tabletop', array( $table, 'generate_tabletop' ) );
        }
    }

    /** @return array Allowed-site scopes, row shapes and the clients each must keep. */
    public static function viewable_client_cases(): array {
        $restricted = array( 'partial', 'full', 'partial_short_roster' );
        return array(
            'restricted arrays'  => array( 'restricted', 'array', $restricted ),
            'restricted objects' => array( 'restricted', 'object', $restricted ),
            'no allowed sites'   => array( 'none', 'array', array() ),
        );
    }

    /**
     * Visibility comes from the client's real site rows, never the GROUP_CONCAT roster string,
     * which MySQL truncates at group_concat_max_len.
     *
     * @dataProvider viewable_client_cases
     */
    public function test_filter_viewable_clients_matrix( $scope, $shape, $expected ): void {
        $partial      = $this->client();
        $partial_site = $this->site( $partial );
        $this->site( $partial );
        $full      = $this->client();
        $full_site = $this->site( $full );
        $hidden    = $this->client();
        $this->site( $hidden );
        $empty = $this->client();

        $rows = array(
            'partial'              => array( 'client_id' => $partial->client_id, 'selected_sites' => '' ),
            'full'                 => array( 'client_id' => $full->client_id, 'selected_sites' => (string) $full_site ),
            'hidden_forged_roster' => array( 'client_id' => $hidden->client_id, 'selected_sites' => (string) $partial_site ),
            'empty'                => array( 'client_id' => $empty->client_id, 'selected_sites' => '' ),
            'partial_short_roster' => array( 'client_id' => $partial->client_id, 'selected_sites' => substr( (string) $partial_site, 0, 1 ) ),
            'no_client_id'         => array( 'selected_sites' => (string) $partial_site ),
        );
        $input = array();
        foreach ( $rows as $key => $row ) {
            $row['key'] = $key;
            $input[]    = 'object' === $shape ? (object) $row : $row;
        }

        $this->scope( 'restricted' === $scope ? array( $partial_site, $full_site ) : array() );
        $kept = MainWP_Client::filter_viewable_clients( $input );
        $this->assertSame( $expected, array_map( static function ( $row ) { return is_array( $row ) ? $row['key'] : $row->key; }, $kept ) );
        $this->assertSame( array_values( $kept ), $kept );

        $this->scope( 'all' );
        $this->assertSame( $input, MainWP_Client::filter_viewable_clients( $input ) );
        $this->scope( array( $partial_site ) );
        $this->assertFalse( MainWP_Client::filter_viewable_clients( false ) );
    }

    /** Widget output and its hook omit hidden clients; unrestricted output keeps all clients. */
    public function test_clients_widget_filters_scope_before_output_and_hook(): void {
        $visible = $this->client();
        $allowed = $this->site( $visible );
        $this->site( $visible );
        $hidden = $this->client();
        $this->site( $hidden );
        $empty = $this->client();
        $this->scope( array( $allowed ) );
        $hook_clients = array();
        $this->filter( 'mainwp_clients_widget_top', static function ( $clients ) use ( &$hook_clients ) { $hook_clients = $clients; } );
        $html = $this->capture( static function () { MainWP_Clients::render(); } );
        $this->assertStringNotContainsString( $hidden->name, $html );
        $this->assertStringNotContainsString( $hidden->client_email, $html );
        $this->assertStringNotContainsString( $empty->name, $html );
        $this->assertStringNotContainsString( $empty->client_email, $html );
        $this->assertStringContainsString( $visible->name, $html );
        $this->assertStringContainsString( $visible->client_email, $html );
        $this->assertSame( array( (int) $visible->client_id ), array_map( 'intval', wp_list_pluck( $hook_clients, 'client_id' ) ) );

        $this->scope( 'all' );
        $expected = MainWP_DB_Client::instance()->get_wp_clients();
        $html = $this->capture( static function () { MainWP_Clients::render(); } );
        $this->assertSame( $expected, $hook_clients );
        foreach ( array( $visible, $hidden, $empty ) as $client ) {
            $this->assertStringContainsString( $client->name, $html );
            $this->assertStringContainsString( $client->client_email, $html );
        }
    }

    /** Add and import forms enforce the Dashboard mutation right. */
    public function test_add_and_import_forms_require_manage_clients_and_selector_is_scoped(): void {
        $allowed = $this->create_test_site( array( 'name' => 'Visible selector site' ) );
        $this->create_test_site( array( 'name' => 'Hidden selector site' ) );
        $this->scope( array( $allowed ) );
        $html = $this->capture( static function () { MainWP_Client::render_add_client(); } );
        $this->assertStringContainsString( 'Visible selector site', $html );
        $this->assertStringNotContainsString( 'Hidden selector site', $html );
        $this->filter( 'mainwp_currentusercan', '__return_false' );
        foreach ( array( 'render_add_client', 'render_import_clients' ) as $method ) {
            $html = $this->capture( static function () use ( $method ) { MainWP_Client::$method(); } );
            $this->assertStringContainsString( 'mainwp-permission-error', $html );
        }
    }

    /** @return array Scope-restricted operations. */
    public static function mutations(): array {
        $cases = array();
        foreach ( array( 'save', 'delete', 'suspend', 'unsuspend', 'notes', 'field_create', 'field_edit', 'field_delete', 'client_image', 'contact_image', 'csv', 'import', 'import_no_site', 'multi' ) as $operation ) {
            foreach ( array( 'hidden', 'empty', 'right' ) as $restriction ) {
                $cases[ $operation . '-' . $restriction ] = array( $operation, $restriction );
            }
        }
        return $cases;
    }

    /**
     * @dataProvider mutations
     * @param string $operation Mutation entry point.
     * @param string $restriction Scope or right denial.
     */
    public function test_mutation_refused_without_access( $operation, $restriction ): void {
        $client = $this->client();
        $site = 'empty' === $restriction ? 0 : $this->site( $client );
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $client->client_id, 'contact_name' => 'Stored contact', 'contact_email' => 'contact@example.com', 'contact_image' => 'scope-contact.png' ) );
        $field = $this->field( $client->client_id );
        $this->scope( 'right' === $restriction ? 'all' : array() );
        if ( 'right' === $restriction ) {
            $this->filter( 'mainwp_currentusercan', '__return_false' );
        }
        $before = $this->before_write();
        $body = $this->operation( $operation, $client, $site, $field, $contact->contact_id );
        $this->assert_unchanged( $before );
        $this->assertStringContainsString( 'permission', strtolower( $body ), $body );
    }

    /** @return array Full-coverage operations. */
    public static function destructive(): array {
        return array( array( 'delete' ), array( 'suspend' ), array( 'unsuspend' ) );
    }

    /** @return array Client refusal handlers. */
    public static function refusal_handlers(): array {
        return array(
            array( 'delete', 'bulk' ),
            array( 'delete', '.client_deleteitem' ),
            array( 'suspend', '.client-suspend-unsuspend-sites' ),
            array( 'unsuspend', '.client-suspend-unsuspend-sites' ),
        );
    }

    /**
     * @dataProvider refusal_handlers
     * @param string $operation Client operation.
     * @param string $handler Click handler or bulk.
     */
    public function test_client_refusal_reaches_the_ui_as_an_error( $operation, $handler ): void {
        $client = $this->client();
        $site = $this->site( $client );
        $this->site( $client );
        if ( 'unsuspend' === $operation ) {
            MainWP_DB_Client::instance()->update_client( array( 'client_id' => $client->client_id, 'suspended' => 1 ) );
            MainWP_DB_Client::instance()->suspend_unsuspend_websites_by_client_id( $client->client_id, 1 );
        }
        $this->scope( array( $site ) );
        $before = $this->before_write();
        $body = $this->operation( $operation, $client );
        $this->assert_unchanged( $before );
        if ( 'delete' === $operation ) {
            $this->assertSame( 'Error - insufficient permissions!', json_decode( $body, true )['error'] ?? null );
        } else {
            $this->assertSame( 'Error - insufficient permissions!', $body );
        }
        $action = 'mainwp_clients_' . ( 'delete' === $operation ? 'delete' : 'suspend' ) . '_client';
        $this->assert_refusal_ui( 'mainwp-clients.js', $handler, $body, $action );
    }

    /** @return array Batch responses and expected reloads. */
    public static function bulk_delete_completion_cases(): array {
        $refused = array( 'success' => false, 'error' => 'Error - insufficient permissions!' );
        $allowed = array( 'success' => 'SUCCESS' );
        return array(
            'single refusal' => array( array( $refused ), 0, array() ),
            'mixed batch'    => array( array( $allowed, $refused ), 0, array( 'tr[clientid=1]' ) ),
            'all success'    => array( array( $allowed, $allowed ), 1, array( 'tr[clientid=1]', 'tr[clientid=2]' ) ),
        );
    }

    /**
     * @dataProvider bulk_delete_completion_cases
     * @param array $responses Batch responses.
     * @param int   $reloads Expected reloads.
     * @param array $fades Expected removed rows.
     */
    public function test_bulk_delete_completion_keeps_failed_rows( $responses, $reloads, $fades ): void {
        $ui = $this->run_refusal_ui( 'mainwp-clients.js', 'bulk', wp_json_encode( $responses ) );
        $this->assertSame( count( $responses ), $ui['finished'], 'Every row must reach completion.' );
        $this->assertSame( array_fill( 0, count( $responses ), 'mainwp_clients_delete_client' ), $ui['posts'] );
        $this->assertSame( $fades, $ui['fades'], 'Only deleted rows may fade out.' );
        $this->assertSame( $reloads, $ui['reloads'], 'A failed row must prevent the completion reload.' );
        if ( 0 === $reloads ) {
            $errors = array_filter( $ui['messages'], static function ( $message ) {
                return false !== strpos( $message['text'] ?? '', 'Error - insufficient permissions!' ) && false !== strpos( $message['style'] ?? '', 'red' );
            } );
            $this->assertNotEmpty( $errors, 'The refused row must retain its red error.' );
        }
    }

    /** @return array Site client dropdowns. */
    public static function site_dropdowns(): array {
        return array( array( 'edit' ), array( 'add' ) );
    }

    /**
     * @dataProvider site_dropdowns
     * @param string $page Site form.
     */
    public function test_site_client_dropdown_keeps_current_and_overlapping_clients( $page ): void {
        $current = $this->client();
        $site = $this->site( $current );
        $overlap = $this->client();
        $other_site = $this->site( $overlap );
        $this->site( $overlap );
        $hidden = $this->client();
        $this->site( $hidden );
        $empty = $this->client();
        $this->scope( array( $site, $other_site ) );
        $this->assertSame( array( 0, (int) $current->client_id, (int) $overlap->client_id ), $this->site_client_options( $page, $site, $current->client_id ) );
        $this->scope( 'all' );
        $this->assertSame( array( 0, (int) $current->client_id, (int) $overlap->client_id, (int) $hidden->client_id, (int) $empty->client_id ), $this->site_client_options( $page, $site, $current->client_id ) );
    }

    /** @param string $page Site form. @param int $site Site ID. @param int $current Current client. @return array Sorted client options. */
    private function site_client_options( $page, $site, $current ): array {
        $html = $this->capture( static function () use ( $page, $site ) {
            if ( 'edit' === $page ) {
                MainWP_Manage_Sites_View::render_edit_site( $site, false );
            } else {
                MainWP_Manage_Sites::render_new_site_add_new_site( array() );
            }
        } );
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        try {
            $this->assertTrue( $document->loadHTML( $html ) );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
        $xpath = new \DOMXPath( $document );
        if ( 'edit' === $page ) {
            $input = $xpath->query( '//input[@name="mainwp_managesites_edit_client_id"]' )->item( 0 );
            $this->assertNotNull( $input );
            $this->assertSame( (string) $current, $input->getAttribute( 'value' ), 'Current client must remain selected.' );
            $query = '//input[@name="mainwp_managesites_edit_client_id"]/parent::div/div[@class="menu"]/div[@class="item"]';
        } else {
            $query = '//div[@id="mainwp_managesites_add_client_id"]/div[@class="menu"]/div[@class="item"]';
        }
        $ids = array();
        foreach ( $xpath->query( $query ) as $option ) {
            $ids[] = (int) $option->getAttribute( 'data-value' );
        }
        sort( $ids );
        return $ids;
    }

    /**
     * @dataProvider destructive
     * @param string $operation Operation requiring full coverage.
     */
    public function test_destructive_partial_refused_full_allowed( $operation ): void {
        $client = $this->client();
        $first = $this->site( $client );
        $second = $this->site( $client );
        if ( 'unsuspend' === $operation ) {
            MainWP_DB_Client::instance()->update_client( array( 'client_id' => $client->client_id, 'suspended' => 1 ) );
            MainWP_DB_Client::instance()->suspend_unsuspend_websites_by_client_id( $client->client_id, 1 );
        }
        $this->scope( array( $first ) );
        $before = $this->before_write();
        $body = $this->operation( $operation, $client );
        $this->assert_unchanged( $before );
        $this->assertStringContainsString( 'permission', strtolower( $body ) );
        $this->scope( array( $first, $second ) );
        $body = $this->operation( $operation, $client );
        if ( 'delete' === $operation ) {
            $this->assertSame( 'SUCCESS', json_decode( $body, true )['success'] );
            $this->assertEmpty( MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id ) );
        } else {
            $this->assertSame( 'success', $body );
            $status = 'suspend' === $operation ? 1 : 0;
            $this->assertSame( $status, (int) MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id )->suspended );
            $this->assertSame( $status, (int) MainWP_DB::instance()->get_website_by_id( $second )->suspended );
        }
    }

    /** Partial saves can replace visible memberships, never hidden ones. */
    public function test_partial_save_keeps_hidden_sites_and_refuses_hidden_submission(): void {
        $client = $this->client();
        $visible = $this->site( $client );
        $hidden = $this->site( $client );
        $new_site = $this->create_test_site();
        $this->scope( array( $visible, $new_site ) );
        $body = $this->save( $client, array( $new_site ) );
        $this->assertSame( 'yes', json_decode( $body, true )['success'] );
        $this->assertSame( array( $hidden, $new_site ), $this->members( $client ) );
        $before = $this->before_write();
        $this->assertStringContainsString( 'permission', $this->save( $client, array( $hidden ) ) );
        $this->assert_unchanged( $before );
        $before = $this->before_write();
        $this->assertStringContainsString( 'permission', $this->save( $client, array( $new_site ), array( 'client.suspended' => 1 ) ) );
        $this->assert_unchanged( $before );
    }

    /** @return array Stored ownership cases. */
    public static function foreign_records(): array {
        return array( array( 'primary' ), array( 'field' ), array( 'contact' ) );
    }

    /**
     * @dataProvider foreign_records
     * @param string $record Foreign record type.
     */
    public function test_foreign_record_save_refused_before_any_write( $record ): void {
        $client = $this->client();
        $foreign = $this->client();
        $site = $this->site( $client );
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $foreign->client_id, 'contact_name' => 'Foreign', 'contact_email' => 'foreign@example.com' ) );
        $field = $this->field( $foreign->client_id );
        $this->scope( array( $site ) );
        $fields = array();
        $defaults = array();
        if ( 'primary' === $record ) {
            $defaults['primary_contact_id'] = $contact->contact_id;
        } elseif ( 'field' === $record ) {
            $fields['custom_fields'] = array( array( $field => 'Foreign value' ) );
        } else {
            $fields['contacts_field'] = array( 'client.contact.name' => array( 'Hijacked' ), 'contact.email' => array( 'hijacked@example.com' ), 'contact_id' => array( $contact->contact_id ), 'nonce_contact_id' => array( wp_create_nonce( 'editing-' . $client->client_id . '-contact-' . $contact->contact_id ) ) );
            foreach ( array( 'contact.phone', 'contact.role', 'contact.facebook', 'contact.twitter', 'contact.instagram', 'contact.linkedin', 'selected_icon', 'selected_color' ) as $key ) {
                $fields['contacts_field'][ $key ] = array( '' );
            }
        }
        $before = $this->before_write();
        $body = $this->save( $client, array( $site ), $defaults, $fields );
        $this->assert_unchanged( $before );
        $this->assertStringContainsString( 'permission', strtolower( $body ) );
    }

    /** Global fields retain their owner and existing permission behavior. */
    public function test_field_edit_keeps_stored_owner_and_global_fields_unchanged(): void {
        $client = $this->client();
        $this->scope( array( $this->site( $client ) ) );
        $field = $this->field( $client->client_id );
        $body = $this->dispatch( 'mainwp_clients_save_field', array( 'client_id' => 0, 'field_id' => $field, 'field_name' => 'renamed', 'field_desc' => 'edited' ) );
        $this->assertTrue( json_decode( $body, true )['success'] );
        $this->assertSame( (int) $client->client_id, (int) MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field )->client_id );
        $global = $this->field( 0 );
        $this->scope( array() );
        $this->filter( 'mainwp_currentusercan', '__return_false' );
        $body = $this->dispatch( 'mainwp_clients_save_field', array( 'client_id' => $client->client_id, 'field_id' => $global, 'field_name' => 'global-renamed', 'field_desc' => 'edited' ) );
        $this->assertTrue( json_decode( $body, true )['success'] );
        $this->assertSame( 0, (int) MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $global )->client_id );
    }

    /** A supplied visible parent cannot authorize a hidden field's deletion. */
    public function test_field_delete_resolves_stored_owner(): void {
        $visible = $this->client();
        $hidden = $this->client();
        $this->scope( array( $this->site( $visible ) ) );
        $field = $this->field( $hidden->client_id );
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_delete_field', array( 'client_id' => $visible->client_id, 'field_id' => $field ) );
        $this->assert_unchanged( $before );
        $this->assertStringContainsString( 'permission', strtolower( $body ) );
    }

    /** The contact, primary contact and both field kinds can be saved in scope. */
    public function test_owned_contact_and_field_values_allowed(): void {
        $client = $this->client();
        $site = $this->site( $client );
        $hidden = $this->site( $client );
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $client->client_id, 'contact_name' => 'Contact', 'contact_email' => 'contact@example.com' ) );
        $field = $this->field( $client->client_id );
        $global = $this->field( 0 );
        $fields = array(
            'custom_fields' => array( array( $field => 'Owned value' ), array( $global => 'Global value' ) ),
            'contacts_field' => array( 'client.contact.name' => array( 'Edited contact' ), 'contact.email' => array( 'contact@example.com' ), 'contact_id' => array( $contact->contact_id ), 'nonce_contact_id' => array( wp_create_nonce( 'editing-' . $client->client_id . '-contact-' . $contact->contact_id ) ) ),
        );
        foreach ( array( 'contact.phone', 'contact.role', 'contact.facebook', 'contact.twitter', 'contact.instagram', 'contact.linkedin', 'selected_icon', 'selected_color' ) as $key ) {
            $fields['contacts_field'][ $key ] = array( '' );
        }
        $this->scope( array( $site ) );
        $body = $this->save( $client, array( $site ), array( 'primary_contact_id' => $contact->contact_id ), $fields );
        $this->assertSame( 'yes', json_decode( $body, true )['success'] );
        $this->assertSame( 'Edited contact', MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $contact->contact_id )->contact_name );
        $this->assertSame( (int) $contact->contact_id, (int) MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id )->primary_contact_id );
        $this->assertContains( $hidden, $this->members( $client ) );
        global $wpdb;
        $this->assertSame( 'Owned value', $wpdb->get_var( $wpdb->prepare( "SELECT field_value FROM {$wpdb->prefix}mainwp_wp_clients_field_values WHERE field_id = %d AND value_client_id = %d", $field, $client->client_id ) ) );
        $this->assertSame( 'Global value', $wpdb->get_var( $wpdb->prepare( "SELECT field_value FROM {$wpdb->prefix}mainwp_wp_clients_field_values WHERE field_id = %d AND value_client_id = %d", $global, $client->client_id ) ) );
    }

    /** Unknown edit and suspend targets cannot create a client row. */
    public function test_unknown_client_save_and_suspend_refused(): void {
        $client = (object) array( 'client_id' => 99999999 );
        foreach ( array( 'save', 'suspend' ) as $operation ) {
            $before = $this->before_write();
            $body = $this->operation( $operation, $client );
            $this->assert_unchanged( $before );
            $this->assertStringContainsString( 'permission', strtolower( $body ) );
        }
    }

    /** @return array In-scope ordinary mutations. */
    public static function ordinary(): array {
        return array_map( static function ( $operation ) { return array( $operation ); }, array( 'notes', 'field_create', 'field_edit', 'field_delete', 'client_image', 'contact_image', 'csv', 'import', 'multi' ) );
    }

    /**
     * @dataProvider ordinary
     * @param string $operation Ordinary mutation entry point.
     */
    public function test_partial_metadata_and_import_allowed( $operation ): void {
        $client = $this->client();
        $site = $this->site( $client );
        $hidden = $this->site( $client );
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => $client->client_id, 'contact_name' => 'Contact', 'contact_email' => 'contact@example.com', 'contact_image' => 'scope-contact.png' ) );
        $field = $this->field( $client->client_id );
        $this->scope( array( $site ) );
        $body = $this->operation( $operation, $client, $site, $field, $contact->contact_id );
        $json = json_decode( $body, true );
        $this->assertIsArray( $json, $body );
        $this->assertEmpty( $json['error'] ?? '' );
        $this->assertTrue( ! empty( $json['success'] ) || in_array( $json['result'] ?? '', array( 'success', 'SUCCESS' ), true ), $body );
        $this->assertContains( $hidden, $this->members( $client ) );
        if ( 'notes' === $operation ) {
            $this->assertSame( 'Edited note', MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id )->note );
        } elseif ( 'field_delete' === $operation ) {
            $this->assertEmpty( MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field ) );
        } elseif ( 'field_edit' === $operation ) {
            $this->assertSame( 'edited-field', MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field )->field_name );
        } elseif ( 'field_create' === $operation ) {
            $this->assertIsObject( MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', 'edited-field', $client->client_id ) );
        } elseif ( 'client_image' === $operation ) {
            $this->assertSame( '', MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id )->image );
        } elseif ( 'contact_image' === $operation ) {
            $this->assertSame( '', MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $contact->contact_id )->contact_image );
        }
    }

    /** No-site import keeps its legacy response after successfully creating a row. */
    public function test_no_site_import_allowed_and_existing_partial_client_kept(): void {
        $client = $this->client();
        $visible = $this->site( $client );
        $hidden = $this->site( $client );
        $this->scope( array( $visible ) );
        $before = $this->before_write();
        $body = $this->operation( 'import_no_site', $client );
        $this->assertStringNotContainsString( 'permission', strtolower( $body ) );
        $this->assert_unchanged( $before );
        $this->assertContains( $hidden, $this->members( $client ) );
        $new = (object) array( 'client_id' => 0, 'name' => 'No site client', 'client_email' => 'no-site@example.com' );
        $this->operation( 'import_no_site', $new );
        $this->assertIsObject( MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', $new->client_email ) );
    }

    /** A fully covered edit can change suspension without manage_options. */
    public function test_full_edit_suspension_allowed_and_zero_grants_keep_hidden_roster(): void {
        $client = $this->client();
        $site = $this->site( $client );
        $this->scope( array( $site ) );
        $this->assertSame( 'yes', json_decode( $this->save( $client, array( $site ), array( 'client.suspended' => 1 ) ), true )['success'] );
        $this->assertSame( 1, (int) MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client->client_id )->suspended );
        $this->scope( array() );
        $this->assertSame( array( $site ), array_map( 'intval', \MainWP\Dashboard\MainWP_System::get_selected_sites_with_allowed_sites( array(), array( $site ) ) ) );
    }

    /** Site-side assignment allows a client already in scope. */
    public function test_site_edit_and_add_allow_in_scope_target(): void {
        $client = $this->client();
        $member = $this->site( $client );
        $site = $this->create_test_site();
        $this->scope( array( $member, $site ) );
        $_POST = array( 'submit' => 'Save', 'wp_nonce' => wp_create_nonce( 'UpdateWebsite' . $site ), 'mainwp_managesites_edit_siteadmin' => 'admin', 'mainwp_managesites_edit_sitename' => 'Changed', 'mainwp_managesites_edit_client_id' => $client->client_id );
        $_REQUEST = array( 'id' => $site );
        $method = new \ReflectionMethod( MainWP_Manage_Sites::class, 'update_site_handle' );
        $method->setAccessible( true );
        $this->assertTrue( $method->invoke( null, MainWP_DB::instance()->get_website_by_id( $site ) ) );
        $this->assertSame( (int) $client->client_id, (int) MainWP_DB::instance()->get_website_by_id( $site )->client_id );
        $this->filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
        $this->filter( 'mainwp_fetch_url_not_authed_pre', static function () { return array( 'register' => 'OK' ); } );
        $this->filter( 'mainwp_fetch_url_authed_pre', static function () { return array(); } );
        // Team Control grants newly created sites to their creator before sync.
        $this->filter( 'mainwp_currentuserallowedaccesssites', static function () use ( $member, $site ) {
            global $wpdb;
            $new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url = %s", 'https://client-scope-allowed.example.com/' ) );
            return $new ? array( $member, $site, $new ) : array( $member, $site );
        } );
        $_POST = array( 'managesites_add_wpurl' => 'https://client-scope-allowed.example.com/', 'managesites_add_wpname' => 'New site', 'managesites_add_wpadmin' => 'admin', 'clientid' => $client->client_id );
        $result = MainWP_Manage_Sites_View::add_site();
        $this->assertSame( '', $result[1] );
        $this->assertGreaterThan( 0, (int) $result[2] );
        $this->assertSame( (int) $client->client_id, (int) MainWP_DB::instance()->get_website_by_id_unscoped( $result[2] )->client_id );
    }

    /** New client paths cannot attach hidden sites, even before the client exists. */
    public function test_new_client_and_import_refuse_hidden_site_before_insert(): void {
        $hidden_site = $this->create_test_site();
        $this->scope( array() );
        $client = (object) array( 'client_id' => 0, 'name' => 'New Client', 'client_email' => 'new@example.com' );
        foreach ( array( 'save', 'csv', 'import', 'multi' ) as $operation ) {
            $before = $this->before_write();
            $body = $this->operation( $operation, $client, $hidden_site );
            $this->assert_unchanged( $before );
            $this->assertStringContainsString( 'permission', strtolower( $body ) );
        }
    }

    /** @return array New-client entry points that attach sites. */
    public static function new_clients(): array {
        return array( array( 'save' ), array( 'csv' ), array( 'import' ), array( 'multi' ) );
    }

    /**
     * @dataProvider new_clients
     * @param string $operation New-client entry point.
     */
    public function test_new_client_paths_attach_only_allowed_sites( $operation ): void {
        $site = $this->create_test_site();
        $this->scope( array( $site ) );
        $client = (object) array( 'client_id' => 0, 'name' => 'New client', 'client_email' => 'new@example.com' );
        $body = $this->operation( $operation, $client, $site );
        $json = json_decode( $body, true );
        $this->assertIsArray( $json, $body );
        $this->assertNotEmpty( $json['success'], $body );
        $saved_site = MainWP_DB::instance()->get_website_by_id( $site );
        $this->assertGreaterThan( 0, (int) $saved_site->client_id );
        $this->assertIsObject( MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $saved_site->client_id ) );
    }

    /** Site-side client assignment must refuse before the site's other fields change. */
    public function test_site_edit_and_add_refuse_hidden_target_client(): void {
        $client = $this->client();
        $this->site( $client );
        $site = $this->create_test_site();
        $this->scope( array( $site ) );
        $_POST = array( 'submit' => 'Save', 'wp_nonce' => wp_create_nonce( 'UpdateWebsite' . $site ), 'mainwp_managesites_edit_siteadmin' => 'admin', 'mainwp_managesites_edit_sitename' => 'Changed', 'mainwp_managesites_edit_client_id' => $client->client_id );
        $_REQUEST = array( 'id' => $site );
        $before = $this->before_write();
        $method = new \ReflectionMethod( MainWP_Manage_Sites::class, 'update_site_handle' );
        $method->setAccessible( true );
        $this->assertFalse( $method->invoke( null, MainWP_DB::instance()->get_website_by_id( $site ) ) );
        $this->assert_unchanged( $before );
        $this->filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
        $register_calls = 0;
        $this->filter( 'mainwp_fetch_url_not_authed_pre', static function () use ( &$register_calls ) { ++$register_calls; return array( 'register' => 'OK' ); } );
        $this->filter( 'mainwp_fetch_url_authed_pre', static function () { return array(); } );
        $_POST = array( 'managesites_add_wpurl' => 'https://client-scope-add.example.com/', 'managesites_add_wpname' => 'New site', 'managesites_add_wpadmin' => 'admin', 'clientid' => $client->client_id );
        $before = $this->before_write();
        $result = MainWP_Manage_Sites_View::add_site();
        $this->assertSame( 0, $register_calls, 'Refused clients must not reach Child registration.' );
        $this->assert_unchanged( $before );
        $this->assertSame( 0, (int) $result[2] );
        $this->assertStringContainsString( 'permission', strtolower( $result[1] ) );
    }

    /** @return array Rows whose refusal must prevent earlier writes. */
    public static function refused_multi_client_rows(): array {
        return array( array( 'hidden site' ), array( 'missing site' ), array( 'hidden existing email' ), array( 'empty existing client' ) );
    }

    /**
     * @dataProvider refused_multi_client_rows
     * @param string $reason Refused second row.
     */
    public function test_multi_client_preflight_refuses_entire_batch( $reason ): void {
        $allowed = $this->create_test_site();
        $hidden = $this->client();
        $hidden_site = $this->site( $hidden );
        $empty = $this->client();
        $this->scope( array( $allowed, 99999999 ) );
        $row = array( 'client_name' => 'Refused', 'client_email' => 'refused@example.com', 'website_id' => $hidden_site );
        if ( 'missing site' === $reason ) {
            $row['website_id'] = 99999999;
        } elseif ( 'hidden existing email' === $reason || 'empty existing client' === $reason ) {
            $row['client_email'] = 'hidden existing email' === $reason ? $hidden->client_email : $empty->client_email;
            $row['website_id'] = $allowed;
        }
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array(
            array( 'client_name' => 'Allowed', 'client_email' => 'allowed@example.com', 'website_id' => $allowed, 'contacts_field' => array( 'contact_name' => 'Contact', 'contact_email' => 'contact@example.com' ) ),
            $row,
        ) ) );
        $this->assertSame( array( 'success' => false, 'data' => 'Error - insufficient permissions!' ), json_decode( $body, true ), $body );
        $this->assert_unchanged( $before );
    }

    /** @return array Allowed scope modes. */
    public static function allowed_multi_client_scopes(): array {
        return array( array( 'restricted' ), array( 'admin' ) );
    }

    /**
     * @dataProvider allowed_multi_client_scopes
     * @param string $scope Caller scope.
     */
    public function test_multi_client_preflight_keeps_allowed_batch( $scope ): void {
        $existing = $this->client();
        $member = $this->site( $existing );
        $hidden = $this->site( $existing );
        $first = $this->create_test_site();
        $second = $this->create_test_site();
        if ( 'admin' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
            $this->scope( 'all' );
        } else {
            $this->scope( array( $member, $first, $second ) );
        }
        $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array(
            array( 'client_name' => 'New batch client', 'client_email' => 'batch@example.com', 'website_id' => $first, 'contacts_field' => array( 'contact_name' => 'Batch contact', 'contact_email' => 'batch-contact@example.com' ) ),
            array( 'client_name' => 'Keep existing name', 'client_email' => $existing->client_email, 'website_id' => $second ),
        ) ) );
        $this->assertSame( array( 'success' => true, 'data' => 'Created client successfully.' ), json_decode( $body, true ), $body );
        $new = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', 'batch@example.com' );
        $this->assertIsObject( $new );
        $this->assertSame( 'New batch client', $new->name );
        $this->assertSame( array( $first ), $this->members( $new ) );
        $contact = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_email', 'batch-contact@example.com' );
        $this->assertIsObject( $contact );
        $this->assertSame( (int) $new->client_id, (int) $contact->contact_client_id );
        $this->assertSame( $existing->name, MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $existing->client_id )->name );
        $this->assertContains( $second, $this->members( $existing ) );
        $this->assertContains( $hidden, $this->members( $existing ) );
    }

    /** @return array Missing-client entry points. */
    public static function missing_client_entry_points(): array {
        return array( array( 'add form' ), array( 'delete' ), array( 'orphan contact image' ) );
    }

    /**
     * @dataProvider missing_client_entry_points
     * @param string $entry Entry point.
     */
    public function test_missing_client_refusal_has_no_incorrect_usage( $entry ): void {
        $this->scope( array() );
        $contact = MainWP_DB_Client::instance()->update_client_contact( array( 'contact_client_id' => 0, 'contact_name' => 'Orphan', 'contact_email' => 'orphan@example.com', 'contact_image' => 'scope-contact.png' ) );
        $incorrect = 0;
        $this->filter( 'doing_it_wrong_run', static function () use ( &$incorrect ) { ++$incorrect; } );
        $before = $this->before_write();
        if ( 'add form' === $entry ) {
            $_GET = array( 'page' => 'ClientAddNew', 'client_id' => 0 );
            $body = $this->capture( static function () { MainWP_Client::render_add_client(); } );
            $this->assertStringContainsString( 'mainwp-permission-error', $body );
        } elseif ( 'delete' === $entry ) {
            $body = $this->dispatch( 'mainwp_clients_delete_client', array() );
            $this->assertSame( 'Error - insufficient permissions!', json_decode( $body, true )['error'] ?? null );
        } else {
            $body = $this->operation( 'contact_image', (object) array( 'client_id' => 0 ), 0, 0, $contact->contact_id );
            $this->assertSame( 'Error - insufficient permissions!', json_decode( $body, true )['error'] ?? null );
        }
        $this->assertSame( 0, $incorrect, 'A missing client must refuse without _doing_it_wrong.' );
        $this->assert_unchanged( $before );
    }

    /** Invalid IDs must not reach the roster, even with unrestricted access. */
    public function test_missing_client_access_skips_roster(): void {
        foreach ( array( array(), 'all' ) as $scope ) {
            $this->scope( $scope );
            $queries = 0;
            $this->filter( 'query', static function ( $sql ) use ( &$queries ) { ++$queries; return $sql; } );
            $this->assertFalse( MainWP_Client::has_client_access( 0 ) );
            $this->assertFalse( MainWP_Client::has_client_access( -1 ) );
            $this->assertSame( 0, $queries, 'Invalid client IDs must not read a roster.' );
        }
    }

    /** Admins retain zero-site client behavior. */
    public function test_unrestricted_admin_zero_site_save_and_notes_allowed(): void {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $client = $this->client();
        $this->assertSame( 'yes', json_decode( $this->save( $client ), true )['success'] );
        $this->assertSame( 'SUCCESS', json_decode( $this->operation( 'notes', $client ), true )['result'] );
    }

    /** @return object Client fixture. */
    private function client() {
        return MainWP_DB_Client::instance()->update_client( array( 'name' => 'Private client ' . wp_generate_uuid4(), 'client_email' => wp_generate_uuid4() . '@example.com', 'image' => 'scope-client.png', 'suspended' => 0, 'created' => time() ), true );
    }

    /** @param object $client Client. @return int Site ID. */
    private function site( $client ): int {
        return $this->create_test_site( array( 'client_id' => $client->client_id ) );
    }

    /** @param int $client Client ID. @return int Field ID. */
    private function field( $client ): int {
        return (int) MainWP_DB_Client::instance()->add_client_field( array( 'client_id' => $client, 'field_name' => 'field-' . wp_generate_uuid4(), 'field_desc' => 'Fixture' ) )->field_id;
    }

    /** @param object $client Client. @return array Complete roster. */
    private function members( $client ): array {
        $ids = array_map( 'intval', wp_list_pluck( MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id, array( 'no_perm_check' => true ) ), 'id' ) );
        sort( $ids );
        return $ids;
    }

    /** @param mixed $sites Allowed site IDs or all. */
    private function scope( $sites ): void {
        $this->filter( 'mainwp_currentuserallowedaccesssites', static function () use ( $sites ) { return $sites; } );
    }

    /** @param string $hook Filter name. @param callable $callback Callback. */
    private function filter( $hook, $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /** @return array Client, contact, field, value and site rows. */
    private function snapshot(): array {
        global $wpdb;
        $rows = array();
        foreach ( array( 'wp_clients', 'wp_clients_contacts', 'wp_clients_fields', 'wp_clients_field_values', 'wp' ) as $table ) {
            $rows[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'mainwp_' . $table, ARRAY_A );
        }
        return $rows;
    }

    /** @return array State before a write, excluding fixture hooks. */
    private function before_write(): array {
        $this->hooks = array_fill_keys( array_keys( $this->hooks ), 0 );
        return $this->snapshot();
    }

    /** @param array $before Original DB state. */
    private function assert_unchanged( $before ): void {
        $this->assertSame( $before, $this->snapshot(), 'Refusal changed database rows.' );
        $this->assertSame( array_fill_keys( array_keys( $this->hooks ), 0 ), $this->hooks, 'Refusal fired a mutation hook.' );
    }

    /** @param callable $callback Request. @return string Captured response. */
    private function capture( $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Client_Scope_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /** @return callable AJAX termination callback. */
    public function ajax_die_handler(): callable {
        return static function ( $message ) { throw new Client_Scope_Response_Stop( (string) $message ); };
    }

    /** @param string $action AJAX action. @param array $post POST fields. @return string Response. */
    private function dispatch( $action, $post ): string {
        $_POST = array_merge( $post, array( 'security' => wp_create_nonce( $action ) ) );
        $_REQUEST = $_POST;
        return $this->capture( static function () use ( $action ) {
            $methods = array( 'mainwp_import_website_add_client' => 'ajax_import_website_add_client', 'mainwp_import_website_add_client_no_site' => 'ajax_import_website_add_client_no_site', 'mainwp_clients_add_multi_client' => 'ajax_clients_add_multi_client' );
            if ( 'mainwp_add_edit_client_upload_client_icon' === $action ) {
                Client_Scope_Controller::ajax_upload_client_icon();
            } elseif ( 'mainwp_add_edit_contact_upload_contact_icon' === $action ) {
                Client_Scope_Controller::ajax_upload_contact_icon();
            } else {
                $method = $methods[ $action ] ?? $action;
                Client_Scope_Post_Handler::instance()->$method();
            }
        } );
    }

    /** @param object $client Client. @param array $sites Sites. @param array $defaults Metadata. @param array $fields Other fields. @return string Response. */
    private function save( $client, $sites = array(), $defaults = array(), $fields = array() ): string {
        return $this->dispatch( 'mainwp_clients_add_client', array( 'selected_sites' => $sites, 'nonce_client_id' => wp_create_nonce( 'editing-client-' . $client->client_id ), 'client_fields' => array_merge( $fields, array( 'client_id' => $client->client_id, 'default_field' => array_merge( array( 'client.name' => 'Edited client' ), $defaults ) ) ) ) );
    }

    /** @param string $operation Operation. @param object $client Client. @param int $site Site. @param int $field Field. @param int $contact Contact. @return string Response. */
    private function operation( $operation, $client, $site = 0, $field = 0, $contact = 0 ): string {
        $id = $client->client_id;
        if ( 'save' === $operation ) {
            return $this->save( $client, $site ? array( $site ) : array() );
        }
        if ( in_array( $operation, array( 'delete', 'suspend', 'unsuspend', 'notes' ), true ) ) {
            $action = 'notes' === $operation ? 'mainwp_clients_notes_save' : 'mainwp_clients_' . ( 'delete' === $operation ? 'delete' : 'suspend' ) . '_client';
            return $this->dispatch( $action, array( 'clientid' => $id, 'suspend_status' => 'suspend' === $operation ? 1 : 0, 'note' => 'Edited note' ) );
        }
        if ( 0 === strpos( $operation, 'field_' ) ) {
            return $this->dispatch( 'field_delete' === $operation ? 'mainwp_clients_delete_field' : 'mainwp_clients_save_field', array( 'client_id' => $id, 'field_id' => 'field_create' === $operation ? 0 : $field, 'field_name' => 'edited-field', 'field_desc' => 'Edited description' ) );
        }
        if ( false !== strpos( $operation, '_image' ) ) {
            $kind = 'client_image' === $operation ? 'client' : 'contact';
            $slug = 'scope-' . $kind . '.png';
            return $this->dispatch( 'mainwp_add_edit_' . $kind . '_upload_' . $kind . '_icon', array( 'delete' => 1, 'iconItemId' => 'client' === $kind ? $id : $contact, 'iconFileSlug' => $slug, 'delnonce' => \MainWP\Dashboard\MainWP_System_Utility::get_custom_nonce( $kind, $slug ) ) );
        }
        if ( 'csv' === $operation ) {
            $urls = $site ? array( MainWP_DB::instance()->get_website_by_id_unscoped( $site )->url ) : array();
            return $this->dispatch( 'mainwp_clients_import_client', array( 'name' => 'Imported client', 'email' => $client->client_email, 'urls' => $urls ) );
        }
        if ( 'multi' === $operation ) {
            return $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array( array( 'client_name' => 'Imported client', 'client_email' => $client->client_email, 'website_id' => $site ) ) ) );
        }
        $data = wp_json_encode( array( 'name' => 'Imported client', 'client_email' => $client->client_email ) );
        return $this->dispatch( 'mainwp_import_website_add_client' . ( 'import_no_site' === $operation ? '_no_site' : '' ), array( 'client' => 'import_no_site' === $operation ? array( $data ) : $data, 'site_id' => $site ) );
    }
}
