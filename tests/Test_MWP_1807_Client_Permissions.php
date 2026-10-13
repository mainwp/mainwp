<?php
/**
 * MWP-1807 client mutation permission regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Client;
use MainWP\Dashboard\MainWP_Client_Overview_Custom_Info;
use MainWP\Dashboard\MainWP_Client_Overview_Note;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Post_Handler;

/** Stop an AJAX response without ending the PHPUnit process. */
class MWP_1807_Response_Stop extends \Error {}

/** Verify every residual MWP-1807 write and UI boundary. */
class Test_MWP_1807_Client_Permissions extends \WP_UnitTestCase {

    /** @var callable|null Test-owned MainWP rights filter. */
    private $rights_filter;

    /** @var array Original menu globals. */
    private $menu_globals = array();

    /** @var bool Whether this test registered the Clients menu. */
    private $registered_client_menu = false;

    /** Sign in as a Dashboard administrator before applying Team Control rights. */
    public function setUp(): void {
        parent::setUp();
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();

        foreach ( array( 'submenu', 'mainwp_leftmenu', 'mainwp_sub_leftmenu', '_mainwp_menu_active_slugs', '_registered_pages', '_parent_pages', '_wp_submenu_nopriv' ) as $key ) {
            $this->menu_globals[ $key ] = array_key_exists( $key, $GLOBALS ) ? $GLOBALS[ $key ] : null;
        }
    }

    /** Restore filters, request state and shared menu globals. */
    public function tearDown(): void {
        if ( $this->rights_filter ) {
            remove_filter( 'mainwp_currentusercan', $this->rights_filter, 999 );
        }
        foreach ( $this->menu_globals as $key => $value ) {
            if ( null === $value ) {
                unset( $GLOBALS[ $key ] );
            } else {
                $GLOBALS[ $key ] = $value;
            }
        }
        if ( $this->registered_client_menu && MainWP_Client::$page ) {
            remove_action( 'load-' . MainWP_Client::$page, array( MainWP_Client::class, 'on_load_page' ) );
        }
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** A nonce cannot authorize global client-field writes without manage_clients. */
    public function test_global_client_field_mutations_require_manage_clients(): void {
        $saved       = $this->field( 'saved' );
        $deleted     = $this->field( 'deleted' );
        $bulk_first  = $this->field( 'bulk-first' );
        $bulk_second = $this->field( 'bulk-second' );
        $stored_name = MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $saved )->field_name;
        $this->allow_rights( array( 'access_client_dashboard' ) );

        $response = $this->dispatch(
            'mainwp_clients_save_field',
            array(
                'client_id'  => 0,
                'field_id'   => $saved,
                'field_name' => 'unauthorized-rename',
                'field_desc' => 'Unauthorized edit',
            )
        );
        $this->assert_permission_error( $response );
        $this->assertSame( $stored_name, MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $saved )->field_name );

        $new_name = 'unauthorized-new-' . wp_generate_uuid4();
        $response = $this->dispatch(
            'mainwp_clients_save_field',
            array(
                'client_id'  => 0,
                'field_id'   => 0,
                'field_name' => $new_name,
                'field_desc' => 'Unauthorized field',
            )
        );
        $this->assert_permission_error( $response );
        $this->assertNull( MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $new_name, 0 ) );

        $response = $this->dispatch( 'mainwp_clients_delete_general_field', array( 'field_id' => $deleted ) );
        $this->assert_permission_error( $response );
        $this->assertNotNull( MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $deleted ) );

        $response = $this->dispatch( 'mainwp_clients_bulk_delete_fields', array( 'field_ids' => array( $bulk_first, $bulk_second ) ) );
        $this->assert_permission_error( $response );
        $this->assertNotNull( MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $bulk_first ) );
        $this->assertNotNull( MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $bulk_second ) );
    }

    /** The global Client Fields page is registered and rendered only for client managers. */
    public function test_client_fields_page_requires_manage_clients(): void {
        $this->allow_rights( array( 'access_client_dashboard' ) );
        $GLOBALS['submenu']                    = array();
        $GLOBALS['mainwp_leftmenu']            = array();
        $GLOBALS['mainwp_sub_leftmenu']        = array();
        $GLOBALS['_mainwp_menu_active_slugs']  = array();
        $GLOBALS['_registered_pages']          = array();
        $GLOBALS['_parent_pages']              = array();
        $GLOBALS['_wp_submenu_nopriv']         = array();

        MainWP_Client::init_menu();
        $this->registered_client_menu = true;

        $submenu = array_values(
            array_filter(
                $GLOBALS['submenu']['mainwp_tab'] ?? array(),
                static function ( $item ) {
                    return isset( $item[2] ) && 'ClientAddField' === $item[2];
                }
            )
        );
        $this->assertCount( 1, $submenu );
        $this->assertSame( 'manage_options', $submenu[0][1] );

        $left_menu = array_values(
            array_filter(
                $GLOBALS['mainwp_sub_leftmenu']['ManageClients'] ?? array(),
                static function ( $item ) {
                    return isset( $item[4] ) && 'ClientAddField' === $item[4];
                }
            )
        );
        $this->assertCount( 1, $left_menu );
        $this->assertSame( 'manage_clients', $left_menu[0][2] );

        ob_start();
        MainWP_Client::init_subpages_menu();
        $submenu_html = (string) ob_get_clean();
        $this->assertStringNotContainsString( 'page=ClientAddField', $submenu_html );

        ob_start();
        MainWP_Client::render_client_fields();
        $page_html = (string) ob_get_clean();
        $this->assertStringContainsString( 'mainwp-permission-error', $page_html );
        $this->assertStringNotContainsString( 'mainwp-add-client-fields', $page_html );
    }

    /** Read-only Client Overview users see data but not mutation controls. */
    public function test_client_overview_hides_mutation_controls_without_manage_clients(): void {
        $client = $this->client();
        $this->allow_rights( array( 'access_client_dashboard' ) );

        $note = $this->render_widget( array( MainWP_Client_Overview_Note::class, 'render_addition_info' ), $client->client_id );
        $info = $this->render_widget( array( MainWP_Client_Overview_Custom_Info::class, 'render_addition_info' ), $client->client_id );

        $this->assertStringContainsString( 'MWP-1807 read-only note', $note );
        $this->assertStringNotContainsString( 'mainwp-edit-client-note', $note );
        $this->assertStringNotContainsString( 'Edit Notes', $note );
        $this->assertStringContainsString( 'Additional Client Info', $info );
        $this->assertStringNotContainsString( 'Add Custom Client Info', $info );
        $this->assertStringNotContainsString( 'page=ClientAddField', $info );

        $this->allow_rights( array( 'manage_clients' ) );
        $note = $this->render_widget( array( MainWP_Client_Overview_Note::class, 'render_addition_info' ), $client->client_id );
        $info = $this->render_widget( array( MainWP_Client_Overview_Custom_Info::class, 'render_addition_info' ), $client->client_id );
        $this->assertStringContainsString( 'Edit Notes', $note );
        $this->assertStringContainsString( 'Add Custom Client Info', $info );
    }

    /** @param array<int, string> $rights MainWP Dashboard rights to allow. */
    private function allow_rights( array $rights ): void {
        if ( $this->rights_filter ) {
            remove_filter( 'mainwp_currentusercan', $this->rights_filter, 999 );
        }
        $this->rights_filter = static function ( $allowed, $type, $right ) use ( $rights ) {
            unset( $allowed );
            return 'dashboard' === $type && in_array( $right, $rights, true );
        };
        add_filter( 'mainwp_currentusercan', $this->rights_filter, 999, 3 );
    }

    /** @param string $label Fixture label. @return int Global field ID. */
    private function field( $label ): int {
        $field = MainWP_DB_Client::instance()->add_client_field(
            array(
                'client_id'  => 0,
                'field_name' => 'mwp-1807-' . $label . '-' . wp_generate_uuid4(),
                'field_desc' => 'MWP-1807 fixture',
            )
        );
        $this->assertIsObject( $field );
        return (int) $field->field_id;
    }

    /** @return object Client fixture. */
    private function client() {
        $client = MainWP_DB_Client::instance()->update_client(
            array(
                'name'         => 'MWP-1807 client ' . wp_generate_uuid4(),
                'client_email' => wp_generate_uuid4() . '@example.com',
                'note'         => 'MWP-1807 read-only note',
                'suspended'    => 0,
                'created'      => time(),
            ),
            true
        );
        $this->assertIsObject( $client );
        return $client;
    }

    /** @param string $action AJAX action and method. @param array $post Request fields. @return array Decoded response. */
    private function dispatch( $action, array $post ): array {
        $_POST    = array_merge( $post, array( 'security' => wp_create_nonce( $action ) ) );
        $_REQUEST = $_POST;
        $body     = $this->capture(
            static function () use ( $action ) {
                MainWP_Post_Handler::instance()->$action();
            }
        );
        $response = json_decode( $body, true );
        $this->assertIsArray( $response, $body );
        return $response;
    }

    /** @param array $response AJAX response. */
    private function assert_permission_error( array $response ): void {
        $this->assertFalse( $response['success'] );
        $this->assertStringContainsString( 'permission', strtolower( $response['error'] ) );
    }

    /** @param callable $callback AJAX request. @return string Response body. */
    private function capture( callable $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( MWP_1807_Response_Stop $exception ) {
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
        return static function ( $message ) {
            throw new MWP_1807_Response_Stop( (string) $message );
        };
    }

    /** @param callable $callback Widget renderer. @param int $client_id Client ID. @return string Markup. */
    private function render_widget( callable $callback, $client_id ): string {
        ob_start();
        $callback( $client_id );
        return (string) ob_get_clean();
    }
}
