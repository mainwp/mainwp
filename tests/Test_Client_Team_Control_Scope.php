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
            'missing id'     => array( array( array( 'success' => false, 'result' => 'Client ID empty.' ) ), 0, array() ),
            'message only'   => array( array( array( 'success' => false, 'message' => 'Delete failed.' ) ), 0, array() ),
            'no message'     => array( array( array( 'success' => false ) ), 0, array() ),
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
            foreach ( $responses as $response ) {
                if ( 'SUCCESS' === ( $response['success'] ?? null ) ) {
                    continue;
                }
                $expected = $response['error'] ?? $response['result'] ?? $response['message'] ?? 'Undefined error. Please try again.';
                $errors = array_filter( $ui['messages'], static function ( $message ) use ( $expected ) {
                    return false !== strpos( $message['text'] ?? '', $expected ) && false !== strpos( $message['style'] ?? '', 'red' );
                } );
                $this->assertNotEmpty( $errors, 'The failed row must retain its red error: ' . $expected );
            }
        }
    }

    /** Quick Setup must show the server's refusal and remove its pending status. */
    public function test_multi_client_ui_clears_status_on_refusal(): void {
        $ui = $this->run_refusal_ui( 'mainwp-clients.js', '#bulk_add_multi_create_client', wp_json_encode( array( 'success' => false, 'data' => 'Error - insufficient permissions!' ) ) );
        $this->assertSame( array( 'mainwp_clients_add_multi_client' ), $ui['posts'] );
        $this->assertCount( 1, $ui['requests'][0]['data'], 'The click handler must collect a valid form row.' );
        $this->assertSame( 'client@example.com', $ui['requests'][0]['data'][0]['client_email'] );
        $errors = array_filter( $ui['messages'], static function ( $message ) {
            return '#mainwp-message-zone' === $message['target'] && 'red' === $message['style'] && 'Error - insufficient permissions!' === $message['text'];
        } );
        $this->assertNotEmpty( $errors, 'The server refusal must be visible in red.' );
        $statuses = array_values( array_filter( $ui['messages'], static function ( $message ) { return '#mainwp-message-zone-client' === $message['target']; } ) );
        $this->assertSame( '', end( $statuses )['text'], 'A failure must clear the pending status.' );
        $this->assertTrue( end( $statuses )['helper'] ?? false, 'The pending status must be cleared through mainwp_set_message_zone(), which also hides the zone.' );
        $this->assertSame( 0, $ui['reloads'] );
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

    /**
     * Duplicate-email matrix, with every supplied site allowed:
     * New/new site then no site: restricted and unrestricted merge.
     * New/new no site then site, or neither with a site: restricted refuses before writes;
     * unrestricted merges. New/new with two sites: both scopes merge both memberships.
     * Existing DB email in scope: both scopes merge. Existing DB email out of scope:
     * restricted refuses before writes; unrestricted merges.
     * Email normalization precedes the column's collation comparison, so removable
     * accents and padding cannot bypass the projected ownership checks.
     *
     * @return array Scope, initial client, row memberships and expected refusal.
     */
    public static function duplicate_multi_client_emails(): array {
        $cases = array();
        foreach ( array( 'restricted', 'all' ) as $scope ) {
            foreach ( array( 'site then none' => array( true, false ), 'none then site' => array( false, true ), 'neither' => array( false, false ), 'both' => array( true, true ) ) as $name => $sites ) {
                $cases[ $scope . ' new ' . $name ] = array( $scope, 'new', $sites, 'Batch@example.com', 'restricted' === $scope && ! $sites[0] );
            }
            foreach ( array( 'in scope', 'out of scope' ) as $existing ) {
                $cases[ $scope . ' existing ' . $existing ] = array( $scope, $existing, array( false, true ), 'Batch@example.com', 'restricted' === $scope && 'out of scope' === $existing );
            }
        }
        $cases['restricted case match'] = array( 'restricted', 'new', array( false, true ), 'batch@example.com', true );
        $cases['restricted trailing space match'] = array( 'restricted', 'new', array( false, true ), 'Batch@example.com ', true );
        $cases['restricted case and trailing space match'] = array( 'restricted', 'new', array( false, false ), 'batch@example.com ', true );
        $cases['restricted non-ascii case match'] = array( 'restricted', 'new', array( false, true ), 'ÉMILE@example.com', true, 'émile@example.com' );
        $cases['restricted stripped accent match'] = array( 'restricted', 'new', array( false, true ), 'Bäatch@example.com', true );
        $cases['all stripped accent merge'] = array( 'all', 'new', array( false, true ), 'Bäatch@example.com', false );
        return $cases;
    }

    /**
     * @dataProvider duplicate_multi_client_emails
     */
    public function test_multi_client_duplicate_email_preflight( $scope, $existing, $sites, $second_email, $refused, $first_email = 'Batch@example.com' ): void {
        $first = $this->create_test_site();
        $second = $this->create_test_site();
        if ( 'new' !== $existing ) {
            $client = MainWP_DB_Client::instance()->update_client( array( 'name' => 'Existing batch client', 'client_email' => 'Batch@example.com' ), true );
            $member = 'in scope' === $existing ? $first : $this->create_test_site();
            MainWP_DB::instance()->update_website_values( $member, array( 'client_id' => $client->client_id ) );
        }
        $this->scope( 'all' === $scope ? 'all' : array( $first, $second ) );
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array(
            array( 'client_name' => 'First row', 'client_email' => $first_email, 'website_id' => $sites[0] ? $first : '', 'contacts_field' => array( 'contact_name' => 'Batch contact', 'contact_email' => 'batch-contact@example.com' ) ),
            array( 'client_name' => 'Second row', 'client_email' => $second_email, 'website_id' => $sites[1] ? $second : '' ),
        ) ) );
        $this->assertSame( array( 'success' => ! $refused, 'data' => $refused ? 'Error - insufficient permissions!' : 'Created client successfully.' ), json_decode( $body, true ), $body );
        if ( $refused ) {
            $this->assert_unchanged( $before );
            return;
        }
        global $wpdb;
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_clients WHERE client_email = %s", 'Batch@example.com' ) ) );
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', 'Batch@example.com' );
        $this->assertSame( 'new' === $existing ? 'First row' : 'Existing batch client', $client->name );
        $members = array();
        if ( $sites[0] || 'in scope' === $existing ) {
            $members[] = $first;
        }
        if ( $sites[1] ) {
            $members[] = $second;
        }
        if ( 'out of scope' === $existing ) {
            $this->assertSame( array( $second, $member ), $this->members( $client ) );
        } elseif ( empty( $members ) ) {
            $this->assertEmpty( MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id, array( 'no_perm_check' => true ) ) );
        } else {
            $this->assertSame( $members, $this->members( $client ) );
        }
    }

    /** @return array Ordered rows, final owners, new clients and scope decisions. */
    public static function multi_client_plan_matrix(): array {
        $shapes = array(
            'new accessible site' => array( array( array( 'B', 'S2' ) ), array( 'S2' => 'B' ), array( 'B' ), false ),
            'new inaccessible site' => array( array( array( 'B', 'H' ) ), array( 'H' => 'B' ), array( 'B' ), true ),
            'missing site' => array( array( array( 'B', 'missing' ) ), array(), array(), true, true ),
            'existing overlap' => array( array( array( 'A', 'S2' ) ), array( 'S2' => 'A' ), array(), false ),
            'existing ASCII case' => array( array( array( 'A', 'S2', 'EMILE@example.com' ) ), array( 'S2' => 'A' ), array(), false ),
            'existing decomposed combining mark stripped' => array( array( array( 'A', 'S2', 'émile@example.com' ) ), array( 'S2' => 'A' ), array(), false ),
            'existing trailing space' => array( array( array( 'A', 'S2', 'emile@example.com ' ) ), array( 'S2' => 'A' ), array(), false ),
            'normalized existing overlap' => array( array( array( 'A', 'S2', '"emile"@example.com' ) ), array( 'S2' => 'A' ), array(), false ),
            'normalized existing no overlap' => array( array( array( 'Z', 'S2', '"hidden"@example.com' ) ), array( 'S2' => 'Z' ), array(), true ),
            'existing no overlap' => array( array( array( 'Z', 'S2' ) ), array( 'S2' => 'Z' ), array(), true ),
            'existing empty roster' => array( array( array( 'E', 'S2' ) ), array( 'S2' => 'E' ), array(), true ),
            'unreferenced donor' => array( array( array( 'B', 'T' ) ), array( 'T' => 'B' ), array( 'B' ), false ),
            'C4-1 earlier move removes later overlap' => array( array( array( 'B', 'S1' ), array( 'A', 'S2' ) ), array( 'S1' => 'B', 'S2' => 'A' ), array( 'B' ), true ),
            'C4-1 reverse order' => array( array( array( 'A', 'S2' ), array( 'B', 'S1' ) ), array( 'S1' => 'B', 'S2' => 'A' ), array( 'B' ), false ),
            'same site two new clients' => array( array( array( 'B', 'S2' ), array( 'C', 'S2' ) ), array( 'S2' => 'C' ), array( 'B', 'C' ), false ),
            'same site later former owner' => array( array( array( 'B', 'S1' ), array( 'A', 'S1' ) ), array( 'S1' => 'A' ), array( 'B' ), true ),
            'three rows retain another overlap' => array( array( array( 'A', 'S2' ), array( 'B', 'S1' ), array( 'A', 'S3' ) ), array( 'S1' => 'B', 'S2' => 'A', 'S3' => 'A' ), array( 'B' ), false ),
            'new duplicate loses its only site' => array( array( array( 'B', 'S2' ), array( 'C', 'S2' ), array( 'B', 'S3' ) ), array( 'S2' => 'C', 'S3' => 'B' ), array( 'B', 'C' ), true ),
            'S3-F1 duplicate none then site' => array( array( array( 'B', '' ), array( 'B', 'S2' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'duplicate site then none' => array( array( array( 'B', 'S2' ), array( 'B', '' ) ), array( 'S2' => 'B' ), array( 'B' ), false ),
            'duplicate neither site' => array( array( array( 'B', '' ), array( 'B', '' ) ), array(), array( 'B' ), true ),
            'duplicate both sites' => array( array( array( 'B', 'S2' ), array( 'B', 'S3' ) ), array( 'S2' => 'B', 'S3' => 'B' ), array( 'B' ), false ),
            'duplicate existing overlap' => array( array( array( 'A', '' ), array( 'A', 'S2' ) ), array( 'S2' => 'A' ), array(), false ),
            'duplicate existing no overlap' => array( array( array( 'Z', '' ), array( 'Z', 'S2' ) ), array( 'S2' => 'Z' ), array(), true ),
            'duplicate ASCII case' => array( array( array( 'B', '' ), array( 'B', 'S2', 'B@example.com' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'S3-G1 duplicate accent fold' => array( array( array( 'B', '' ), array( 'B', 'S2', 'b̈@example.com' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'duplicate non-ASCII case' => array( array( array( 'B', '', 'émile-new@example.com', null, 'mile-new@example.com' ), array( 'B', 'S2', 'ÉMILE-NEW@example.com' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'duplicate trailing space' => array( array( array( 'B', '' ), array( 'B', 'S2', 'b@example.com ' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'leading space normalized duplicate' => array( array( array( 'B', '' ), array( 'B', 'S2', ' b@example.com' ) ), array( 'S2' => 'B' ), array( 'B' ), true ),
            'hidden sites kept' => array( array( array( 'A', 'S2' ), array( 'A', 'S3' ) ), array( 'S2' => 'A', 'S3' => 'A' ), array(), false ),
            'new zero sites' => array( array( array( 'B', '' ) ), array(), array( 'B' ), false ),
            'existing overlap zero sites' => array( array( array( 'A', '' ) ), array(), array(), false ),
            'existing no overlap zero sites' => array( array( array( 'Z', '' ) ), array(), array(), true ),
            'existing empty zero sites' => array( array( array( 'E', '' ) ), array(), array(), true ),
            'all allowed three rows' => array( array( array( 'B', 'S2' ), array( 'C', 'S3' ), array( 'A', 'T' ) ), array( 'S2' => 'B', 'S3' => 'C', 'T' => 'A' ), array( 'B', 'C' ), false ),
            'missing optional fields' => array( array( array( 'B', 'S2', '', 'omitted' ) ), array(), array(), true, true, '', 'malformed' ),
            'empty emails stay distinct' => array( array( array( 'B', 'S2', '' ), array( 'C', 'S3', '' ) ), array( 'S2' => 'B', 'S3' => 'C' ), array( 'B', 'C' ), false ),
            'zero site id' => array( array( array( 'B', 0 ) ), array(), array( 'B' ), false ),
            'incomplete contact' => array( array( array( 'B', 'S2', null, 'incomplete' ) ), array( 'S2' => 'B' ), array( 'B' ), false ),
            'duplicate contact email' => array( array( array( 'B', 'S2', null, 'duplicate' ), array( 'C', 'S3', null, 'duplicate' ) ), array( 'S2' => 'B', 'S3' => 'C' ), array( 'B', 'C' ), false ),
            'empty batch' => array( array(), array(), array(), true, true ),
            'C4-2 unavailable metadata' => array( array( array( 'B', 'S2' ), array( 'B', 'S3' ) ), array( 'S2' => 'B', 'S3' => 'B' ), array( 'B' ), true, false, 'metadata' ),
            'C4-2 invalid metadata' => array( array( array( 'B', 'S2' ), array( 'B', 'S3' ) ), array( 'S2' => 'B', 'S3' => 'B' ), array( 'B' ), true, false, 'invalid metadata' ),
            'C4-2 NULL comparison' => array( array( array( 'B', 'S2' ), array( 'B', 'S3' ) ), array( 'S2' => 'B', 'S3' => 'B' ), array( 'B' ), true, false, 'comparison' ),
            // Non-empty emails that sanitize to empty must refuse before any projected move is stored.
            'zero email then padded zero' => array( array( array( 'B', 'S2', '0' ), array( 'B', 'S3', '0 ' ) ), array(), array(), true, true, '', 'invalid email' ),
            'empty email then space' => array( array( array( 'B', 'S2', '' ), array( 'B', 'S3', ' ' ) ), array(), array(), true, true, '', 'invalid email' ),
            'padded zero donor edited later' => array( array( array( 'B', 'S2', '0' ), array( 'B', 'S1', '0 ' ), array( 'A', 'S3' ) ), array(), array(), true, true, '', 'invalid email' ),
            'zero emails invalid' => array( array( array( 'B', 'S2', '0' ), array( 'C', 'S3', '0' ) ), array(), array(), true, true, '', 'invalid email' ),
            // A new client the column cannot store is refused before the earlier projected move detaches a site.
            'over-long new email' => array( array( array( 'B', 'S1', str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 150 ) . '.com' ), array( 'A', 'S2' ) ), array(), array(), true, true, '', 'too long' ),
            'over-long new name' => array( array( array( 'B', 'S1', null, 'long name' ), array( 'A', 'S2' ) ), array(), array(), true, true, '', 'too long' ),
            'client insert fails' => array( array( array( 'B', 'S1' ), array( 'C', 'S2' ) ), array(), array(), true, true, 'insert', 'insert' ),
        );
        $cases = array();
        foreach ( array( 'restricted', 'administrator' ) as $scope ) {
            foreach ( $shapes as $name => $shape ) {
                $cases[ $scope . ': ' . $name ] = array( $scope, $shape[0], $shape[1], $shape[2], $shape[3] && ( 'restricted' === $scope || ! empty( $shape[4] ) ), $shape[5] ?? '', $shape[6] ?? '' );
            }
        }
        return $cases;
    }

    /** @return array Raw import values and their expected stored strings. */
    public static function multi_client_normalization_matrix(): array {
        $shapes = array(
            'markup in name' => array( array( 'client_name' => '<img src=x onerror=alert(1)>Client' ), 'Client', 'second@example.com', null, false ),
            'slashed name' => array( array( 'client_name' => "O\\'Brien" ), "O'Brien", 'second@example.com', null, false ),
            'email normalization' => array( array( 'client_email' => '"second"@example.com' ), 'Second client', 'second@example.com', null, false ),
            'markup in contact' => array( array( 'contacts_field' => array( 'contact_name' => "<b>O\\'Brien</b>", 'contact_email' => '"contact"@example.com', 'contact_role' => '<img src=x onerror=alert(1)>Owner' ) ), 'Second client', 'second@example.com', array( "O'Brien", 'contact@example.com', 'Owner' ), false ),
            'invalid client email' => array( array( 'client_email' => 'invalid-email' ), '', '', null, true ),
            'markup only email' => array( array( 'client_email' => '<script>' ), '', '', null, true ),
            'invalid contact email' => array( array( 'contacts_field' => array( 'contact_name' => 'Contact', 'contact_email' => 'invalid-email' ) ), '', '', null, true ),
        );
        $cases = array();
        foreach ( array( 'restricted', 'administrator' ) as $scope ) {
            foreach ( $shapes as $name => $shape ) {
                $cases[ $scope . ': ' . $name ] = array_merge( array( $scope ), $shape );
            }
        }
        return $cases;
    }

    /**
     * Invalid later rows must refuse before the first client or contact is stored.
     *
     * @dataProvider multi_client_normalization_matrix
     * @param string $scope Caller scope.
     * @param array  $fields Raw second-row fields.
     * @param string $name Expected client name.
     * @param string $email Expected client email.
     * @param array|null $contact Expected contact fields.
     * @param bool $refused Expected refusal.
     */
    public function test_multi_client_normalization_matrix( $scope, $fields, $name, $email, $contact, $refused ): void {
        if ( 'administrator' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        }
        $first = $this->create_test_site();
        $second = $this->create_test_site();
        $this->scope( 'administrator' === $scope ? 'all' : array( $first, $second ) );
        $rows = array(
            array( 'client_name' => 'First client', 'client_email' => 'first@example.com', 'website_id' => $first, 'contacts_field' => array( 'contact_name' => 'First contact', 'contact_email' => 'first-contact@example.com' ) ),
            array_merge( array( 'client_name' => 'Second client', 'client_email' => 'second@example.com', 'website_id' => $second ), $fields ),
        );
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => $rows ) );
        $this->assertSame( array( 'success' => ! $refused, 'data' => $refused ? $this->import_refusal_message( 'invalid email' ) : 'Created client successfully.' ), json_decode( $body, true ), $body );
        if ( $refused ) {
            $this->assert_unchanged( $before );
            return;
        }
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', $email );
        $this->assertIsObject( $client );
        $this->assertSame( $name, $client->name );
        $this->assertSame( $email, $client->client_email );
        $this->assertSame( array( $second ), $this->members( $client ) );
        if ( $contact ) {
            $stored = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $client->primary_contact_id );
            $this->assertIsObject( $stored );
            $this->assertSame( $contact[0], $stored->contact_name );
            $this->assertSame( $contact[1], $stored->contact_email );
            $this->assertSame( $contact[2], $stored->contact_role );
        }
    }

    /** @return array JSON import entry points. */
    public static function json_import_handlers(): array {
        return array( array( 'mainwp_import_website_add_client', true ), array( 'mainwp_import_website_add_client_no_site', false ) );
    }

    /**
     * JSON escapes can carry markup past sanitization of the encoded string.
     *
     * @dataProvider json_import_handlers
     * @param string $action Import action.
     * @param bool $with_site Whether to attach a site.
     */
    public function test_json_import_normalizes_decoded_client_fields( $action, $with_site ): void {
        $site = $this->create_test_site();
        $this->scope( array( $site ) );
        $json = wp_slash( wp_json_encode( array( 'name' => "<img src=x onerror=alert(1)>O'Brien", 'client_email' => '"json-client"@example.com' ), JSON_HEX_TAG ) );
        $body = $this->dispatch( $action, array( 'client' => $with_site ? $json : array( $json ), 'site_id' => $with_site ? $site : '' ) );
        $this->assertSame( array( 'success' => $with_site, 'data' => $with_site ? 'Created client successfully.' : 'Undefined error. Please try again.' ), json_decode( $body, true ), $body );
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', 'json-client@example.com' );
        $this->assertIsObject( $client );
        $this->assertSame( "O'Brien", $client->name );
        $this->assertSame( 'json-client@example.com', $client->client_email );
        if ( $with_site ) {
            $this->assertSame( array( $site ), $this->members( $client ) );
        }
    }

    /** @return array JSON entry points under both authorization scopes. */
    public static function json_import_scopes(): array {
        $cases = array();
        foreach ( array( 'restricted', 'administrator' ) as $scope ) {
            foreach ( self::json_import_handlers() as $handler ) {
                $cases[ $scope . ': ' . $handler[0] ] = array_merge( array( $scope ), $handler );
            }
        }
        return $cases;
    }

    /**
     * An imported identifier must never select an existing client for an update.
     *
     * @dataProvider json_import_scopes
     */
    public function test_json_import_only_stores_known_sanitized_columns( $scope, $action, $with_site ): void {
        if ( 'administrator' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        }
        $target = $this->client();
        $hidden = $this->site( $target );
        $site = $this->create_test_site();
        $this->scope( 'administrator' === $scope ? 'all' : array( $site ) );
        $before = $this->before_write();
        $row = array(
            'client_id' => $target->client_id,
            'created' => 1,
            'name' => "<b>Back\\slash O'Brien</b>",
            'client_email' => 'known-columns@example.com',
            'image' => '../../outside.png',
            'suspended' => '1',
            'primary_contact_id' => '999',
            'selected_icon_info' => 'untrusted',
            'note' => '<img src=x onerror=alert(1)>Note',
        );
        foreach ( array( 'address_1', 'address_2', 'city', 'zip', 'state', 'country', 'client_phone' ) as $field ) {
            $row[ $field ] = '<img src=x onerror=alert(1)><b>' . $field . '</b>';
        }
        foreach ( array( 'client_facebook', 'client_twitter', 'client_instagram', 'client_linkedin' ) as $field ) {
            $row[ $field ] = 'javascript:alert(1)';
        }
        $json = wp_slash( wp_json_encode( $row, JSON_HEX_TAG ) );
        $body = $this->dispatch( $action, array( 'client' => $with_site ? $json : array( $json ), 'site_id' => $with_site ? $site : '' ) );
        $this->assertSame( array( 'success' => $with_site, 'data' => $with_site ? __( 'Created client successfully.', 'mainwp' ) : __( 'Undefined error. Please try again.', 'mainwp' ) ), json_decode( $body, true ), $body );
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', $row['client_email'] );
        $this->assertIsObject( $client );
        $this->assertNotSame( $target->client_id, $client->client_id );
        $this->assertSame( "Back\\slash O'Brien", $client->name );
        foreach ( array( 'address_1', 'address_2', 'city', 'zip', 'state', 'country', 'client_phone' ) as $field ) {
            $this->assertSame( $field, $client->$field, $field );
        }
        $this->assertSame( '&lt;img src=x onerror=alert(1)&gt;Note', $client->note );
        foreach ( array( 'image', 'client_facebook', 'client_twitter', 'client_instagram', 'client_linkedin' ) as $field ) {
            $this->assertSame( '', $client->$field, $field );
        }
        $this->assertSame( 'selected:wordpress;color:#34424d', $client->selected_icon_info );
        $this->assertSame( '0', $client->suspended );
        $this->assertSame( '0', $client->primary_contact_id );
        $this->assertNotSame( '1', $client->created );
        $after = $this->snapshot();
        $this->assertCount( count( $before['wp_clients'] ) + 1, $after['wp_clients'] );
        $this->assertSame( $before['wp_clients'][0], $after['wp_clients'][0], 'The targeted client must retain every column.' );
        $this->assertSame( $before['wp_clients_contacts'], $after['wp_clients_contacts'] );
        $this->assertSame( array( $hidden ), $this->members( $target ) );
        $this->assertSame( $with_site ? (int) $client->client_id : 0, (int) MainWP_DB::instance()->get_website_by_id_unscoped( $site )->client_id );
    }

    /** @return array Refusals across all import entry points and scopes. */
    public static function import_refusals(): array {
        $cases = array();
        $kinds = array( 'invalid email', 'invalid contact email', 'too long name', 'too long email', 'row string', 'name array', 'email null', 'contacts nested', 'contacts scalar', 'contact number', 'empty sanitized name', 'both empty', 'insert' );
        foreach ( array( 'restricted', 'administrator' ) as $scope ) {
            foreach ( array( 'site', 'no site', 'multi' ) as $handler ) {
                foreach ( $kinds as $kind ) {
                    $cases[ $scope . ': ' . $handler . ': ' . $kind ] = array( $scope, $handler, $kind );
                }
            }
        }
        return $cases;
    }

    /**
     * Refused requests must preserve clients, contacts and site ownership.
     *
     * @dataProvider import_refusals
     */
    public function test_import_refusals_return_specific_translated_message_without_writes( $scope, $handler, $kind ): void {
        global $wpdb;
        if ( 'administrator' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        }
        $existing = $this->client();
        $site = $this->site( $existing );
        $this->scope( 'administrator' === $scope ? 'all' : array( $site ) );
        $name_field = 'multi' === $handler ? 'client_name' : 'name';
        $valid = array( $name_field => 'Valid client', 'client_email' => 'valid@example.com' );
        if ( 'multi' === $handler ) {
            $valid['website_id'] = $site;
            $valid['contacts_field'] = array( 'contact_name' => 'Valid contact', 'contact_email' => 'contact@example.com' );
        }
        $row = array_merge( $valid, array( 'client_email' => 'second@example.com' ) );
        $message = 'malformed';
        switch ( $kind ) {
            case 'invalid email':
                $row['client_email'] = 'invalid-email';
                $message = 'invalid email';
                break;
            case 'invalid contact email':
                $row['contacts_field']['contact_email'] = 'invalid-email';
                $message = 'invalid email';
                break;
            case 'too long name':
            case 'too long email':
                $table = MainWP_DB_Client::instance()->get_table_name( 'wp_clients' );
                $column = 'too long name' === $kind ? 'name' : 'client_email';
                $limit = $wpdb->get_col_length( $table, $column )['length'];
                $row[ 'name' === $column ? $name_field : $column ] = 'name' === $column ? str_repeat( 'é', $limit + 1 ) : str_repeat( 'a', $limit ) . '@example.com';
                $message = 'too long';
                break;
            case 'row string':
                $row = 'invalid row';
                break;
            case 'name array':
                $row[ $name_field ] = array( 'Name' );
                break;
            case 'email null':
                $row['client_email'] = null;
                break;
            case 'contacts nested':
                $row['contacts_field'] = array( array( 'contact_name' => 'Contact' ) );
                break;
            case 'contacts scalar':
                $row['contacts_field'] = false;
                break;
            case 'contact number':
                $row['contacts_field']['contact_role'] = 123;
                break;
            case 'empty sanitized name':
                $row[ $name_field ] = '<img src=x onerror=alert(1)>';
                break;
            case 'both empty':
                $row[ $name_field ] = '';
                $row['client_email'] = '';
                break;
            case 'insert':
                $message = 'insert';
                $client_insert = 'INSERT INTO `' . $wpdb->prefix . 'mainwp_wp_clients`';
                $this->filter( 'query', static function ( $sql ) use ( $client_insert ) { return 0 === strpos( $sql, $client_insert ) ? '' : $sql; } );
                break;
        }
        $before = $this->before_write();
        if ( 'multi' === $handler ) {
            $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array( $valid, $row, array_merge( $valid, array( 'client_email' => 'third@example.com' ) ) ) ) );
        } else {
            $json = wp_slash( wp_json_encode( $row, JSON_HEX_TAG ) );
            $batch = array( wp_slash( wp_json_encode( $valid ) ), $json, wp_slash( wp_json_encode( array_merge( $valid, array( 'client_email' => 'third@example.com' ) ) ) ) );
            $body = $this->dispatch( 'mainwp_import_website_add_client' . ( 'no site' === $handler ? '_no_site' : '' ), array( 'client' => 'no site' === $handler ? $batch : $json, 'site_id' => $site ) );
        }
        $this->assertSame( array( 'success' => false, 'data' => $this->import_refusal_message( $message ) ), json_decode( $body, true ), $body );
        $this->assert_unchanged( $before );
    }

    /** @return array Non-string known JSON columns and non-object JSON shapes. */
    public static function malformed_json_imports(): array {
        $cases = array();
        foreach ( self::json_import_handlers() as $handler ) {
            foreach ( array( 'name', 'client_email', 'address_1', 'address_2', 'city', 'zip', 'state', 'country', 'client_phone', 'note', 'client_facebook', 'client_twitter', 'client_instagram', 'client_linkedin', 'image', 'selected_icon_info', 'suspended', 'primary_contact_id' ) as $field ) {
                $cases[ $handler[0] . ': ' . $field ] = array( $handler[0], $handler[1], wp_json_encode( array( 'name' => 'Client', 'client_email' => 'malformed@example.com', $field => array( 'invalid' ) ) ) );
            }
            foreach ( array( '{}', '[]', 'null', '42', '"scalar"', '{broken', '[{"name":"Client","client_email":"array@example.com"}]' ) as $json ) {
                $cases[ $handler[0] . ': ' . $json ] = array( $handler[0], $handler[1], $json );
            }
        }
        return $cases;
    }

    /** @dataProvider malformed_json_imports */
    public function test_json_import_refuses_non_string_columns_and_non_object_rows( $action, $with_site, $json ): void {
        $site = $this->create_test_site();
        $this->scope( array( $site ) );
        $before = $this->before_write();
        $json = wp_slash( $json );
        $body = $this->dispatch( $action, array( 'client' => $with_site ? $json : array( $json ), 'site_id' => $site ) );
        $this->assertSame( array( 'success' => false, 'data' => $this->import_refusal_message( 'malformed' ) ), json_decode( $body, true ), $body );
        $this->assert_unchanged( $before );
    }

    /** Precomposed non-ASCII email bytes retain the current sanitize_email behavior. */
    public function test_multi_client_precomposed_accent_creates_sanitized_address(): void {
        $existing = MainWP_DB_Client::instance()->update_client( array( 'name' => 'Existing', 'client_email' => 'emile@example.com' ), true );
        $existing_site = $this->site( $existing );
        $site = $this->create_test_site();
        $this->scope( array( $existing_site, $site ) );
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => array( array( 'client_name' => 'New client', 'client_email' => 'émile@example.com', 'website_id' => $site ) ) ) );
        $this->assertSame( array( 'success' => true, 'data' => __( 'Created client successfully.', 'mainwp' ) ), json_decode( $body, true ), $body );
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', sanitize_email( 'émile@example.com' ) );
        $this->assertIsObject( $client );
        $this->assertNotSame( $existing->client_id, $client->client_id );
        $this->assertSame( 'mile@example.com', $client->client_email );
        $this->assertSame( $before['wp_clients'][0], $this->snapshot()['wp_clients'][0] );
        $this->assertSame( array( $existing_site ), $this->members( $existing ) );
        $this->assertSame( array( $site ), $this->members( $client ) );
    }

    /** Server text must not become executable markup in the refusal zone. */
    public function test_multi_client_ui_escapes_server_message(): void {
        $message = '<img src=x onerror=alert(1)>Unreadable & invalid';
        $ui = $this->run_refusal_ui( 'mainwp-clients.js', '#bulk_add_multi_create_client', wp_json_encode( array( 'success' => false, 'data' => $message ) ) );
        $errors = array_values( array_filter( $ui['messages'], static function ( $entry ) { return '#mainwp-message-zone' === $entry['target'] && 'red' === $entry['style']; } ) );
        $this->assertCount( 1, $errors );
        $this->assertSame( esc_html( $message ), $errors[0]['text'] );
        $this->assertSame( 0, $ui['reloads'] );
    }

    /** @param string $kind Refusal category. @return string Translated response. */
    private function import_refusal_message( $kind ): string {
        if ( 'invalid email' === $kind ) {
            return __( 'One or more email addresses are not valid. No clients were added.', 'mainwp' );
        }
        if ( 'too long' === $kind ) {
            global $wpdb;
            $table = MainWP_DB_Client::instance()->get_table_name( 'wp_clients' );
            /* translators: 1: Maximum client name length, 2: Maximum client email address length. */
            return sprintf( __( 'A client name or email address is too long. Names can have up to %1$d characters and email addresses up to %2$d. No clients were added.', 'mainwp' ), $wpdb->get_col_length( $table, 'name' )['length'], $wpdb->get_col_length( $table, 'client_email' )['length'] );
        }
        if ( 'insert' === $kind ) {
            return __( 'A client could not be saved, so the import stopped. Clients before it in the list may have been added; check the Clients page.', 'mainwp' );
        }
        return __( 'The client data could not be read. Please try again.', 'mainwp' );
    }

    /**
     * @dataProvider multi_client_plan_matrix
     * @param string $scope Caller scope.
     * @param array  $rows Ordered client and site references.
     * @param array  $owners Changed final site owners for an allowed batch.
     * @param array  $created New client references in insertion order.
     * @param bool   $refused Expected refusal.
     * @param string $fault Database comparison fault.
     * @param string $message Refusal text when it is not the scope refusal.
     */
    public function test_multi_client_plan_matrix( $scope, $rows, $owners, $created, $refused, $fault, $message = '' ): void {
        global $wpdb;
        if ( 'administrator' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        }
        $clients = array();
        foreach ( array( 'A' => 'emile@example.com', 'Z' => 'hidden@example.com', 'E' => 'empty@example.com', 'D' => 'donor@example.com' ) as $key => $email ) {
            $clients[ $key ] = MainWP_DB_Client::instance()->update_client( array( 'name' => 'Original ' . $key, 'client_email' => $email ), true );
        }
        $sites = array();
        $initial_owners = array( 'S1' => 'A', 'S2' => '', 'S3' => '', 'H' => 'A', 'X' => 'Z', 'T' => 'D' );
        foreach ( $initial_owners as $key => $owner ) {
            $sites[ $key ] = $this->create_test_site( array( 'client_id' => $owner ? $clients[ $owner ]->client_id : 0 ) );
        }
        $sites['missing'] = 99999999;
        $this->scope( 'administrator' === $scope ? 'all' : array( $sites['S1'], $sites['S2'], $sites['S3'], $sites['T'], $sites['missing'] ) );
        $data = array();
        foreach ( $rows as $index => $row ) {
            $data[ $index ] = array(
                'client_name' => 'Row ' . $index,
                'client_email' => $row[2] ?? ( isset( $clients[ $row[0] ] ) ? $clients[ $row[0] ]->client_email : strtolower( $row[0] ) . '@example.com' ),
                'website_id' => $sites[ $row[1] ] ?? $row[1],
                'contacts_field' => array( 'contact_name' => 'Contact ' . $index, 'contact_email' => 'contact-' . $index . '@example.com' ),
            );
            if ( 'omitted' === ( $row[3] ?? '' ) ) {
                unset( $data[ $index ]['client_name'], $data[ $index ]['client_email'], $data[ $index ]['contacts_field'] );
            } elseif ( 'incomplete' === ( $row[3] ?? '' ) ) {
                unset( $data[ $index ]['contacts_field']['contact_email'] );
            } elseif ( 'duplicate' === ( $row[3] ?? '' ) ) {
                $data[ $index ]['contacts_field']['contact_email'] = 'duplicate-contact@example.com';
            } elseif ( 'long name' === ( $row[3] ?? '' ) ) {
                $data[ $index ]['client_name'] = str_repeat( 'n', 256 );
            }
        }
        if ( $fault ) {
            $client_insert = 'INSERT INTO `' . $wpdb->prefix . 'mainwp_wp_clients`';
            $this->filter( 'query', static function ( $sql ) use ( $fault, $client_insert ) {
                if ( 'insert' === $fault ) {
                    return 0 === strpos( $sql, $client_insert ) ? '' : $sql;
                }
                if ( false !== strpos( $sql, 'SHOW FULL COLUMNS' ) ) {
                    if ( 'metadata' === $fault ) {
                        return 'SELECT NULL WHERE 1=0';
                    }
                    if ( 'invalid metadata' === $fault ) {
                        return "SELECT 'unsafe-collation' AS Collation";
                    }
                }
                return 'comparison' === $fault && 0 === strpos( $sql, 'SELECT CONVERT(' ) ? 'SELECT NULL' : $sql;
            } );
        }
        $before = $this->before_write();
        $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => $data ) );
        $expected_response = array( 'success' => ! $refused, 'data' => $refused ? ( $message ? $this->import_refusal_message( $message ) : ( $rows ? 'Error - insufficient permissions!' : 'Undefined error. Please try again.' ) ) : 'Created client successfully.' );
        $this->assertSame( $expected_response, json_decode( $body, true ), $body );
        $after = $this->snapshot();
        $before_ids = array_column( $before['wp_clients'], 'client_id' );
        $new_rows = array_values( array_filter( $after['wp_clients'], static function ( $row ) use ( $before_ids ) { return ! in_array( $row['client_id'], $before_ids, true ); } ) );
        $this->assertCount( $refused ? 0 : count( $created ), $new_rows, 'Unexpected created client IDs: ' . wp_json_encode( array_column( $new_rows, 'client_id' ) ) );
        if ( $refused ) {
            $this->assert_unchanged( $before );
            return;
        }
        foreach ( $created as $index => $key ) {
            $first = array_search( $key, array_column( $rows, 0 ), true );
            $this->assertGreaterThan( 0, (int) $new_rows[ $index ]['client_id'] );
            $this->assertSame( $data[ $first ]['client_name'] ?? '', $new_rows[ $index ]['name'] );
            $this->assertSame( $rows[ $first ][4] ?? ( $data[ $first ]['client_email'] ?? '' ), $new_rows[ $index ]['client_email'] );
            foreach ( array( 'image', 'address_1', 'address_2', 'city', 'zip', 'state', 'country', 'note', 'client_phone', 'client_facebook', 'client_twitter', 'client_instagram', 'client_linkedin' ) as $field ) {
                $this->assertSame( '', $new_rows[ $index ][ $field ], $field );
            }
            $this->assertSame( 'selected:wordpress;color:#34424d', $new_rows[ $index ]['selected_icon_info'] );
            $this->assertSame( '0', $new_rows[ $index ]['suspended'] );
            $clients[ $key ] = (object) $new_rows[ $index ];
        }
        $expected = $before;
        $expected['wp_clients'] = array_merge( $expected['wp_clients'], $new_rows );
        $contacts = array();
        foreach ( $data as $index => $row ) {
            $fields = $row['contacts_field'] ?? array();
            if ( empty( $fields['contact_name'] ) || empty( $fields['contact_email'] ) || isset( $contacts[ $fields['contact_email'] ] ) ) {
                continue;
            }
            $contact = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_email', $fields['contact_email'], ARRAY_A );
            $this->assertIsArray( $contact );
            $this->assertSame( $fields['contact_name'], $contact['contact_name'] );
            $this->assertSame( (string) $clients[ $rows[ $index ][0] ]->client_id, $contact['contact_client_id'] );
            foreach ( array( 'contact_role', 'contact_phone', 'facebook', 'twitter', 'instagram', 'linkedin', 'contact_icon_info' ) as $field ) {
                $this->assertSame( '', $contact[ $field ], $field );
            }
            $contacts[ $fields['contact_email'] ] = $contact;
            $expected['wp_clients_contacts'][] = $contact;
            foreach ( $expected['wp_clients'] as &$client_row ) {
                if ( $client_row['client_id'] === $contact['contact_client_id'] ) {
                    $client_row['primary_contact_id'] = $contact['contact_id'];
                }
            }
            unset( $client_row );
        }
        foreach ( array_merge( $initial_owners, $owners ) as $site => $owner ) {
            foreach ( $expected['wp'] as &$site_row ) {
                if ( (int) $site_row['id'] === $sites[ $site ] ) {
                    $site_row['client_id'] = $owner ? (string) $clients[ $owner ]->client_id : '0';
                }
            }
            unset( $site_row );
        }
        $this->assertSame( $expected, $after, 'Unexpected client, contact, field or site-assignment rows.' );
        $this->assertSame( array_fill_keys( array_keys( $this->hooks ), 0 ), $this->hooks, 'Quick Setup imports must preserve the existing client hook sequence (none).' );
    }

    /** @return array Request shapes the contact write cannot take, per scope. */
    public static function malformed_multi_client_rows(): array {
        $shapes = array(
            'data string'              => 'abc',
            'data null'                => null,
            'data false'               => false,
            'data empty string'        => '',
            'row string'               => array( 'abc' ),
            'client email array'       => array( array( 'client_email' => array( 'x@example.com' ) ) ),
            'client name array'        => array( array( 'client_name' => array( 'x' ) ) ),
            'contacts field string'    => array( array( 'contacts_field' => 'abc' ) ),
            'contacts field value array' => array( array( 'contacts_field' => array( 'contact_name' => array( 'x' ), 'contact_email' => 'c@example.com' ) ) ),
        );
        $cases = array();
        foreach ( array( 'restricted', 'administrator' ) as $scope ) {
            foreach ( $shapes as $name => $shape ) {
                $cases[ $scope . ': ' . $name ] = array( $scope, $shape );
            }
        }
        return $cases;
    }

    /**
     * A malformed second row must refuse before the valid first row is written.
     *
     * @dataProvider malformed_multi_client_rows
     * @param string       $scope Caller scope.
     * @param mixed        $shape Malformed data or rows.
     */
    public function test_multi_client_malformed_row_refused_before_any_write( $scope, $shape ): void {
        if ( 'administrator' === $scope ) {
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        }
        $site = $this->create_test_site( array( 'client_id' => 0 ) );
        $this->scope( 'administrator' === $scope ? 'all' : array( $site ) );
        $data = $shape;
        if ( is_array( $shape ) ) {
            $valid = array(
                'client_name'    => 'Valid row',
                'client_email'   => 'valid-' . wp_generate_uuid4() . '@example.com',
                'website_id'     => $site,
                'contacts_field' => array( 'contact_name' => 'Valid contact', 'contact_email' => 'valid-contact-' . wp_generate_uuid4() . '@example.com' ),
            );
            $data = array( $valid, is_array( $shape[0] ) ? array_merge( $valid, array( 'client_email' => 'second-' . wp_generate_uuid4() . '@example.com' ), $shape[0] ) : $shape[0] );
        }
        $before = $this->before_write();
        $body   = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => $data ) );
        $this->assertSame( array( 'success' => false, 'data' => $this->import_refusal_message( 'malformed' ) ), json_decode( $body, true ), $body );
        $this->assert_unchanged( $before );
    }

    /** A client row the database refuses must not get a contact written against a missing client. */
    public function test_multi_client_failed_client_insert_writes_no_contact(): void {
        global $wpdb;
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $this->scope( 'all' );
        $site = $this->create_test_site( array( 'client_id' => 0 ) );
        $data = array(
            array(
                'client_name'    => 'Too long',
                'client_email'   => str_repeat( 'a', 200 ) . '@example.com',
                'website_id'     => $site,
                'contacts_field' => array( 'contact_name' => 'Orphan contact', 'contact_email' => 'orphan-' . wp_generate_uuid4() . '@example.com' ),
            ),
        );
        $before   = $this->before_write();
        $warnings = array();
        // The defect surfaces only as a warning on the false client and a NULL contact insert.
        set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
            static function ( $errno, $message ) use ( &$warnings ) {
                $warnings[] = $message;
                return true;
            },
            E_WARNING
        );
        try {
            $body = $this->dispatch( 'mainwp_clients_add_multi_client', array( 'data' => $data ) );
        } finally {
            restore_error_handler();
        }
        $this->assertSame( array(), $warnings, $body );
        $this->assertStringNotContainsString( 'contact_client_id', $wpdb->last_error );
        $after = $this->snapshot();
        $this->assertSame( $before['wp_clients'], $after['wp_clients'], 'The refused client row was written.' );
        $this->assertSame( $before['wp_clients_contacts'], $after['wp_clients_contacts'], 'A contact was written for a client that does not exist.' );
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

    /** Invalid IDs must not reach a restricted user's roster. */
    public function test_missing_client_access_skips_roster(): void {
        $this->scope( array() );
        $queries = 0;
        $this->filter( 'query', static function ( $sql ) use ( &$queries ) { ++$queries; return $sql; } );
        $this->assertFalse( MainWP_Client::has_client_access( 0 ) );
        $this->assertFalse( MainWP_Client::has_client_access( -1 ) );
        $this->assertSame( 0, $queries, 'Invalid client IDs must not read a restricted roster.' );
    }

    /** Unrestricted access retains its legacy result without a client ID. */
    public function test_missing_client_access_keeps_unrestricted_behavior(): void {
        $this->scope( 'all' );
        $this->assertTrue( MainWP_Client::has_client_access( 0 ) );
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
