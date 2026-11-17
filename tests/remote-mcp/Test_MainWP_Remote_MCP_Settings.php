<?php
/**
 * Remote MCP settings tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( class_exists( Remote_MCP_Settings::class ) ) {

	/**
	 * Settings reading a test-only logs-module constant, so the test controls whether the logs
	 * module counts as loaded: the real MAINWP_MODULE_LOG_ENABLED is defined once per process.
	 */
	class Remote_MCP_Test_Log_Settings extends Remote_MCP_Settings {
		const LOG_MODULE_CONSTANT = 'MAINWP_REMOTE_MCP_TEST_LOG_MODULE';
	}

	/**
	 * Settings reading a test-only logs-module constant that is never defined.
	 */
	class Remote_MCP_Test_Log_Off_Settings extends Remote_MCP_Settings {
		const LOG_MODULE_CONSTANT = 'MAINWP_REMOTE_MCP_TEST_LOG_MODULE_UNDEFINED';
	}
}

/**
 * Class Test_MainWP_Remote_MCP_Settings
 */
class Test_MainWP_Remote_MCP_Settings extends \WP_UnitTestCase {

	const SWITCH_OPTIONS = array( 'mainwp_remote_mcp_registration_enabled', 'mainwp_remote_mcp_static_clients', 'mainwp_remote_mcp_tool_switches', 'mainwp_remote_mcp_unaudited_tools_enabled', 'mainwp_remote_mcp_counters' );

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		foreach ( self::SWITCH_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	public function test_activity_log_available_follows_logs_module_setting() {
		$this->assertSame( 'MAINWP_MODULE_LOG_ENABLED', Remote_MCP_Settings::LOG_MODULE_CONSTANT );

		// With the logs module itself off, its stored setting doesn't matter: no connector loads.
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$this->assertFalse( defined( Remote_MCP_Test_Log_Off_Settings::LOG_MODULE_CONSTANT ) );
		$this->assertFalse( Remote_MCP_Test_Log_Off_Settings::activity_log_available() );

		if ( ! defined( Remote_MCP_Test_Log_Settings::LOG_MODULE_CONSTANT ) ) {
			define( Remote_MCP_Test_Log_Settings::LOG_MODULE_CONSTANT, true );
		}
		$this->assertTrue( Remote_MCP_Test_Log_Settings::activity_log_available() );

		update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
		$this->assertFalse( Remote_MCP_Test_Log_Settings::activity_log_available() );

		update_option( 'mainwp_module_log_settings', array( 'records_logs_ttl' => 100 ) );
		$this->assertFalse( Remote_MCP_Test_Log_Settings::activity_log_available(), 'unset reads as off' );

		update_option( 'mainwp_module_log_settings', 'enabled' );
		$this->assertFalse( Remote_MCP_Test_Log_Settings::activity_log_available(), 'a malformed option reads as off' );

		delete_option( 'mainwp_module_log_settings' );
		$this->assertFalse( Remote_MCP_Test_Log_Settings::activity_log_available(), 'missing reads as off' );

		update_option( 'mainwp_module_log_settings', array( 'enabled' => '1' ) );
		$this->assertTrue( Remote_MCP_Test_Log_Settings::activity_log_available(), 'read per call, never cached' );
	}

	public function test_counters_roll_over_after_24_hours_and_ignore_unknown_names() {
		$zero = array(
			'auth_header_missing' => 0,
			'auth_header_received' => 0,
			'auth_token_rejected' => 0,
			'unknown_client'      => 0,
		);
		$this->assertSame( $zero, Remote_MCP_Settings::counters() );

		Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
		Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
		Remote_MCP_Settings::bump_counter( 'unknown_client' );
		Remote_MCP_Settings::bump_counter( 'made_up' );
		Remote_MCP_Settings::bump_counter( 'AUTH_HEADER_MISSING' );
		$this->assertSame(
			array(
				'auth_header_missing' => 2,
				'auth_header_received' => 0,
				'auth_token_rejected' => 0,
				'unknown_client'      => 1,
			),
			Remote_MCP_Settings::counters()
		);
		$stored = get_option( 'mainwp_remote_mcp_counters' );
		$this->assertSame( array( 'auth_header_missing', 'unknown_client' ), array_keys( $stored ), 'unknown names are never stored' );

		// Each name has its own 24-hour window: an old window reads as zero and the next bump starts over.
		$stored['auth_header_missing']['since'] = time() - DAY_IN_SECONDS - 1;
		$stored['unknown_client']['since']      = time() - DAY_IN_SECONDS + 60;
		update_option( 'mainwp_remote_mcp_counters', $stored, false );
		$this->assertSame(
			array(
				'auth_header_missing' => 0,
				'auth_header_received' => 0,
				'auth_token_rejected' => 0,
				'unknown_client'      => 1,
			),
			Remote_MCP_Settings::counters()
		);
		Remote_MCP_Settings::bump_counter( 'auth_header_missing' );
		$this->assertSame( 1, Remote_MCP_Settings::counters()['auth_header_missing'] );
		$this->assertGreaterThanOrEqual( time() - 5, get_option( 'mainwp_remote_mcp_counters' )['auth_header_missing']['since'] );

		// Wrong shapes read as zero, and a bump over one starts that counter over.
		update_option( 'mainwp_remote_mcp_counters', 'lots', false );
		$this->assertSame( $zero, Remote_MCP_Settings::counters() );
		update_option(
			'mainwp_remote_mcp_counters',
			array(
				'auth_header_missing' => array(
					'count' => '7',
					'since' => time(),
				),
				'auth_token_rejected' => array( 'count' => 3 ),
				'unknown_client'      => array(
					'count' => -2,
					'since' => time(),
				),
			),
			false
		);
		$this->assertSame( $zero, Remote_MCP_Settings::counters() );
		Remote_MCP_Settings::bump_counter( 'auth_token_rejected' );
		$this->assertSame( 1, Remote_MCP_Settings::counters()['auth_token_rejected'] );
	}

	public function test_header_received_writes_once_per_window() {
		Remote_MCP_Settings::note_header_received();
		$first = get_option( 'mainwp_remote_mcp_counters' )['auth_header_received'];
		Remote_MCP_Settings::note_header_received();
		$this->assertSame( $first, get_option( 'mainwp_remote_mcp_counters' )['auth_header_received'], 'a busy connection does not write on every call' );
		$this->assertSame( 1, Remote_MCP_Settings::counters()['auth_header_received'] );

		$stored = get_option( 'mainwp_remote_mcp_counters' );
		$stored['auth_header_received']['since'] = time() - DAY_IN_SECONDS - 1;
		update_option( 'mainwp_remote_mcp_counters', $stored, false );
		$this->assertSame( 0, Remote_MCP_Settings::counters()['auth_header_received'], 'it expires with the window' );
		Remote_MCP_Settings::note_header_received();
		$this->assertSame( 1, Remote_MCP_Settings::counters()['auth_header_received'] );
	}

	public function test_chunk_size_cap_normalizes_numeric_core_thresholds() {
		$threshold = function () use ( &$value ) {
			return $value;
		};
		add_filter( 'mainwp_abilities_batch_threshold', $threshold );
		// Core compares `count > $threshold` loosely, so a numeric string or float threshold still queues.
		foreach ( array( '8' => 7, ' 8' => 7, '8.5' => 8, 'x' => 10 ) as $value => $expected ) {
			$this->assertSame( $expected, Remote_MCP_Settings::chunk_size(), 'threshold ' . var_export( $value, true ) );
		}
		// Array keys turn '8' into an int, so the plain numeric string is checked on its own.
		$value = '8';
		$this->assertSame( 7, Remote_MCP_Settings::chunk_size(), 'numeric string threshold' );
		$value = 8.0;
		$this->assertSame( 7, Remote_MCP_Settings::chunk_size(), 'float threshold' );
		remove_filter( 'mainwp_abilities_batch_threshold', $threshold );
	}

	public function test_chunk_size_defaults_to_ten_and_is_capped_below_core_threshold() {
		$this->assertSame( 10, Remote_MCP_Settings::chunk_size() );

		$size = function () use ( &$value ) {
			return $value;
		};
		add_filter( 'mainwp_remote_mcp_chunk_size', $size );
		foreach ( array( 25 => 25, 0 => 1, -4 => 1, 199 => 199, 200 => 199, 5000 => 199 ) as $value => $expected ) {
			$this->assertSame( $expected, Remote_MCP_Settings::chunk_size(), 'filtered to ' . $value );
		}
		$value = '50';
		$this->assertSame( 10, Remote_MCP_Settings::chunk_size(), 'a non-integer filter value is ignored' );

		// The cap follows the core threshold, so a chunked call never enters the core queue.
		add_filter(
			'mainwp_abilities_batch_threshold',
			function () {
				return 8;
			}
		);
		$value = 25;
		$this->assertSame( 7, Remote_MCP_Settings::chunk_size() );
		remove_filter( 'mainwp_remote_mcp_chunk_size', $size );
		$this->assertSame( 7, Remote_MCP_Settings::chunk_size(), 'the default is capped too' );
		add_filter(
			'mainwp_abilities_batch_threshold',
			function () {
				return 1;
			},
			20
		);
		$this->assertSame( 1, Remote_MCP_Settings::chunk_size(), 'never below one' );
	}

	public function test_switch_accessors_defaults_round_trip_and_malformed_reads_off() {
		// Every switch default, with nothing stored: all on.
		$this->assertTrue( Remote_MCP_Settings::DEFAULT_REGISTRATION_ENABLED );
		$this->assertTrue( Remote_MCP_Settings::DEFAULT_STATIC_CLIENT_ENABLED );
		$this->assertTrue( Remote_MCP_Settings::DEFAULT_TOOL_ENABLED );
		$this->assertTrue( Remote_MCP_Settings::DEFAULT_UNAUDITED_TOOLS_ENABLED );
		$this->assertTrue( Remote_MCP_Settings::registration_enabled() );
		$this->assertTrue( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );
		$this->assertTrue( Remote_MCP_Settings::static_client_enabled( 'mainwp-gemini-cli' ) );
		$this->assertTrue( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
		$this->assertTrue( Remote_MCP_Settings::category_enabled( 'mainwp-sites' ) );
		$this->assertTrue( Remote_MCP_Settings::unaudited_extension_tools_enabled() );

		// Round trips.
		$this->assertTrue( Remote_MCP_Settings::set_registration_enabled( false ) );
		$this->assertFalse( Remote_MCP_Settings::registration_enabled() );
		$this->assertTrue( Remote_MCP_Settings::set_registration_enabled( true ) );
		$this->assertTrue( Remote_MCP_Settings::registration_enabled() );

		$this->assertTrue( Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', false ) );
		$this->assertFalse( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );
		$this->assertTrue( Remote_MCP_Settings::static_client_enabled( 'mainwp-gemini-cli' ), 'one switch per client' );
		$this->assertTrue( Remote_MCP_Settings::static_client_enabled( 'MAINWP-CURSOR' ), 'ids compare byte for byte, so another id keeps the default' );
		$this->assertTrue( Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', true ) );
		$this->assertTrue( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );

		$this->assertTrue( Remote_MCP_Settings::set_tool_enabled( 'delete_site_v1', false ) );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'delete_site_v1' ) );
		$this->assertTrue( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
		$this->assertTrue( Remote_MCP_Settings::set_category_enabled( 'mainwp-sites', false ) );
		$this->assertFalse( Remote_MCP_Settings::category_enabled( 'mainwp-sites' ) );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'delete_site_v1' ), 'tool and category switches share one option' );
		$this->assertSame( array( 'tools', 'categories' ), array_keys( get_option( 'mainwp_remote_mcp_tool_switches' ) ) );

		$this->assertTrue( Remote_MCP_Settings::set_unaudited_extension_tools_enabled( false ) );
		$this->assertFalse( Remote_MCP_Settings::unaudited_extension_tools_enabled() );

		// A stored value of the wrong shape reads as off, never as the default.
		update_option( 'mainwp_remote_mcp_registration_enabled', array( 1 ) );
		$this->assertFalse( Remote_MCP_Settings::registration_enabled() );
		update_option( 'mainwp_remote_mcp_registration_enabled', 'yes' );
		$this->assertFalse( Remote_MCP_Settings::registration_enabled() );
		update_option( 'mainwp_remote_mcp_static_clients', 'mainwp-cursor' );
		$this->assertFalse( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );
		update_option( 'mainwp_remote_mcp_static_clients', array( 'mainwp-cursor' => 'true' ) );
		$this->assertFalse( Remote_MCP_Settings::static_client_enabled( 'mainwp-cursor' ) );
		update_option( 'mainwp_remote_mcp_tool_switches', 'all-on' );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
		$this->assertFalse( Remote_MCP_Settings::category_enabled( 'mainwp-sites' ) );
		update_option( 'mainwp_remote_mcp_tool_switches', array( 'tools' => 'x', 'categories' => array() ) );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
		update_option( 'mainwp_remote_mcp_tool_switches', array( 'tools' => array( 'list_sites_v1' => 1 ), 'categories' => array() ) );
		$this->assertFalse( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ), 'a non-boolean entry reads as off' );
		update_option( 'mainwp_remote_mcp_unaudited_tools_enabled', 2 );
		$this->assertFalse( Remote_MCP_Settings::unaudited_extension_tools_enabled() );

		// A setter over a malformed option starts it over rather than inheriting the damage.
		$this->assertTrue( Remote_MCP_Settings::set_tool_enabled( 'list_sites_v1', true ) );
		$this->assertTrue( Remote_MCP_Settings::tool_enabled( 'list_sites_v1' ) );
	}
}
