<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Match the PHPUnit test filenames.
/**
 * CLI add-site argument, registration request and output tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Sites_Handler;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_WP_CLI_Handle;

/** Minimal command parent for loading the CLI handler in the WordPress harness. */
class Add_Site_CLI_Command_Double {}

/** Capture CLI output without terminating PHPUnit. */
class Add_Site_CLI_Output_Double {
	/**
	 * Captured command messages.
	 *
	 * @var array
	 */
	public static $messages = array();

	/**
	 * Capture a successful command result.
	 *
	 * @param string $message Command output.
	 */
	public static function success( $message ) {
		self::$messages[] = array( 'success', $message );
	}

	/**
	 * Capture a command error and simulate its exit.
	 *
	 * @param string $message Command output.
	 * @throws Add_Site_CLI_Error After capturing the error.
	 */
	public static function error( $message ) {
		self::$messages[] = array( 'error', $message );
		throw new Add_Site_CLI_Error();
	}
}

/** Expected CLI error exit. */
class Add_Site_CLI_Error extends \RuntimeException {}

/** Exercise the real adapter and serialized registration request. */
class Test_WP_CLI_Add_Site extends \WP_UnitTestCase {
	/**
	 * Serialized registration requests.
	 *
	 * @var array
	 */
	private $requests = array();
	/**
	 * Child response fixture.
	 *
	 * @var array
	 */
	private $reply = array( 'register' => 'OK' );
	/**
	 * Certificate verification flags received by transport.
	 *
	 * @var array
	 */
	private $certificate_checks = array();
	/**
	 * Simulate an untrusted certificate at the transport boundary.
	 *
	 * @var bool
	 */
	private $untrusted_certificate = false;
	/**
	 * Native cURL failure diagnostic.
	 *
	 * @var string
	 */
	private $transport_error = 'SSL certificate problem: self-signed certificate';

	/**
	 * Load the CLI handler with output doubles.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		// The handler checks existence; false preserves normal capability checks.
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', false );
		}
		if ( ! defined( 'MAINWP_DEV_FILTERS_ENABLED' ) ) {
			define( 'MAINWP_DEV_FILTERS_ENABLED', true );
		}
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			class_alias( Add_Site_CLI_Command_Double::class, 'WP_CLI_Command' );
		}
		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( Add_Site_CLI_Output_Double::class, 'WP_CLI' );
		}
		require_once dirname( __DIR__ ) . '/class/class-mainwp-wp-cli-handle.php';
	}

	/**
	 * Prepare an administrator and intercept registration transport.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Add_Site_CLI_Output_Double::$messages = array();
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		add_filter( 'mainwp_dev_return_data_before_connect_site', array( $this, 'capture_request' ), 10, 6 );
		add_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'set_transport_failure_context' ), 10, 12 );
		add_filter( 'mainwp_fetch_url_authed_pre', '__return_empty_array' );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	/**
	 * Remove the transport hooks and captured output.
	 */
	public function tear_down() {
		remove_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		remove_filter( 'mainwp_dev_return_data_before_connect_site', array( $this, 'capture_request' ), 10 );
		remove_filter( 'mainwp_fetch_url_not_authed_pre', array( $this, 'set_transport_failure_context' ), 10 );
		remove_filter( 'mainwp_fetch_url_authed_pre', '__return_empty_array' );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		Add_Site_CLI_Output_Double::$messages = array();
		parent::tear_down();
	}

	/**
	 * Prevent unrelated WordPress HTTP requests.
	 *
	 * @return \WP_Error Network refusal.
	 */
	public function block_http() {
		return new \WP_Error( 'test_network_disabled', 'Network disabled in CLI tests.' );
	}

	/**
	 * Capture the serialized request before cURL transport.
	 *
	 * @param mixed  $pre     Existing development response.
	 * @param object $website Site being registered.
	 * @param string $url     Registration endpoint.
	 * @param string $postdata Serialized request.
	 * @param bool   $check_constraints Whether transport checks site constraints.
	 * @param bool   $verify_certificate Whether transport verifies the certificate.
	 * @return array Child response fixture.
	 * @throws MainWP_Exception Native transport failure fixture.
	 */
	public function capture_request( $pre, $website, $url, $postdata, $check_constraints, $verify_certificate ) {
		$this->requests[]           = $postdata;
		$this->certificate_checks[] = $verify_certificate;
		if ( $this->untrusted_certificate && $verify_certificate ) {
			throw new MainWP_Exception( 'HTTPERROR', $this->transport_error );
		}
		return $this->reply;
	}

	/**
	 * Supply the same failure category as cURL before the capture hook throws.
	 *
	 * @param mixed  $pre Existing response.
	 * @param string $url Endpoint.
	 * @param string $admin Administrator.
	 * @param string $what Operation.
	 * @param array  $params Request fields.
	 * @param bool   $force Force request.
	 * @param bool   $verify_certificate Certificate verification.
	 * @param string $http_user HTTP username.
	 * @param string $http_pass HTTP password.
	 * @param int    $ssl_version TLS version.
	 * @param array  $others Transport options.
	 * @param array  $output Failure context passed by reference.
	 * @return mixed Existing response.
	 */
	public function set_transport_failure_context( $pre, $url, $admin, $what, $params, $force, $verify_certificate, $http_user, $http_pass, $ssl_version, $others, &$output ) {
		if ( $this->untrusted_certificate && $verify_certificate ) {
			$output['error_category'] = 'http_error';
		}
		return $pre;
	}

	/**
	 * Build the required add-site arguments.
	 *
	 * @return array Command arguments.
	 */
	private function arguments() {
		return array(
			'add-site' => true,
			'site-url' => 'https://cli-add-site.example.org/',
			'name'     => 'CLI site',
			'admin'    => 'child-admin',
		);
	}

	/**
	 * Run add-site and capture its success or error output.
	 *
	 * @param array $args Command arguments.
	 * @return array Output status and message.
	 */
	private function run_command( $args ) {
		try {
			MainWP_WP_CLI_Handle::callback_site_add_site( array(), $args );
		} catch ( Add_Site_CLI_Error $error ) {
			// WP_CLI::error terminates the command after writing its message.
		}
		$this->assertCount( 1, Add_Site_CLI_Output_Double::$messages );
		return Add_Site_CLI_Output_Double::$messages[0];
	}

	/**
	 * Password registration verifies HTTPS certificates by default.
	 */
	public function test_password_defaults_to_certificate_verification() {
		$args                  = $this->arguments();
		$args['adminpassword'] = 'CliFixture42';
		update_option( 'mainwp_sslVerifyCertificate', 0 );
		list( $status ) = $this->run_command( $args );
		$this->assertSame( array( true ), $this->certificate_checks );
		$this->assertSame( 'success', $status );
	}

	/**
	 * HTTP passwords require the explicit zero opt-out before shared handling.
	 *
	 * @dataProvider http_password_arguments
	 * @param array $extra Additional CLI arguments.
	 */
	public function test_http_password_requires_explicit_opt_out( $extra ) {
		$args             = array_merge( $this->arguments(), array( 'adminpassword' => 'CliFixture42' ), $extra );
		$args['site-url'] = 'http://cli-add-site.example.org/';
		$shared_calls     = 0;
		$observe_shared   = static function ( $query ) use ( &$shared_calls ) {
			if ( false !== strpos( $query, 'mainwp_wp' ) ) {
				++$shared_calls;
			}
			return $query;
		};
		add_filter( 'query', $observe_shared );
		try {
			list( $status, $message ) = $this->run_command( $args );
		} finally {
			remove_filter( 'query', $observe_shared );
		}
		$this->assertSame( 'error', $status );
		$this->assertStringContainsString( 'HTTPS', $message );
		$this->assertStringContainsString( '--ssl_verify=0', $message );
		$this->assertStringNotContainsString( 'CliFixture42', $message );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 0, $shared_calls, 'HTTP refusal must precede the shared handler site lookup.' );
	}

	/**
	 * Supply HTTP inputs that do not explicitly opt out.
	 *
	 * @return array CLI argument fixtures.
	 */
	public function http_password_arguments() {
		return array(
			'omitted'        => array( array() ),
			'verify'         => array( array( 'ssl_verify' => '1' ) ),
			'global'         => array( array( 'ssl_verify' => '2' ) ),
			'empty-option'   => array( array( 'ssl_verify' => '' ) ),
			'empty-password' => array( array( 'adminpassword' => '' ) ),
			'zero-password'  => array( array( 'adminpassword' => '0' ) ),
		);
	}

	/**
	 * Only documented TLS choices may accompany the new password argument.
	 *
	 * @dataProvider invalid_tls_choices
	 * @param string $choice Unsupported verification choice.
	 */
	public function test_password_rejects_invalid_tls_choice( $choice ) {
		$args                     = $this->arguments();
		$args['adminpassword']    = 'CliFixture42';
		$args['ssl_verify']       = $choice;
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertStringContainsString( '--ssl_verify=0', $message );
		$this->assertStringContainsString( 'TLS', $message );
		$this->assertStringNotContainsString( $args['adminpassword'], $message );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Supply values that disable verification without the documented opt-out.
	 *
	 * @return array Invalid verification fixtures.
	 */
	public function invalid_tls_choices() {
		return array( array( '' ), array( 'invalid' ), array( 'false' ), array( '00' ), array( '0suffix' ) );
	}

	/**
	 * An untrusted HTTPS certificate fails when verification is omitted.
	 *
	 * @dataProvider certificate_errors
	 * @param string $diagnostic Native cURL diagnostic.
	 */
	public function test_untrusted_https_password_fails_with_secure_default( $diagnostic ) {
		$args                        = $this->arguments();
		$args['adminpassword']       = 'CliFixture42';
		$this->untrusted_certificate = true;
		$this->transport_error       = $diagnostic;
		list( $status, $message )    = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertSame( array( true, true ), $this->certificate_checks );
		$this->assertStringContainsString( $diagnostic, $message );
		$this->assertStringContainsString( 'TLS certificate verification failed', $message );
		$this->assertStringContainsString( '--ssl_verify=0', $message );
		$this->assertStringNotContainsString( $args['adminpassword'], $message );
		$this->assertCount( 2, $this->requests );
		$this->assertCount( 0, MainWP_DB::instance()->get_websites_by_url( $args['site-url'] ) );
	}

	/**
	 * Supply certificate chain and hostname verification failures.
	 *
	 * @return array Certificate diagnostics.
	 */
	public function certificate_errors() {
		return array(
			array( 'SSL certificate problem: self-signed certificate' ),
			array( 'SSL: no alternative certificate subject name matches target hostname' ),
		);
	}

	/**
	 * Credential rejection must not suggest disabling TLS verification.
	 */
	public function test_credential_failure_has_no_tls_opt_out_hint() {
		$args                     = $this->arguments();
		$args['adminpassword']    = 'CliFixture42';
		$this->reply              = array(
			'error'      => 'Administrator credentials rejected',
			'error_code' => 'REG_ERROR7',
		);
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertStringNotContainsString( '--ssl_verify=0', $message );
	}

	/**
	 * Other transport failures must not suggest disabling certificate checks.
	 *
	 * @dataProvider other_transport_errors
	 * @param string $diagnostic Native transport diagnostic.
	 */
	public function test_other_transport_failure_has_no_tls_opt_out_hint( $diagnostic ) {
		$args                        = $this->arguments();
		$args['adminpassword']       = 'CliFixture42';
		$args['ssl_verify']          = '1';
		$this->untrusted_certificate = true;
		$this->transport_error       = $diagnostic;
		list( $status, $message )    = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertStringContainsString( $diagnostic, $message );
		$this->assertStringNotContainsString( '--ssl_verify=0', $message );
	}

	/**
	 * Supply failures unrelated to certificate verification.
	 *
	 * @return array Transport diagnostics.
	 */
	public function other_transport_errors() {
		return array( array( 'Connection refused' ), array( 'SSL connect error' ) );
	}

	/**
	 * Explicit TLS choices and no-password calls retain their transport behavior.
	 *
	 * @dataProvider tls_compatibility_arguments
	 * @param string $scheme   URL scheme.
	 * @param array  $extra    Additional CLI arguments.
	 * @param bool   $expected Expected certificate verification flag.
	 */
	public function test_tls_choices_preserve_existing_behavior( $scheme, $extra, $expected ) {
		$args                        = array_merge( $this->arguments(), $extra );
		$args['site-url']            = $scheme . '://cli-add-site.example.org/';
		$this->untrusted_certificate = ! $expected;
		list( $status )              = $this->run_command( $args );
		$this->assertSame( array( $expected ), $this->certificate_checks );
		$this->assertSame( 'success', $status );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Supply explicit verification choices and legacy no-password calls.
	 *
	 * @return array Transport fixtures.
	 */
	public function tls_compatibility_arguments() {
		return array(
			'http-opt-out'      => array(
				'http',
				array(
					'adminpassword' => 'CliFixture42',
					'ssl_verify'    => '0',
				),
				false,
			),
			'https-opt-out'     => array(
				'https',
				array(
					'adminpassword' => 'CliFixture42',
					'ssl_verify'    => '0',
				),
				false,
			),
			'https-verify'      => array(
				'https',
				array(
					'adminpassword' => 'CliFixture42',
					'ssl_verify'    => '1',
				),
				true,
			),
			'https-global'      => array(
				'https',
				array(
					'adminpassword' => 'CliFixture42',
					'ssl_verify'    => '2',
				),
				true,
			),
			'http-no-password'  => array( 'http', array(), false ),
			'https-no-password' => array( 'https', array(), false ),
			'legacy-verify'     => array( 'https', array( 'ssl_verify' => '1' ), true ),
			'legacy-opt-out'    => array( 'http', array( 'ssl_verify' => '0' ), false ),
			'legacy-empty'      => array( 'https', array( 'ssl_verify' => '' ), false ),
			'legacy-invalid'    => array( 'https', array( 'ssl_verify' => 'invalid' ), false ),
		);
	}

	/**
	 * The CLI adapter retains the raw password.
	 */
	public function test_password_argument_is_retained_unchanged() {
		$args                  = $this->arguments();
		$args['adminpassword'] = 'CliFixture!+&=%';
		$fields                = MainWP_WP_CLI_Handle::get_cli_params( array(), $args, 'add-site' );
		$this->assertTrue( isset( $fields['adminpassword'] ) && $args['adminpassword'] === $fields['adminpassword'], 'CLI must retain the supplied password.' );
	}

	/**
	 * Registration carries the password as userpwd.
	 */
	public function test_password_reaches_serialized_child_request() {
		$args                  = $this->arguments();
		$args['adminpassword'] = 'CliFixture!+&=%';
		$this->run_command( $args );
		$this->assertCount( 1, $this->requests );
		parse_str( $this->requests[0], $post );
		$this->assertTrue( rawurlencode( $args['adminpassword'] ) === $post['userpwd'], 'Serialized registration must carry the encoded password.' );
		$this->assertTrue( rawurldecode( $post['userpwd'] ) === $args['adminpassword'], 'Child decoding must recover the supplied password.' );
	}

	/**
	 * Registration preserves the original password bytes.
	 *
	 * @dataProvider password_bytes
	 * @param string $password Password fixture.
	 */
	public function test_password_bytes_reach_serialized_child_request( $password ) {
		$args                  = $this->arguments();
		$args['adminpassword'] = $password;
		$fields                = MainWP_WP_CLI_Handle::get_cli_params( array(), $args, 'add-site' );
		$this->assertTrue( $password === $fields['adminpassword'], 'CLI parsing must preserve the original password bytes.' );
		$this->run_command( $args );
		$this->assertCount( 1, $this->requests );
		parse_str( $this->requests[0], $post );
		$this->assertTrue( rawurlencode( $password ) === $post['userpwd'], 'Serialized registration must carry the encoded password.' );
		$this->assertTrue( rawurldecode( $post['userpwd'] ) === $password, 'Child decoding must recover the original password bytes.' );
		$this->assertTrue( rawurldecode( wp_slash( $post['userpwd'] ) ) === $password, 'Child decoding must preserve bytes after WordPress request slashing.' );
	}

	/**
	 * Supply password characters affected by registration encoding.
	 *
	 * @return array Password fixtures.
	 */
	public function password_bytes() {
		return array(
			'backslash'              => array( 'Cli\\Fixture42' ),
			'double-backslash'       => array( 'Cli\\\\Fixture42' ),
			'backslash-single-quote' => array( "Cli\\'Fixture42" ),
			'backslash-double-quote' => array( 'Cli\\"Fixture42' ),
			'literal-quotes'         => array( 'Cli\'"Fixture42' ),
			'backslash-only'         => array( '\\' ),
			'trailing-backslash'     => array( 'CliFixture42\\' ),
			'single-quote'           => array( "Cli'Fixture42" ),
			'double-quote'           => array( 'Cli"Fixture42' ),
			'percent'                => array( 'Cli%41Fixture42' ),
			'plus'                   => array( 'Cli+Fixture42' ),
			'space'                  => array( 'Cli Fixture42' ),
			'ampersand'              => array( 'Cli&Fixture42' ),
			'equals'                 => array( 'Cli=Fixture42' ),
			'non-ascii'              => array( 'Clié漢Fixture42' ),
			'plain'                  => array( 'CliFixture42' ),
		);
	}

	/**
	 * Direct shared-handler callers retain their existing unslashing and result.
	 *
	 * @dataProvider shared_handler_passwords
	 * @param string $input    Shared-handler input.
	 * @param string $expected Expected wire password.
	 */
	public function test_shared_handler_password_semantics_are_unchanged( $input, $expected ) {
		$fields = array(
			'url'           => $this->arguments()['site-url'],
			'name'          => 'CLI site',
			'admin'         => 'child-admin',
			'adminpassword' => $input,
		);
		$result = MainWP_Manage_Sites_Handler::rest_api_add_site( $fields );
		$this->assertCount( 1, $this->requests );
		parse_str( $this->requests[0], $post );
		$this->assertTrue( $expected === $post['userpwd'], 'Direct shared-handler input must retain existing wire semantics.' );
		$sites = MainWP_DB::instance()->get_websites_by_url( $fields['url'] );
		$this->assertCount( 1, $sites );
		$id = (int) $sites[0]->id;
		$this->assertSame(
			array(
				'response' => sprintf( 'Site successfully added - Visit the Site&#039;s %1$sDashboard%2$s now.%3$s', '<a href="admin.php?page=managesites&dashboard=' . $id . '" style="text-decoration: none;" title="Dashboard">', '</a>', '<br/>' ),
				'siteid'   => $id,
			),
			$result
		);
		$this->assertSame( array(), Add_Site_CLI_Output_Double::$messages );
	}

	/**
	 * Supply WordPress-slashed and raw shared-handler inputs.
	 *
	 * @return array Input and wire fixtures.
	 */
	public function shared_handler_passwords() {
		$password = 'Cli\\\'"Fixture42';
		return array(
			'wordpress-slashed' => array( wp_slash( $password ), $password ),
			'raw'               => array( $password, wp_unslash( $password ) ),
		);
	}

	/**
	 * Password adaptation leaves every other allowed CLI field unchanged.
	 */
	public function test_password_preserves_all_other_allowed_fields() {
		$group_id = MainWP_DB_Common::instance()->add_group( get_current_user_id(), 'CLI fixture group' );
		$this->assertGreaterThan( 0, $group_id );
		$args     = array_merge(
			$this->arguments(),
			array(
				'name'           => 'CLI\\\'" site',
				'admin'          => 'child\\admin',
				'adminpassword'  => 'Cli\\Fixture42',
				'uniqueid'       => 'Fixture\\Id42',
				'ssl_verify'     => '1',
				'force_use_ipv4' => '0',
				'ssl_version'    => '6',
				'http_user'      => 'http\\user',
				'http_pass'      => 'Http\\\'"Fixture42',
				'groupids'       => (string) $group_id,
			)
		);
		$expected = $args;
		unset( $expected['add-site'], $expected['site-url'] );
		$expected['url'] = $args['site-url'];
		$this->assertSame( $expected, MainWP_WP_CLI_Handle::get_cli_params( array(), $args, 'add-site' ) );
		$this->reply              = array( 'register' => 'NO' );
		list( $status, $message ) = $this->run_command( $args );
		unset( $expected['adminpassword'] );
		$result = MainWP_Manage_Sites_Handler::rest_api_add_site( $expected );
		$this->assertCount( 2, $this->requests );
		parse_str( $this->requests[0], $cli_post );
		parse_str( $this->requests[1], $shared_post );
		$this->assertTrue( rawurlencode( $args['adminpassword'] ) === $cli_post['userpwd'], 'CLI adaptation must encode the password.' );
		$this->assertTrue( rawurldecode( $cli_post['userpwd'] ) === $args['adminpassword'], 'Child decoding must preserve the password.' );
		$cli_post['userpwd'] = '';
		$this->assertSame( $shared_post, $cli_post );
		$this->assertSame( 'error', $status );
		$this->assertSame( '  -> Add site result: ' . print_r( $result, true ), $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Compare existing shared-handler result bytes.
	}

	/**
	 * Omitting the password preserves the fields, request and success bytes.
	 */
	public function test_without_password_preserves_fields_request_and_success_output() {
		$args = $this->arguments();
		$this->assertSame(
			array(
				'name'  => 'CLI site',
				'admin' => 'child-admin',
				'url'   => $args['site-url'],
			),
			MainWP_WP_CLI_Handle::get_cli_params( array(), $args, 'add-site' )
		);
		list( $status, $message ) = $this->run_command( $args );
		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			http_build_query(
				array(
					'user'      => 'child-admin',
					'function'  => 'register',
					'mainwpver' => MainWP_System::$version,
					'pubkey'    => '-1',
					'server'    => get_admin_url(),
					'uniqueId'  => '',
					'userpwd'   => '',
				),
				'',
				'&'
			),
			$this->requests[0]
		);
		$sites = MainWP_DB::instance()->get_websites_by_url( $args['site-url'] );
		$this->assertCount( 1, $sites );
		$id       = (int) $sites[0]->id;
		$response = sprintf( 'Site successfully added - Visit the Site&#039;s %1$sDashboard%2$s now.%3$s', '<a href="admin.php?page=managesites&dashboard=' . $id . '" style="text-decoration: none;" title="Dashboard">', '</a>', '<br/>' );
		$this->assertSame( 'success', $status );
		$this->assertSame(
			'  -> Add site result: ' . print_r( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Assert the existing CLI output bytes.
				array(
					'response' => $response,
					'siteid'   => $id,
				),
				true
			),
			$message
		);
	}

	/**
	 * Omitting the password preserves the error bytes.
	 */
	public function test_without_password_preserves_error_output() {
		$this->reply              = array( 'register' => 'NO' );
		list( $status, $message ) = $this->run_command( $this->arguments() );
		$this->assertSame( 'error', $status );
		$this->assertSame( '  -> Add site result: ' . print_r( array( 'error' => 'Undefined error occurred. Please try again. For additional help, contact the MainWP Support.' ), true ), $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Assert the existing CLI output bytes.
	}

	/**
	 * Success output conceals a matching password.
	 */
	public function test_success_output_redacts_password() {
		$args                     = $this->arguments();
		$args['adminpassword']    = 'Site successfully added';
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'success', $status );
		$this->assertFalse( strpos( $message, $args['adminpassword'] ), 'Success output must not contain the submitted password.' );
		$this->assertStringContainsString( '[redacted]', $message );
	}

	/**
	 * Reflected wire passwords remain concealed after CLI adaptation.
	 *
	 * @dataProvider password_bytes
	 * @param string $password Password fixture.
	 */
	public function test_error_output_redacts_original_password_bytes( $password ) {
		$args                     = $this->arguments();
		$args['adminpassword']    = $password;
		$this->reply              = array( 'error' => rawurlencode( 'Rejected value ' . $password ) );
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		foreach ( array( $password, esc_html( $password ), MainWP_Utility::esc_content( $password ) ) as $variant ) {
			$this->assertFalse( strpos( $message, $variant ), 'Error output must conceal the original password forms.' );
		}
		$this->assertStringContainsString( '[redacted]', $message );
	}

	/**
	 * Child errors can reflect the encoded registration password.
	 *
	 * @dataProvider password_bytes
	 * @param string $password Password fixture.
	 */
	public function test_error_output_redacts_encoded_password( $password ) {
		$args                     = $this->arguments();
		$args['adminpassword']    = $password;
		$encoded                  = rawurlencode( $password );
		$this->reply              = array( 'error' => rawurlencode( 'Rejected value ' . $encoded ) );
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertFalse( strpos( $message, $encoded ), 'Error output must conceal the encoded registration password.' );
		$this->assertStringContainsString( '[redacted]', $message );
	}

	/**
	 * Error output conceals the password forms a Child can reflect.
	 *
	 * @dataProvider reflected_passwords
	 * @param string $password Password fixture.
	 */
	public function test_error_output_redacts_reflected_password( $password ) {
		$args                     = $this->arguments();
		$args['adminpassword']    = $password;
		$this->reply              = array( 'error' => rawurlencode( 'Rejected value ' . wp_unslash( $password ) ) );
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertFalse( strpos( $message, wp_unslash( $password ) ), 'Error output must not contain the transmitted password.' );
		$this->assertFalse( strpos( $message, esc_html( wp_unslash( $password ) ) ), 'Error output must not contain the escaped password.' );
		$this->assertFalse( strpos( $message, MainWP_Utility::esc_content( wp_unslash( $password ) ) ), 'Error output must not contain the sanitized password.' );
		$this->assertStringContainsString( '[redacted]', $message );
	}

	/**
	 * Supply reflected password forms.
	 *
	 * @return array Password fixtures.
	 */
	public function reflected_passwords() {
		return array(
			'plain'     => array( 'CliFixtureValue42' ),
			'unslashed' => array( 'Cli\\FixtureValue42' ),
			'escaped'   => array( 'Cli&Fixture"Value42' ),
			'zero'      => array( '0' ),
		);
	}

	/**
	 * Missing required fields fail before registration.
	 *
	 * @dataProvider required_fields
	 * @param string $field Required argument name.
	 */
	public function test_required_fields_are_unchanged( $field ) {
		$args = $this->arguments();
		unset( $args[ $field ] );
		list( $status, $message ) = $this->run_command( $args );
		$this->assertSame( 'error', $status );
		$this->assertSame( 'Missing field: ' . $field, $message );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Supply the required add-site argument names.
	 *
	 * @return array Required fields.
	 */
	public function required_fields() {
		return array( array( 'site-url' ), array( 'name' ), array( 'admin' ) );
	}
}
