<?php
/**
 * Command palette search ranking and entity authorization regressions.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Command_Palette;
use MainWP\Dashboard\MainWP_Post_Site_Handler;

/** Stop captured AJAX responses without terminating PHPUnit. */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only response termination beside the fixture using it.
class Command_Palette_Ajax_Response_Stop extends \Error {}

/** Search the real MainWP tables through the production palette helpers. */
class Test_Command_Palette extends MainWP_Abilities_Test_Case {

    /** @var array|string Team Control grants for the current test. */
    private $allowed = 'all';

    /** Capture legacy exits while retaining the production administrator and nonce checks. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $code = '';
        foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/class/class-mainwp-post-base-handler.php' ) ) as $token ) {
            if ( is_array( $token ) ) {
                $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && 'MainWP_Post_Base_Handler' === $token[1] ? 'Command_Palette_Ajax_Base_Handler' : $token[1] );
            } else {
                $code .= $token;
            }
        }
        $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
        eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Existing suite pattern: capture legacy exits in a renamed test-only source copy.
    }

    /** Configure an administrator and predictable, test-owned scope filters. */
    public function setUp(): void {
        parent::setUp();
        $this->set_current_user_as_admin();
        $this->allowed = 'all';
        add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
        add_filter( 'mainwp_is_single_user', '__return_true', 999 );
        add_filter( 'mainwp_currentusercan', '__return_true', 999 );
    }

    /** Restore the shared suite's authorization filters. */
    public function tearDown(): void {
        remove_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'allowed_sites' ), 999 );
        remove_filter( 'mainwp_is_single_user', '__return_true', 999 );
        remove_filter( 'mainwp_currentusercan', '__return_true', 999 );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** @return array|string Test-specific site grants. */
    public function allowed_sites() {
        return $this->allowed;
    }

    /** Exact and prefix matches must survive a five-result limit. */
    public function test_site_name_ranking_happens_before_the_limit(): void {
        $query = 'palette-' . wp_generate_uuid4();
        for ( $i = 0; $i < 5; ++$i ) {
            $this->create_test_site( array( 'name' => 'Other ' . $query . ' match ' . $i, 'url' => 'https://test-aa' . $i . '.example.com/' ) );
        }
        $prefix = $this->create_test_site( array( 'name' => $query . ' prefix', 'url' => 'https://test-yy.example.com/' ) );
        $exact  = $this->create_test_site( array( 'name' => $query, 'url' => 'https://test-zz.example.com/' ) );

        $commands = MainWP_Command_Palette::get_site_commands( $query );

        $this->assertCount( 5, $commands );
        $this->assertSame( 'mainwp/site/' . $exact, $commands[0]['name'] );
        $this->assertSame( 'mainwp/site/' . $prefix, $commands[1]['name'] );
    }

    /** A full URL resolves the exact host ahead of URLs containing that host. */
    public function test_normalized_url_ranking_happens_before_the_limit(): void {
        $host = 'test-zz-' . wp_generate_uuid4() . '.example.com';
        for ( $i = 0; $i < 6; ++$i ) {
            $this->create_test_site( array( 'url' => 'https://test-aa' . $i . '.example.com/' . $host . '/' ) );
        }
        $exact = $this->create_test_site( array( 'url' => 'https://www.' . $host . '/' ) );

        $commands = MainWP_Command_Palette::get_site_commands( 'http://www.' . $host . '/' );

        $this->assertCount( 5, $commands );
        $this->assertSame( 'mainwp/site/' . $exact, $commands[0]['name'] );
    }

    /** Hidden exact matches cannot displace an allowed result. */
    public function test_site_scope_is_applied_before_ranking_and_recent_resolution(): void {
        $this->skip_when_capabilities_bypassed();
        $query = 'palette-' . wp_generate_uuid4();
        $hidden = $this->create_test_site( array( 'name' => $query ) );
        $visible = $this->create_test_site( array( 'name' => 'Other ' . $query ) );
        $staging = $this->create_test_site( array( 'name' => $query, 'is_staging' => 1 ) );
        $this->allowed = array( $visible, $staging );

        $commands = MainWP_Command_Palette::get_site_commands( $query );
        $this->assertSame( array( 'mainwp/site/' . $visible ), array_column( $commands, 'name' ) );
        $recent = MainWP_Command_Palette::get_recent_commands( array( 'mainwp/site/' . $hidden, 'mainwp/site/' . $visible, 'mainwp/site/' . $staging ) );
        $this->assertSame( array( 'mainwp/site/' . $visible ), array_column( $recent, 'name' ) );
        $this->allowed = array();
        $this->assertSame( array(), MainWP_Command_Palette::get_site_commands( $query ) );
    }

    /** Client lookup uses integer-only grants and cannot expose another client. */
    public function test_client_scope_filters_search_and_recent(): void {
        $query   = 'Test Client palette-' . wp_generate_uuid4();
        $hidden  = $this->create_test_client( array( 'name' => $query ) );
        $visible = $this->create_test_client( array( 'name' => $query . ' allowed' ) );
        $this->create_test_site( array( 'client_id' => $hidden ) );
        $allowed_site = $this->create_test_site( array( 'client_id' => $visible ) );
        $this->allowed = array( (string) $allowed_site, '0) OR 1=1 --', -1 );

        $commands = MainWP_Command_Palette::get_client_commands( $query );
        $this->assertSame( array( 'mainwp/client/' . $visible ), array_column( $commands, 'name' ) );
        $this->assertSame( admin_url( 'admin.php?page=ManageClients&client_id=' . $visible ), $commands[0]['url'] );
        $recent = MainWP_Command_Palette::get_recent_commands( array( 'mainwp/client/' . $hidden, 'mainwp/client/' . $visible ) );
        $this->assertSame( array( 'mainwp/client/' . $visible ), array_column( $recent, 'name' ) );
        $this->allowed = array();
        $this->assertSame( array(), MainWP_Command_Palette::get_client_commands( $query ) );
    }

    /** Recent accepts only bounded native entity IDs, without duplicates. */
    public function test_recent_rejects_malformed_foreign_and_overflow_ids(): void {
        $site = $this->create_test_site();
        $name = 'mainwp/site/' . $site;
        $recent = MainWP_Command_Palette::get_recent_commands( array( $name, 'mainwp/site/0', $name, 'other/site/' . $site, 'mainwp/site/999999999999999999999999999', 'mainwp/client/1' ) );
        $this->assertSame( array( $name ), array_column( $recent, 'name' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_recent_commands( 'mainwp/site/1' ) );
    }

    /** No entity data is returned outside an administrator session. */
    public function test_non_admin_cannot_resolve_entities(): void {
        $site = $this->create_test_site();
        $this->set_current_user_as_subscriber();
        $this->assertSame( array(), MainWP_Command_Palette::get_site_commands( 'Test Site' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_client_commands( 'Test Client' ) );
        $this->assertSame( array(), MainWP_Command_Palette::get_recent_commands( array( 'mainwp/site/' . $site ) ) );
    }

    /** A valid nonce must not let a non-administrator reach a successful search response. */
    public function test_ajax_rejects_non_administrator_with_valid_nonce(): void {
        $this->set_current_user_as_subscriber();
        $nonce = wp_create_nonce( 'mainwp_command_palette_search_sites' );
        $this->assertNotFalse( wp_verify_nonce( $nonce, 'mainwp_command_palette_search_sites' ) );

        $body = $this->request_palette( array( 'search' => 'Test Site', 'security' => $nonce ) );

        $this->assertSame( '', $body, 'The shared non-administrator guard exits before emitting a search response.' );
    }

    /** Missing, invalid and other-action nonces must all fail for an administrator. */
    public function test_ajax_rejects_missing_invalid_and_wrong_action_nonces(): void {
        foreach ( array( null, 'invalid-nonce', wp_create_nonce( 'another-mainwp-action' ) ) as $nonce ) {
            $post = array( 'search' => 'Test Site' );
            if ( null !== $nonce ) {
                $post['security'] = $nonce;
            }
            $response = json_decode( $this->request_palette( $post ), true );

            $this->assertIsArray( $response );
            $this->assertArrayHasKey( 'error', $response );
            $this->assertStringContainsString( 'Insecure request!', $response['error'] );
            $this->assertArrayNotHasKey( 'success', $response );
        }
    }

    /** An authenticated request returns the site and client in the expected JSON envelope. */
    public function test_ajax_valid_administrator_receives_search_results(): void {
        $query  = 'palette-request-' . wp_generate_uuid4();
        $client = $this->create_test_client( array( 'name' => 'Test Client ' . $query ) );
        $site   = $this->create_test_site( array( 'name' => 'Test Site ' . $query, 'client_id' => $client ) );

        $response = json_decode(
            $this->request_palette( array( 'search' => $query, 'security' => wp_create_nonce( 'mainwp_command_palette_search_sites' ) ) ),
            true
        );

        $this->assertIsArray( $response );
        $this->assertTrue( $response['success'] );
        $this->assertSame( array( 'mainwp/site/' . $site, 'mainwp/client/' . $client ), array_column( $response['data'], 'name' ) );
        $this->assertSame( admin_url( 'admin.php?page=ManageClients&client_id=' . $client ), $response['data'][1]['url'] );
    }

    /**
     * Run the production AJAX controller with real security checks and captured exits.
     *
     * @param array $post Browser-style POST values.
     * @return string Response body.
     */
    private function request_palette( array $post ): string {
        $request  = array( $_GET, $_POST, $_REQUEST );
        $instance = new \ReflectionProperty( MainWP_Post_Site_Handler::class, 'instance' );
        $original = $instance->getValue();
        $guard    = new class() extends \MainWP\Dashboard\Command_Palette_Ajax_Base_Handler {
            /** Direct requests do not need action registration. */
            protected function init() {}
        };
        // Retain the controller and shared guard; only redirect the guard's raw exits to wp_die().
        $instance->setValue( null, new class( $guard ) extends MainWP_Post_Site_Handler {
            /** @var object Production guard source with captured exits. */
            private $guard;

            /** @param object $guard Capturable security guard. */
            public function __construct( $guard ) {
                $this->guard = $guard;
            }

            /** @param string $action Nonce action. @param string $query_arg Nonce field. */
            public function secure_request( $action = '', $query_arg = 'security' ) {
                $this->guard->secure_request( $action, $query_arg );
            }
        } );
        $die_handler = static function () {
            return static function ( $message = '' ) {
                // PHP die(0) sets an exit status without printing; string exits emit their message.
                if ( is_string( $message ) ) {
                    echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured test response, never browser output.
                }
                throw new Command_Palette_Ajax_Response_Stop();
            };
        };
        $ajax_context = static function () {
            return true;
        };
        $_GET         = array();
        $_POST        = $post;
        $_REQUEST     = $post;
        add_filter( 'wp_doing_ajax', $ajax_context, 999 );
        add_filter( 'wp_die_ajax_handler', $die_handler, 999 );
        ob_start();
        try {
            MainWP_Command_Palette::ajax_search_sites();
            $this->fail( 'The AJAX controller must terminate its response.' );
        } catch ( Command_Palette_Ajax_Response_Stop $stop ) {
            return ob_get_contents();
        } finally {
            ob_end_clean();
            remove_filter( 'wp_die_ajax_handler', $die_handler, 999 );
            remove_filter( 'wp_doing_ajax', $ajax_context, 999 );
            $instance->setValue( null, $original );
            list( $_GET, $_POST, $_REQUEST ) = $request;
        }
    }
}
