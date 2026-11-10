<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Match the PHPUnit test filenames.
/**
 * CLI help, unignore and bulk disconnect tests (MWP-2154).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Encrypt_Data_Lib;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_WP_CLI_Command;
use MainWP\Dashboard\MainWP_WP_CLI_Handle;

/** Minimal command parent for loading the CLI classes in the WordPress harness. */
class Help_Unignore_CLI_Command_Double {}

/** Capture CLI output without terminating PHPUnit. */
class Help_Unignore_CLI_Output_Double {
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
	 * @throws Help_Unignore_CLI_Error After capturing the error.
	 */
	public static function error( $message ) {
		self::$messages[] = array( 'error', $message );
		throw new Help_Unignore_CLI_Error( $message );
	}

	/**
	 * Capture a warning.
	 *
	 * @param string $message Command output.
	 */
	public static function warning( $message ) {
		self::$messages[] = array( 'warning', $message );
	}

	/**
	 * Capture a plain output line.
	 *
	 * @param string $message Command output.
	 */
	public static function line( $message = '' ) {
		self::$messages[] = array( 'line', $message );
	}

	/**
	 * Return text unchanged; colour tokens do not matter to the assertions.
	 *
	 * @param string $text Text with colour tokens.
	 * @return string
	 */
	public static function colorize( $text ) {
		return $text;
	}
}

/** Expected CLI error exit. */
class Help_Unignore_CLI_Error extends \RuntimeException {}

/** Exercise the sites, site and updates commands through the real handler. */
class Test_WP_CLI_Help_Unignore_Disconnect extends \WP_UnitTestCase {

	const SLUG = 'akismet/akismet.php';

	/**
	 * Value arguments the handler reads alongside each command's flags.
	 *
	 * @var array
	 */
	const VALUE_ARGS = array(
		'sites'   => array(),
		'site'    => array( 'site-url', 'name', 'admin', 'adminpassword', 'ssl_verify', 'uniqueid', 'force_use_ipv4', 'ssl_version', 'sslversion', 'http_user', 'http_pass', 'groupids', 'type', 'slug', 'plugin', 'theme', 'action' ),
		'updates' => array( 'type', 'slug', 'name', 'ver' ),
	);

	/**
	 * Sites created by a test.
	 *
	 * @var int[]
	 */
	private $site_ids = array();

	/**
	 * Sites the disconnect stub was asked to contact, in order.
	 *
	 * @var int[]
	 */
	private $disconnect_calls = array();

	/**
	 * Site whose disconnect request fails.
	 *
	 * @var int
	 */
	private $failing_site_id = 0;

	/**
	 * Load the CLI classes with whichever output double owns WP_CLI.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		// The CLI files return early unless WP_CLI is defined; false keeps normal capability checks.
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', false );
		}
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			class_alias( Help_Unignore_CLI_Command_Double::class, 'WP_CLI_Command' );
		}
		if ( ! class_exists( 'WP_CLI' ) ) {
			class_alias( Help_Unignore_CLI_Output_Double::class, 'WP_CLI' );
		}
		require_once dirname( __DIR__ ) . '/class/class-mainwp-wp-cli-handle.php';
		require_once dirname( __DIR__ ) . '/class/class-mainwp-wp-cli-command.php';
	}

	/**
	 * Run as an administrator with no stored global ignores.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\WP_CLI::$messages = array();
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'stub_disconnect' ), 10, 3 );
	}

	/**
	 * Remove the transport stub, captured output and site key files.
	 */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'stub_disconnect' ), 10 );
		foreach ( $this->site_ids as $site_id ) {
			MainWP_Encrypt_Data_Lib::remove_key_file( $site_id );
		}
		\WP_CLI::$messages = array();
		parent::tear_down();
	}

	/**
	 * Stand in for the Child disconnect request.
	 *
	 * @param mixed  $pre     Short-circuit value.
	 * @param object $website Child site.
	 * @param string $what    Child action.
	 * @return mixed
	 * @throws MainWP_Exception For the failing site.
	 */
	public function stub_disconnect( $pre, $website, $what ) {
		if ( 'disconnect' !== $what ) {
			return $pre;
		}
		$this->disconnect_calls[] = (int) $website->id;
		if ( (int) $website->id === $this->failing_site_id ) {
			throw new MainWP_Exception( 'Child unreachable' );
		}
		return array( 'result' => 'success' );
	}

	/**
	 * Add a child site row.
	 *
	 * @param string $host Host name; sites are listed in URL order.
	 * @return int Site ID.
	 */
	private function add_site( $host ) {
		$site_id = MainWP_DB::instance()->add_website(
			get_current_user_id(),
			'CLI ' . $host,
			'https://' . $host . '-' . wp_generate_uuid4() . '.example/',
			'admin',
			base64_encode( 'pub-' . wp_generate_uuid4() ),
			base64_encode( 'priv-' . wp_generate_uuid4() ),
			array(
				'http_user' => '',
				'http_pass' => '',
			)
		);
		$this->assertNotFalse( $site_id );
		$this->site_ids[] = (int) $site_id;
		return (int) $site_id;
	}

	/**
	 * Run a command, keeping the simulated error exit as data.
	 *
	 * @param string $command    sites, site or updates.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Flags.
	 * @return string|null Error message, or null when the command did not fail.
	 * @throws \RuntimeException Anything other than a WP_CLI::error() exit.
	 */
	private function run_cli( $command, $args, $assoc_args ) {
		\WP_CLI::$messages = array();
		try {
			MainWP_WP_CLI_Handle::handle_cli_callback( $command, $args, $assoc_args );
		} catch ( \RuntimeException $e ) {
			return $this->cli_error_or_rethrow( $e );
		}
		return null;
	}

	/**
	 * Return the WP_CLI::error() message behind a caught exit.
	 *
	 * The doubles throw a RuntimeException, and so do PHPUnit's converted PHP
	 * warnings, so an exit only counts when the last captured message is the error.
	 *
	 * @param \RuntimeException $e Caught exception.
	 * @return string
	 * @throws \RuntimeException When the exception was not a CLI error exit.
	 */
	private function cli_error_or_rethrow( \RuntimeException $e ) {
		$last = end( \WP_CLI::$messages );
		if ( false === $last || 'error' !== $last[0] ) {
			throw $e;
		}
		return $last[1];
	}

	/**
	 * Last captured message of one kind.
	 *
	 * @param string $kind success, error, warning or line.
	 * @return string|null
	 */
	private function last_message( $kind ) {
		$found = null;
		foreach ( \WP_CLI::$messages as $message ) {
			if ( $kind === $message[0] ) {
				$found = $message[1];
			}
		}
		return $found;
	}

	/**
	 * The help text WP-CLI reads for a command method, without docblock decoration.
	 *
	 * @param string $method Command method.
	 * @return string
	 */
	private function help_text( $method ) {
		$doc = ( new \ReflectionMethod( MainWP_WP_CLI_Command::class, $method ) )->getDocComment();
		// Same decoration stripping as WP_CLI\DocParser.
		$doc = preg_replace( '|^/\*\*[\r\n]+|', '', $doc );
		$doc = preg_replace( '|\n[\t ]*\*/$|', '', $doc );
		return preg_replace( '|^[\t ]*\* ?|m', '', $doc );
	}

	/**
	 * Flags documented in a command's OPTIONS section.
	 *
	 * @param string $help Help text.
	 * @return string[]
	 */
	private function documented_flags( $help ) {
		$this->assertSame( 1, preg_match( '/^## OPTIONS\n(.*?)^## /ms', $help, $section ) );
		preg_match_all( '/^\[--([a-z0-9_-]+)/m', $section[1], $flags );
		return $flags[1];
	}

	/**
	 * Commands whose help is checked.
	 *
	 * @return array
	 */
	public function commands() {
		return array(
			'sites'   => array( 'sites' ),
			'site'    => array( 'site' ),
			'updates' => array( 'updates' ),
		);
	}

	/**
	 * Every handler flag is documented and every documented flag is one the handler reads.
	 *
	 * @dataProvider commands
	 *
	 * @param string $command Command.
	 */
	public function test_help_lists_exactly_the_flags_the_handler_accepts( $command ) {
		$documented = $this->documented_flags( $this->help_text( $command ) );
		$handled    = MainWP_WP_CLI_Handle::get_assoc_handle_commands( $command );

		$this->assertSame( array(), array_values( array_diff( $handled, $documented ) ), 'Handler flags missing from wp help.' );
		$this->assertSame( array(), array_values( array_diff( $documented, $handled, self::VALUE_ARGS[ $command ] ) ), 'wp help lists flags the handler ignores.' );
		$this->assertSame( count( $documented ), count( array_unique( $documented ) ), 'A flag is documented twice.' );
	}

	/**
	 * Each option has a description, and none uses the ": " form that turns on WP-CLI validation.
	 *
	 * @dataProvider commands
	 *
	 * @param string $command Command.
	 */
	public function test_help_describes_options_without_enabling_argument_validation( $command ) {
		$help = $this->help_text( $command );

		// WP-CLI builds a synopsis, and validates arguments against it, from lines followed by ":".
		$this->assertSame( 0, preg_match( '/(.+?)[\r\n]+:/', $help ) );
		$this->assertStringNotContainsString( '## Synopsis', $help );
		$this->assertSame( 1, preg_match( '/^## EXAMPLES\n\n {4}wp mainwp ' . $command . '\b/m', $help ) );

		$this->assertSame( 1, preg_match( '/^## OPTIONS\n(.*?)^## /ms', $help, $section ) );
		$entries = preg_split( '/\n\n+/', trim( $section[1] ) );
		foreach ( $entries as $entry ) {
			$this->assertSame( 1, preg_match( '/^\[[^\n]+\]\n {4}\S[^\n]+$/', $entry ), "Option without a one-line description:\n" . $entry );
		}
	}

	/**
	 * The removed --list and --count flags are gone from wp help mainwp sites.
	 */
	public function test_sites_help_drops_flags_that_do_not_exist() {
		$documented = $this->documented_flags( $this->help_text( 'sites' ) );
		$this->assertNotContains( 'list', $documented );
		$this->assertNotContains( 'count', $documented );
	}

	/**
	 * --unignore-updates clears a global ignore written by --ignore-updates.
	 */
	public function test_unignore_updates_clears_the_global_ignore() {
		$this->assertNull(
			$this->run_cli(
				'updates',
				array(),
				array(
					'ignore-updates' => true,
					'type'           => 'plugin',
					'slug'           => self::SLUG,
					'name'           => 'Akismet',
				)
			)
		);
		$this->assertArrayHasKey( self::SLUG, $this->global_ignored_plugins() );

		$this->assertNull(
			$this->run_cli(
				'updates',
				array(),
				array(
					'unignore-updates' => true,
					'type'             => 'plugin',
					'slug'             => self::SLUG,
				)
			)
		);
		$this->assertArrayNotHasKey( self::SLUG, $this->global_ignored_plugins() );
	}

	/**
	 * --unignore-update resolves the site ID and clears that site's ignore.
	 */
	public function test_unignore_update_clears_the_site_ignore() {
		$site_id = $this->add_site( 'unignore' );
		$this->assertNull(
			$this->run_cli(
				'updates',
				array( (string) $site_id ),
				array(
					'ignore-update' => true,
					'type'          => 'plugin',
					'slug'          => self::SLUG,
					'name'          => 'Akismet',
				)
			)
		);
		$this->assertArrayHasKey( self::SLUG, $this->site_ignored_plugins( $site_id ) );

		$this->assertNull(
			$this->run_cli(
				'updates',
				array( (string) $site_id ),
				array(
					'unignore-update' => true,
					'type'            => 'plugin',
					'slug'            => self::SLUG,
				)
			)
		);
		$this->assertArrayNotHasKey( self::SLUG, $this->site_ignored_plugins( $site_id ) );
	}

	/**
	 * --ver unignores one version and keeps the others ignored.
	 */
	public function test_unignore_update_with_ver_keeps_other_versions_ignored() {
		$site_id = $this->add_site( 'versions' );
		$ignored = array(
			self::SLUG => array(
				'Name'             => 'Akismet',
				'ignored_versions' => array( '5.1', '5.2' ),
			),
		);
		MainWP_DB::instance()->update_website_values( $site_id, array( 'ignored_plugins' => wp_json_encode( $ignored ) ) );

		$this->assertNull(
			$this->run_cli(
				'updates',
				array( (string) $site_id ),
				array(
					'unignore-update' => true,
					'type'            => 'plugin',
					'slug'            => self::SLUG,
					'ver'             => '5.1',
				)
			)
		);
		$this->assertSame( array( '5.2' ), array_values( $this->site_ignored_plugins( $site_id )[ self::SLUG ]['ignored_versions'] ) );
	}

	/**
	 * --ver values that would otherwise unignore every version.
	 *
	 * @return array
	 */
	public function empty_versions() {
		return array(
			'bare flag'  => array( true ),
			'whitespace' => array( '   ' ),
			'zero'       => array( '0' ),
		);
	}

	/**
	 * A bare or blank --ver is refused on a site instead of unignoring every version.
	 *
	 * @dataProvider empty_versions
	 *
	 * @param mixed $ver --ver value as WP-CLI passes it.
	 */
	public function test_unignore_update_refuses_empty_ver( $ver ) {
		$site_id = $this->add_site( 'blank-ver' );
		$this->assertNull(
			$this->run_cli(
				'updates',
				array( (string) $site_id ),
				array(
					'ignore-update' => true,
					'type'          => 'plugin',
					'slug'          => self::SLUG,
					'name'          => 'Akismet',
				)
			)
		);

		$error = $this->run_cli(
			'updates',
			array( (string) $site_id ),
			array(
				'unignore-update' => true,
				'type'            => 'plugin',
				'slug'            => self::SLUG,
				'ver'             => $ver,
			)
		);
		$this->assertStringStartsWith( '--ver needs a value.', (string) $error );
		$this->assertArrayHasKey( self::SLUG, $this->site_ignored_plugins( $site_id ) );
	}

	/**
	 * --unignore-update with an unknown site ID stops with an error.
	 */
	public function test_unignore_update_unknown_site_is_an_error() {
		$this->assertSame(
			'Site not found.',
			$this->run_cli(
				'updates',
				array( '999999' ),
				array(
					'unignore-update' => true,
					'type'            => 'plugin',
					'slug'            => self::SLUG,
				)
			)
		);
	}

	/**
	 * --unignore-update without a site ID stops with the empty-ID error, not a PHP warning.
	 */
	public function test_unignore_update_without_site_id_is_an_error() {
		$this->assertSame(
			'Empty site id.',
			$this->run_cli(
				'updates',
				array(),
				array(
					'unignore-update' => true,
					'type'            => 'plugin',
					'slug'            => self::SLUG,
				)
			)
		);
	}

	/**
	 * The unignore-update callback refuses a missing site itself.
	 */
	public function test_unignore_update_callback_refuses_missing_site() {
		try {
			MainWP_WP_CLI_Handle::callback_updates_unignore_update(
				array(),
				array(
					'type' => 'plugin',
					'slug' => self::SLUG,
				),
				false
			);
			$this->fail( 'Expected a CLI error.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Site not found.', $this->cli_error_or_rethrow( $e ) );
		}
	}

	/**
	 * --disconnect-sites keeps going after a failed site and fails at the end.
	 */
	public function test_disconnect_sites_tries_every_site() {
		$failing               = $this->add_site( 'a-fails' );
		$working               = $this->add_site( 'b-works' );
		$this->failing_site_id = $failing;

		$error = $this->run_cli( 'sites', array( $failing . ',' . $working ), array( 'disconnect-sites' => true ) );

		$this->assertSame( array( $failing, $working ), $this->disconnect_calls );
		$this->assertStringContainsString( 'Disconnect failed: ', (string) $this->last_message( 'warning' ) );
		$this->assertSame( 'Disconnect completed with errors', $error );
	}

	/**
	 * --disconnect-sites succeeds when every site disconnects.
	 */
	public function test_disconnect_sites_succeeds_when_no_site_fails() {
		$first  = $this->add_site( 'a-first' );
		$second = $this->add_site( 'b-second' );

		$this->assertNull( $this->run_cli( 'sites', array( $first . ',' . $second ), array( 'disconnect-sites' => true ) ) );
		$this->assertSame( array( $first, $second ), $this->disconnect_calls );
		$this->assertSame( 'Disconnect completed', $this->last_message( 'success' ) );
	}

	/**
	 * Global ignored plugins for the current user.
	 *
	 * @return array
	 */
	private function global_ignored_plugins() {
		$decoded = json_decode( (string) MainWP_DB_Common::instance()->get_user_extension()->ignored_plugins, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Ignored plugins stored on one site.
	 *
	 * @param int $site_id Site ID.
	 * @return array
	 */
	private function site_ignored_plugins( $site_id ) {
		$decoded = json_decode( (string) MainWP_DB::instance()->get_website_by_id( $site_id )->ignored_plugins, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
