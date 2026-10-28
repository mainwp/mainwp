<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Match the PHPUnit test filenames.
/**
 * CLI password value refusals and registration compatibility.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_WP_CLI_Handle;

/** Minimal command parent for the WordPress harness. */
class Password_Value_CLI_Command_Double {}

/** Capture command output without exiting PHPUnit. */
class Password_Value_CLI_Output_Double {
	/**
	 * Captured command messages.
	 *
	 * @var array
	 */
	public static $messages = array();

	/**
	 * Capture a successful result.
	 *
	 * @param string $message Command output.
	 */
	public static function success( $message ) {
		self::$messages[] = array( 'success', $message );
	}

	/**
	 * Capture an error and simulate the command exit.
	 *
	 * @param string $message Command output.
	 * @throws Password_Value_CLI_Error After capturing the error.
	 */
	public static function error( $message ) {
		self::$messages[] = array( 'error', $message );
		throw new Password_Value_CLI_Error();
	}
}

/** Expected command error exit. */
class Password_Value_CLI_Error extends \RuntimeException {}

/** Exercise password validation through the real callback and registration. */
class Test_WP_CLI_Add_Site_Password_Value extends \WP_UnitTestCase {
	/**
	 * Serialized registration requests.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * TLS policies passed to transport.
	 *
	 * @var array
	 */
	private $certificate_checks = array();

	/** Load the handler with whichever output double owns WP_CLI. */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		// False preserves normal capability checks while loading the CLI handler.
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', false );
		}
		if ( ! defined( 'MAINWP_DEV_FILTERS_ENABLED' ) ) {
			define( 'MAINWP_DEV_FILTERS_ENABLED', true );
		}
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			class_alias( Password_Value_CLI_Command_Double::class, 'WP_CLI_Command' );
		}
		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( Password_Value_CLI_Output_Double::class, 'WP_CLI' );
		}
		require_once dirname( __DIR__ ) . '/class/class-mainwp-wp-cli-handle.php';
	}

	/** Prepare an administrator and intercept registration transport. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\WP_CLI::$messages = array();
		add_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		add_filter( 'mainwp_dev_return_data_before_connect_site', array( $this, 'capture_request' ), 10, 6 );
		add_filter( 'mainwp_fetch_url_authed_pre', '__return_empty_array' );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
	}

	/** Remove transport hooks and captured output. */
	public function tear_down() {
		remove_filter( 'mainwp_add_site_skip_key_generation', '__return_true' );
		remove_filter( 'mainwp_dev_return_data_before_connect_site', array( $this, 'capture_request' ), 10 );
		remove_filter( 'mainwp_fetch_url_authed_pre', '__return_empty_array' );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		\WP_CLI::$messages = array();
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
	 * @param mixed  $pre Existing development response.
	 * @param object $website Site being registered.
	 * @param string $url Registration endpoint.
	 * @param string $postdata Serialized request.
	 * @param bool   $check_constraints Whether transport checks site constraints.
	 * @param int    $verify_certificate TLS policy.
	 * @return array Child response fixture.
	 */
	public function capture_request( $pre, $website, $url, $postdata, $check_constraints, $verify_certificate ) {
		$this->requests[]           = $postdata;
		$this->certificate_checks[] = $verify_certificate;
		return array( 'register' => 'OK' );
	}

	/**
	 * Build the required add-site arguments.
	 *
	 * @return array Command arguments.
	 */
	private function arguments() {
		return array(
			'add-site' => true,
			'site-url' => 'https://cli-password-value.example.org/',
			'name'     => 'CLI password value site',
			'admin'    => 'child-admin',
		);
	}

	/**
	 * Run the real callback and capture its result.
	 *
	 * @param array $args Command arguments.
	 * @return array Output status and message.
	 */
	private function run_command( $args ) {
		try {
			MainWP_WP_CLI_Handle::callback_site_add_site( array(), $args );
		} catch ( Password_Value_CLI_Error $error ) {
			// WP_CLI::error terminates the standalone command after its message.
		} catch ( Add_Site_CLI_Error $error ) {
			// The original add-site suite can own the global WP_CLI alias.
		}
		$this->assertCount( 1, \WP_CLI::$messages );
		return \WP_CLI::$messages[0];
	}

	/**
	 * Unsupported supplied values stop before shared registration or transport.
	 *
	 * @dataProvider refused_values
	 * @param mixed $value Supplied password value.
	 * @param array $extra Additional command arguments.
	 */
	public function test_refuses_invalid_password_before_registration( $value, $extra ) {
		$args                  = array_merge( $this->arguments(), $extra );
		$args['adminpassword'] = $value;
		$shared_calls          = 0;
		$observe_shared        = static function ( $query ) use ( &$shared_calls ) {
			if ( false !== strpos( $query, 'mainwp_wp' ) ) {
				++$shared_calls;
			}
			return $query;
		};
		add_filter( 'query', $observe_shared );
		try {
			list( $status, $message ) = $this->run_command( $args );
		} catch ( \Throwable $error ) {
			$this->fail( 'Password refusal raised an unexpected ' . get_class( $error ) . '.' );
		} finally {
			remove_filter( 'query', $observe_shared );
		}
		$this->assertSame( 'error', $status );
		$this->assertTrue( '--adminpassword needs a value. Use --adminpassword=<password>.' === $message, 'Expected the password value error.' );
		$this->assertSame( 0, $shared_calls, 'Password refusal must precede the shared handler site lookup.' );
		$this->assertCount( 0, $this->requests, 'Password refusal must precede outgoing registration.' );
	}

	/**
	 * Values retained by the existing isset mapper.
	 *
	 * @return array Invalid password fixtures.
	 */
	public function refused_values() {
		return array(
			'bare-true'         => array( true, array() ),
			'boolean-false'     => array( false, array() ),
			'empty-string'      => array( '', array() ),
			'integer-zero'      => array( 0, array() ),
			'integer-one'       => array( 1, array() ),
			'float'             => array( 1.5, array() ),
			'array'             => array( array( 'fixture' ), array() ),
			'object'            => array( new \stdClass(), array() ),
			'empty-http'        => array( '', array( 'site-url' => 'http://cli-password-value.example.org/' ) ),
			'bare-http-opt-out' => array(
				true,
				array(
					'site-url'   => 'http://cli-password-value.example.org/',
					'ssl_verify' => '0',
				),
			),
			'false-invalid-tls' => array( false, array( 'ssl_verify' => 'invalid' ) ),
		);
	}

	/**
	 * Nonempty strings retain their bytes and TLS policy through registration.
	 *
	 * @dataProvider accepted_values
	 * @param string $password Supplied value.
	 * @param string $wire Expected encoded password.
	 * @param array  $extra Additional arguments.
	 * @param int    $policy Expected TLS policy.
	 */
	public function test_valid_password_preserves_registration( $password, $wire, $extra, $policy ) {
		$args                  = array_merge( $this->arguments(), $extra );
		$args['adminpassword'] = $password;
		update_option( 'mainwp_sslVerifyCertificate', 0 );
		list( $status ) = $this->run_command( $args );
		$this->assertSame( 'success', $status );
		$this->assertCount( 1, $this->requests );
		parse_str( $this->requests[0], $request );
		$this->assertTrue( isset( $request['userpwd'] ) && $request['userpwd'] === $wire, 'Registration must preserve the encoded password.' );
		$this->assertTrue( rawurldecode( $request['userpwd'] ) === $password, 'Registration must preserve the supplied password bytes.' );
		$this->assertTrue( rawurldecode( wp_slash( $request['userpwd'] ) ) === $password, 'Child decoding must preserve the supplied password bytes.' );
		$this->assertSame( array( $policy ), $this->certificate_checks );
	}

	/**
	 * Special characters and string zero across supported TLS choices.
	 *
	 * @return array Registration fixtures.
	 */
	public function accepted_values() {
		$values = array(
			'special' => array( "P\\\"'%+ é&=42", 'P%5C%22%27%25%2B%20%C3%A9%26%3D42' ),
			'zero'    => array( '0', '0' ),
		);
		$cases  = array();
		foreach ( $values as $name => $value ) {
			$cases[ $name . '-default' ]      = array( $value[0], $value[1], array(), 1 );
			$cases[ $name . '-verify' ]       = array( $value[0], $value[1], array( 'ssl_verify' => '1' ), 1 );
			$cases[ $name . '-global' ]       = array( $value[0], $value[1], array( 'ssl_verify' => '2' ), 2 );
			$cases[ $name . '-opt-out' ]      = array( $value[0], $value[1], array( 'ssl_verify' => '0' ), 0 );
			$cases[ $name . '-http-opt-out' ] = array(
				$value[0],
				$value[1],
				array(
					'ssl_verify' => '0',
					'site-url'   => 'http://cli-password-value.example.org/',
				),
				0,
			);
		}
		return $cases;
	}

	/**
	 * An absent password and a mapper-dropped null retain legacy registration.
	 *
	 * @dataProvider absent_values
	 * @param array $extra Additional arguments.
	 */
	public function test_absent_password_preserves_registration( $extra ) {
		$args             = array_merge( $this->arguments(), $extra );
		$args['site-url'] = 'http://cli-password-value.example.org/';
		$fields           = MainWP_WP_CLI_Handle::get_cli_params( array(), $args, 'add-site' );
		$this->assertFalse( array_key_exists( 'adminpassword', $fields ), 'The mapper must omit absent or null passwords.' );
		list( $status ) = $this->run_command( $args );
		$this->assertSame( 'success', $status );
		$this->assertCount( 1, $this->requests );
		parse_str( $this->requests[0], $request );
		$this->assertTrue( isset( $request['userpwd'] ) && '' === $request['userpwd'], 'Legacy registration must retain its empty password field.' );
		$this->assertSame( array( 2 ), $this->certificate_checks );
	}

	/**
	 * Null is dropped by the mapper rather than treated as a supplied value.
	 *
	 * @return array Legacy argument fixtures.
	 */
	public function absent_values() {
		return array(
			'absent' => array( array() ),
			'null'   => array( array( 'adminpassword' => null ) ),
		);
	}
}
