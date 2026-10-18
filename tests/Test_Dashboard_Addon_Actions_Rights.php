<?php
/**
 * Add-on AJAX rights and license API TLS verification.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Captured exits must escape legacy Exception handlers.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fixtures exercise production nonce checks.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Save and restore request globals around production nonce checks.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve response bodies for assertions.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Source copies and isolated key fixtures need local file operations.

use MainWP\Dashboard\Addon_Rights_Extension_Handler;
use MainWP\Dashboard\Addon_Rights_Post_Handler;
use MainWP\Dashboard\MainWP_Api_Manager;
use MainWP\Dashboard\MainWP_Api_Manager_Key;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Encrypt_Data_Lib;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Extensions_Handler;
use MainWP\Dashboard\MainWP_Extensions_View;
use MainWP\Dashboard\MainWP_Keys_Manager;

/** Keep captured exits outside handlers that catch Exception. */
class Addon_Rights_Response_Stop extends \Error {}

/** Exercise registered AJAX callbacks with administrator capabilities. */
class Test_Dashboard_Addon_Actions_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    private const SLUG      = 'fixture-dashboard-addon-rights';
    private const TLS_ERROR = 'Could not verify the TLS certificate of the MainWP license server. Check that the CA certificates on the server hosting this Dashboard are up to date and that no proxy is intercepting TLS, then try again.';

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();
    /**
     * Original options.
     *
     * @var array
     */
    private $options = array();
    /**
     * Original AJAX hooks.
     *
     * @var array
     */
    private $ajax_hooks = array();
    /**
     * Original static properties.
     *
     * @var array
     */
    private $properties = array();
    /**
     * Original request and screen.
     *
     * @var array
     */
    private $request = array();
    /**
     * Intercepted HTTP requests.
     *
     * @var array
     */
    private $http = array();
    /**
     * HTTP fixture response.
     *
     * @var mixed
     */
    private $http_response;
    /**
     * Isolated key directory.
     *
     * @var string
     */
    private $fixture_root = '';
    /**
     * Site fixtures.
     *
     * @var array
     */
    private $sites = array();

    /** Replace termination tokens without replacing authorization or workers. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Base_Handler'      => 'Addon_Rights_Base_Handler',
            'MainWP_Post_Extension_Handler' => 'Addon_Rights_Extension_Handler',
            'MainWP_Post_Handler'           => 'Addon_Rights_Post_Handler',
        );
        foreach ( array( 'class/class-mainwp-post-base-handler.php', 'class/class-mainwp-post-extension-handler.php', 'class/class-mainwp-post-handler.php' ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                $code .= is_array( $token ) ? ( T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] ) ) : $token;
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture exits from unchanged production callbacks through real AJAX hooks.
        }
    }

    /** Isolate options, keys, HTTP and registered callbacks. */
    public function setUp(): void {
        parent::setUp();
        global $wp_filter, $current_screen;
        $this->request = array( $_GET, $_POST, $_REQUEST, get_current_user_id(), $current_screen ?? null );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        wp_create_nonce( 'addon-rights-fixture' );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        set_current_screen( 'dashboard' );
        $this->filter( 'mainwp_currentusercan', '__return_true' );
        $this->http_response = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"success":true}',
            'headers'  => array(),
        );
        $this->filter(
            'pre_http_request',
            function ( $pre, $args, $url ) {
                $this->http[] = array(
                    'url'  => $url,
                    'args' => $args,
                );
                return $this->http_response;
            }
        );
        $this->fixture_root = get_temp_dir() . 'mainwp-addon-rights-keys-' . wp_generate_uuid4();
        mkdir( $this->fixture_root, 0700, true );
        $this->filter(
            'mainwp_get_wp_upload_dir',
            function ( $upload ) {
                $upload['basedir'] = $this->fixture_root;
                return $upload;
            }
        );
        foreach ( array(
            self::SLUG . '_APIManAdder',
            'mainwp_extensions_all_activation_cached',
            'mainwp_cron_licenses_deactivated_alerted',
            'mainwp_extensions_master_api_key',
            'mainwp_extensions_api_save_login',
            'mainwp_extensions_plan_info',
            'mainwp_extmenu',
            'mainwp_api_sslVerifyCertificate',
            'mainwp_not_start_encrypt_keys',
            'mainwp_ajaxposts',
            'mainwp_extensions',
        ) as $name ) {
            $this->options[ $name ] = get_option( $name );
        }
        update_option( 'mainwp_api_sslVerifyCertificate', 1 );
        $this->save_property( MainWP_Api_Manager_Key::class, 'apisslverify' );
        new MainWP_Api_Manager_Key();
        $this->assertTrue( MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', 'fixture-license-key' ) );
        update_option( 'mainwp_extensions_api_save_login', false );
        update_option( 'mainwp_not_start_encrypt_keys', 1 );
        update_option( 'mainwp_ajaxposts', array( 'preserved' => 'original' ) );
        update_option( 'mainwp_extmenu', array( self::SLUG => 'original' ) );
        $this->assertTrue(
            MainWP_Api_Manager::instance()->set_activation_info(
                self::SLUG,
                array(
                    'api_key'          => 'fixture-license-key',
                    'activated_key'    => 'Activated',
                    'product_id'       => 'fixture-product',
                    'instance_id'      => 'fixture-instance',
                    'software_version' => '1.0',
                )
            )
        );
        $this->sites = array(
            $this->create_test_site( array( 'privkey' => base64_encode( 'fixture-private-key-one' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Match production key storage.
            $this->create_test_site( array( 'privkey' => base64_encode( 'fixture-private-key-two' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Match production key storage.
        );
        $this->filter(
            'mainwp_get_sql_websites',
            function ( $params ) {
                $params['include'] = $this->sites;
                return $params;
            }
        );
        foreach ( array( 'security_names', 'security_nonces' ) as $name ) {
            $this->save_property( \MainWP\Dashboard\Addon_Rights_Base_Handler::class, $name );
        }
        foreach ( $wp_filter as $hook => $callbacks ) {
            if ( 0 === strpos( $hook, 'wp_ajax_' ) ) {
                $this->ajax_hooks[ $hook ] = clone $callbacks;
                unset( $wp_filter[ $hook ] );
            }
        }
        Addon_Rights_Extension_Handler::instance()->init();
        Addon_Rights_Post_Handler::instance()->init();
    }

    /** Restore all fixture state, including caches and key files. */
    public function tearDown(): void {
        global $wp_filter, $current_screen;
        foreach ( array_keys( $wp_filter ) as $hook ) {
            if ( 0 === strpos( $hook, 'wp_ajax_' ) ) {
                unset( $wp_filter[ $hook ] );
            }
        }
        foreach ( $this->ajax_hooks as $hook => $callbacks ) {
            $wp_filter[ $hook ] = $callbacks;
        }
        foreach ( $this->properties as $saved ) {
            $saved[0]->setValue( null, $saved[1] );
        }
        foreach ( $this->options as $name => $value ) {
            false === $value ? delete_option( $name ) : update_option( $name, $value );
        }
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, PHP_INT_MAX );
        }
        if ( $this->fixture_root && is_dir( $this->fixture_root ) ) {
            $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->fixture_root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $files as $file ) {
                $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
            }
            rmdir( $this->fixture_root );
        }
        list( $_GET, $_POST, $_REQUEST, $user_id, $current_screen ) = $this->request;
        wp_set_current_user( $user_id );
        parent::tearDown();
    }

    /**
     * Action, right and accepted request matrix.
     *
     * @return array Cases.
     */
    public static function actions(): array {
        return array(
            'menu add'            => array( 'mainwp_extension_add_menu', 'manage_extensions', array( 'slug' => self::SLUG ) ),
            'menu remove'         => array( 'mainwp_extension_remove_menu', 'manage_extensions', array( 'slug' => self::SLUG ) ),
            'activate'            => array(
                'mainwp_extension_api_activate',
                'manage_extensions',
                array(
                    'slug' => self::SLUG . '/fixture.php',
                    'key'  => 'fixture-license-key',
                ),
            ),
            'deactivate'          => array(
                'mainwp_extension_deactivate',
                'manage_extensions',
                array(
                    'slug'    => self::SLUG . '/fixture.php',
                    'api_key' => 'fixture-license-key',
                ),
            ),
            'test login'          => array( 'mainwp_extension_testextensionapilogin', 'manage_extensions', array() ),
            'connection security' => array( 'mainwp_increase_connection_security', 'manage_dashboard_settings', array() ),
        );
    }

    /**
     * Refusals preserve options, site keys and HTTP state.
     *
     * @dataProvider actions
     * @param string $action Action.
     * @param string $right Right.
     * @param array  $post Request.
     */
    public function test_refused_action( string $action, string $right, array $post ): void {
        $this->right( $right, false );
        $before   = $this->snapshot();
        $response = $this->ajax( $action, $post );
        $this->assertSame( array(), $this->http, 'A refused action reached HTTP.' );
        $this->assertSame( $this->refusal( $right ), json_decode( $response, true ) );
        $this->assertSame( $before, $this->snapshot() );
    }

    /**
     * The right precedes nonce and input validation.
     *
     * @dataProvider actions
     * @param string $action Action.
     * @param string $right Right.
     * @param array  $post Unused accepted request.
     */
    public function test_refusal_precedes_validation( string $action, string $right, array $post ): void {
        $this->right( $right, false );
        $before   = $this->snapshot();
        $response = $this->ajax( $action, array( 'security' => 'invalid' ) );
        $this->assertSame( array(), $this->http );
        $this->assertSame( $this->refusal( $right ), json_decode( $response, true ) );
        $this->assertSame( $before, $this->snapshot() );
    }

    /**
     * Non-admins stop before MainWP rights are evaluated.
     *
     * @dataProvider actions
     * @param string $action Action.
     * @param string $right Right.
     * @param array  $post Request.
     */
    public function test_non_admin_action( string $action, string $right, array $post ): void {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
        wp_create_nonce( 'addon-rights-subscriber' );
        $this->right( $right, true );
        $calls = 0;
        $this->filter(
            'mainwp_currentusercan',
            static function () use ( &$calls ) {
                ++$calls;
                return true;
            }
        );
        $before = $this->snapshot();
        $this->assertSame( '0', $this->ajax( $action, $post ) );
        $this->assertSame( 0, $calls );
        $this->assertSame( array(), $this->http );
        $this->assertSame( $before, $this->snapshot() );
    }

    /**
     * Permitted requests reach the existing workers.
     *
     * @dataProvider actions
     * @param string $action Action.
     * @param string $right Right.
     * @param array  $post Request.
     */
    public function test_allowed_action( string $action, string $right, array $post ): void {
        $this->right( $right, true );
        if ( 'mainwp_extension_api_activate' === $action ) {
            $info                  = MainWP_Api_Manager::instance()->get_activation_info( self::SLUG );
            $info['activated_key'] = 'Deactivated';
            MainWP_Api_Manager::instance()->set_activation_info( self::SLUG, $info );
            $this->http_response['body'] = '{"activated":false,"code":"fixture","error":"Fixture license rejected."}';
        } elseif ( 'mainwp_extension_deactivate' === $action ) {
            $this->http_response['body'] = '{"deactivated":true,"activations_remaining":1}';
        }
        $before   = $this->snapshot();
        $response = json_decode( $this->ajax( $action, $post ), true );
        if ( 'mainwp_extension_api_activate' === $action ) {
            $this->assertSame( array( 'error' => 'Fixture license rejected.' ), $response );
        } elseif ( 'mainwp_increase_connection_security' === $action ) {
            $this->assertSame( array( 'success' => 1 ), $response );
        } else {
            $this->assertSame( 'SUCCESS', $response['result'] );
        }
        if ( 'mainwp_extension_add_menu' === $action ) {
            $this->assertSame(
                array(
                    self::SLUG => 'original',
                    self::SLUG,
                ),
                get_option( 'mainwp_extmenu' )
            );
        } elseif ( 'mainwp_extension_remove_menu' === $action ) {
            $this->assertSame( array(), get_option( 'mainwp_extmenu' ) );
        } elseif ( 'mainwp_extension_api_activate' === $action || 'mainwp_extension_deactivate' === $action ) {
            $info = MainWP_Api_Manager::instance()->get_activation_info( self::SLUG );
            $this->assertSame( 'Deactivated', $info['activated_key'] );
            $this->assertCount( 1, $this->http );
        } elseif ( 'mainwp_extension_testextensionapilogin' === $action ) {
            $this->assertCount( 1, $this->http );
        } else {
            $this->assertFalse( get_option( 'mainwp_not_start_encrypt_keys' ) );
            foreach ( $this->sites as $index => $id ) {
                $site = MainWP_DB::instance()->get_website_by_id( $id );
                $this->assertNotSame( $before['sites'][ $index ]['privkey'], $site->privkey );
                $this->assertSame( 0 === $index ? 'fixture-private-key-one' : 'fixture-private-key-two', MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( base64_decode( $site->privkey ), $id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Verify encrypted storage round-trips.
            }
        }
        $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
    }

    /** An already activated throwaway key retains the existing success path. */
    public function test_allowed_existing_activation(): void {
        $this->right( 'manage_extensions', true );
        $response = json_decode(
            $this->ajax(
                'mainwp_extension_api_activate',
                array(
                    'slug' => self::SLUG . '/fixture.php',
                    'key'  => 'fixture-license-key',
                )
            ),
            true
        );
        $this->assertSame( array( 'result' => 'SUCCESS' ), $response );
        $this->assertSame( array(), $this->http );
    }

    /** Non-JSON license replies must preserve verification and return an error. */
    public function test_test_login_non_json(): void {
        $this->right( 'manage_extensions', true );
        $this->http_response['body'] = 'fixture non-JSON reply';
        $response                    = json_decode( $this->ajax( 'mainwp_extension_testextensionapilogin' ), true );
        $this->assertSame( array( 'error' => 'Connection failed to the License Key API server. Try again later.' ), $response );
        $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
        $this->assertCount( 1, $this->http );
        $this->assertSame( 1, $this->http[0]['args']['sslverify'] );
    }

    /** A missing master key returns a connection error without sending HTTP. */
    public function test_test_login_without_master_key(): void {
        $this->right( 'manage_extensions', true );
        $master_key = get_option( 'mainwp_extensions_master_api_key' );
        delete_option( 'mainwp_extensions_master_api_key' );
        try {
            $response = json_decode( $this->ajax( 'mainwp_extension_testextensionapilogin' ), true );
            $this->assertSame( array( 'error' => 'Connection failed to the License Key API server. Try again later.' ), $response );
            $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
            $this->assertSame( array(), $this->http );
        } finally {
            update_option( 'mainwp_extensions_master_api_key', $master_key );
        }
    }

    /**
     * Transport messages under enabled and disabled certificate verification.
     *
     * @return array Cases.
     */
    public static function transport_errors(): array {
        $certificate = 'cURL error 60: SSL certificate problem: unable to get local issuer certificate';
        $timeout     = 'cURL error 28: Operation timed out after 50001 milliseconds';
        $dns         = 'cURL error 6: Could not resolve host: mainwp.com';
        $connection  = 'cURL error 7: Failed to connect to mainwp.com port 443';
        $handshake   = 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to mainwp.com:443';
        $reset       = 'cURL error 35: OpenSSL SSL_connect: Connection reset by peer in connection to mainwp.com:443';
        $protocol    = 'cURL error 35: error:0A000102:SSL routines::unsupported protocol';
        $read        = 'cURL error 56: OpenSSL SSL_read: Connection reset by peer, errno 104';
        return array(
            'cURL 60'               => array( 1, $certificate, self::TLS_ERROR ),
            'cURL 77'               => array( 1, 'cURL error 77: error setting certificate verify locations', self::TLS_ERROR ),
            'certificate mismatch'  => array( 1, 'SSL certificate did not match the requested domain name', self::TLS_ERROR ),
            'cURL 51'               => array( 1, 'cURL error 51: SSL: no alternative certificate subject name matches target host name', self::TLS_ERROR ),
            'uppercase certificate' => array( 1, 'CERTIFICATE validation failed', self::TLS_ERROR ),
            'streams transport'     => array( 1, 'The SSL certificate for the host could not be verified.', self::TLS_ERROR ),
            'cURL 35 syscall'       => array( 1, $handshake, $handshake ),
            'cURL 35 reset'         => array( 1, $reset, $reset ),
            'cURL 35 protocol'      => array( 1, $protocol, $protocol ),
            'cURL 56'               => array( 1, $read, $read ),
            'cURL 28'               => array( 1, $timeout, $timeout ),
            'cURL 6'                => array( 1, $dns, $dns ),
            'cURL 7'                => array( 1, $connection, $connection ),
            'unverified cURL 60'    => array( 0, $certificate, $certificate ),
            'unverified cURL 28'    => array( 0, $timeout, $timeout ),
            'HTML message'          => array( 1, '<b>Transport failure</b>', '&lt;b&gt;Transport failure&lt;/b&gt;' ),
        );
    }

    /**
     * Transport failures preserve verification and never request a TLS retry.
     *
     * @dataProvider transport_errors
     * @param int    $verify Certificate verification flag.
     * @param string $message Transport error.
     * @param string $expected Expected exception message.
     */
    public function test_verify_api_key_transport_error( int $verify, string $message, string $expected ): void {
        $api      = MainWP_Api_Manager_Key::instance();
        $property = new \ReflectionProperty( MainWP_Api_Manager_Key::class, 'apisslverify' );
        $property->setAccessible( true );
        $property->setValue( null, $verify );
        $this->http_response = new \WP_Error( 'http_request_failed', $message );
        $error               = '';
        $result              = null;
        try {
            $result = $api->verify_api_key( array( 'api_key' => 'fixture-license-key' ) );
        } catch ( MainWP_Exception $exception ) {
            $error = $exception->getMessage();
        }
        $this->assertSame( $expected, $error );
        $this->assertNull( $result, 'No retry_action response may escape.' );
        $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
        $this->assertCount( 1, $this->http );
        $this->assertSame( $verify, $this->http[0]['args']['sslverify'] );
    }

    /** Both PHP consumers must convert transport exceptions to AJAX errors. */
    public function test_login_consumers_return_transport_error(): void {
        $this->right( 'manage_extensions', true );
        $errors = array(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate' => self::TLS_ERROR,
            'cURL error 28: Operation timed out after 50001 milliseconds' => 'cURL error 28: Operation timed out after 50001 milliseconds',
        );
        foreach ( $errors as $message => $expected ) {
            $this->http          = array();
            $this->http_response = new \WP_Error( 'http_request_failed', $message );
            foreach ( array( 'mainwp_extension_testextensionapilogin', 'mainwp_extension_saveextensionapilogin' ) as $action ) {
                $response = json_decode( $this->ajax( $action, array( 'api_key' => 'fixture-license-key' ) ), true );
                $this->assertSame( array( 'error' => $expected ), $response );
                $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
            }
            $this->assertCount( 2, $this->http );
        }
    }

    /**
     * License server replies to an activation request.
     *
     * @return array Cases: reply (WP_Error or HTTP response), expected error, key storage failing.
     */
    public static function activation_replies(): array {
        $failed = 'Connection failed to the License Key API server. Try again later.';
        $reply  = static function ( int $code, string $body ): array {
            return array(
                'response' => array( 'code' => $code ),
                'body'     => $body,
                'headers'  => array(),
            );
        };
        return array(
            'certificate error'                      => array( new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), $failed ),
            'certificate error, key storage failing' => array( new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), $failed, true ),
            'timeout'                                => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 50001 milliseconds' ), $failed ),
            'HTTP 500'                               => array( $reply( 500, '{"activated":true}' ), $failed ),
            'empty body'                             => array( $reply( 200, '' ), $failed ),
            'non-JSON body'                          => array( $reply( 200, '<html>Maintenance</html>' ), $failed ),
            'JSON scalar'                            => array( $reply( 200, 'null' ), $failed ),
            'rejected'                               => array( $reply( 200, '{"activated":false,"code":"fixture","error":"Fixture license rejected."}' ), 'Fixture license rejected.' ),
            'rejected, key storage failing'          => array( $reply( 200, '{"activated":false,"code":"fixture","error":"Fixture license rejected."}' ), 'Fixture license rejected.', true ),
        );
    }

    /**
     * A failed or refused activation returns its error, keeps the stored key and status, and the same key can be submitted again.
     *
     * @dataProvider activation_replies
     * @param array|\WP_Error $reply License server reply.
     * @param string          $expected Expected error.
     * @param bool            $storage_fails Key encryption fails, so the activation info cannot be saved.
     */
    public function test_activation_reply( $reply, string $expected, bool $storage_fails = false ): void {
        $this->right( 'manage_extensions', true );
        $info                  = MainWP_Api_Manager::instance()->get_activation_info( self::SLUG );
        $info['activated_key'] = 'Deactivated';
        $this->assertTrue( MainWP_Api_Manager::instance()->set_activation_info( self::SLUG, $info ) );
        if ( $storage_fails ) {
            $this->filter( 'mainwp_encrypt_key_value', '__return_false' );
        }
        $post                = array(
            'slug' => self::SLUG . '/fixture.php',
            'key'  => 'fixture-license-key',
        );
        $this->http_response = $reply;
        $response            = json_decode( $this->ajax( 'mainwp_extension_api_activate', $post ), true );
        $this->assertSame( array( 'error' => $expected ), $response );
        $after = MainWP_Api_Manager::instance()->get_activation_info( self::SLUG );
        $this->assertSame( 'fixture-license-key', $after['api_key'] );
        $this->assertSame( 'Deactivated', $after['activated_key'] );
        $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
        $this->assertCount( 1, $this->http );
        $this->assertSame( 1, $this->http[0]['args']['sslverify'] );
        $this->assertSame( 'softwareactivation', $this->http[0]['args']['body']['request'] );

        $this->http_response = array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"activated":false,"code":"fixture","error":"Fixture license rejected."}',
            'headers'  => array(),
        );
        $retry               = json_decode( $this->ajax( 'mainwp_extension_api_activate', $post ), true );
        $this->assertSame( array( 'error' => 'Fixture license rejected.' ), $retry, 'The retry must reach activation, not the key replacement path.' );
        $this->assertCount( 2, $this->http );
        $this->assertSame( 'softwareactivation', $this->http[1]['args']['body']['request'] );
    }

    /** Loading Add-ons must not change the license API TLS option. */
    public function test_extensions_render_preserves_tls(): void {
        foreach ( array( 'extensions', 'extensions_disabled' ) as $name ) {
            $property = $this->save_property( MainWP_Extensions_Handler::class, $name );
            $property->setValue( null, array() );
        }
        $output = $this->capture( array( MainWP_Extensions_View::class, 'render' ) );
        $this->assertStringContainsString( 'id="mainwp-manage-extensions"', $output );
        $this->assertSame( 1, (int) get_option( 'mainwp_api_sslVerifyCertificate' ) );
        $this->assertSame( array(), $this->http );
    }

    /**
     * Snapshot all options and both sites, including mainwp_ajaxposts.
     *
     * @return array Stored state.
     */
    private function snapshot(): array {
        global $wpdb;
        return array(
            'options' => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Detect any refused option write.
            'sites'   => $wpdb->get_results( 'SELECT id, pubkey, privkey FROM ' . MainWP_DB::instance()->get_table_name( 'wp' ) . ' ORDER BY id', ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Read fixture key state from the trusted table name.
        );
    }

    /**
     * Apply only the requested MainWP right.
     *
     * @param string $right Right.
     * @param bool   $allowed Grant.
     */
    private function right( string $right, bool $allowed ): void {
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run this class before Test_REST_*.' );
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $requested ) use ( $right, $allowed ) {
                return 'dashboard' === $type && $right === $requested ? $allowed : true;
            }
        );
        $this->assertSame( $allowed, \mainwp_current_user_can( 'dashboard', $right ) );
    }

    /**
     * Expected rights refusal.
     *
     * @param string $right Right.
     * @return array Response.
     */
    private function refusal( string $right ): array {
        return array( 'error' => 'manage_extensions' === $right ? 'You are not allowed to manage Add-ons.' : 'Error - insufficient permissions!' );
    }

    /**
     * Dispatch through production registrations with captured exits.
     *
     * @param string $action Action.
     * @param array  $post Request overrides.
     * @return string Response.
     */
    private function ajax( string $action, array $post = array() ): string {
        $this->assertNotFalse( has_action( 'wp_ajax_' . $action ), 'Missing production AJAX registration.' );
        $_POST    = array_merge(
            array(
                'security' => wp_create_nonce( $action ),
                'dts'      => wp_generate_uuid4(),
            ),
            $post
        );
        $_REQUEST = $_POST;
        return $this->capture(
            static function () use ( $action ) {
                do_action( 'wp_ajax_' . $action );
            }
        );
    }

    /**
     * Capture JSON, wp_die and bare die responses.
     *
     * @param callable $callback Request.
     * @return string Response.
     */
    private function capture( callable $callback ): string {
        $doing_ajax = static function () {
            return true;
        };
        add_filter( 'wp_doing_ajax', $doing_ajax );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Addon_Rights_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', $doing_ajax );
        }
        return $buffer . $body;
    }

    /**
     * AJAX termination callback.
     *
     * @return callable Callback.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Addon_Rights_Response_Stop( (string) $message );
        };
    }

    /**
     * Replace only test-owned callbacks.
     *
     * @param string   $hook Filter.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], PHP_INT_MAX );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, PHP_INT_MAX, 3 );
    }

    /**
     * Save a static cache for teardown.
     *
     * @param string $class_name Class.
     * @param string $name Property.
     * @return \ReflectionProperty Property.
     */
    private function save_property( string $class_name, string $name ): \ReflectionProperty {
        $property = new \ReflectionProperty( $class_name, $name );
        $property->setAccessible( true );
        $this->properties[] = array( $property, $property->getValue() );
        return $property;
    }
}
