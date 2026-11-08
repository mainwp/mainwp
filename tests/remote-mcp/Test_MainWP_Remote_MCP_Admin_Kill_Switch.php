<?php
/**
 * Remote MCP panel kill switch tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Admin_Kill_Switch
 */
class Test_MainWP_Remote_MCP_Admin_Kill_Switch extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * The toggle's submit button.
	 *
	 * @param string $html Panel HTML.
	 * @return string
	 */
	private function toggle_button( string $html ): string {
		$this->assertSame( 1, preg_match( '#<form[^>]*>(?:(?!</form>).)*value="toggle"(?:(?!</form>).)*</form>#s', $html, $form ), 'the toggle form renders' );
		$this->assertSame( 1, preg_match( '#<button[^>]*>#', $form[0], $button ) );
		return $button[0];
	}

	public function test_forced_off_toggle_is_disabled_and_names_the_cause() {
		$this->assertStringNotContainsString( 'disabled', $this->toggle_button( $this->admin()->render() ), 'usable while not forced off' );

		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$html = $this->admin()->render();
		$this->assertStringContainsString( 'disabled', $this->toggle_button( $html ) );
		$this->assertStringContainsString( esc_html( Remote_MCP_Admin::forced_off_message( 'filter' ) ), $html );
		$this->assertStringContainsString( 'by a plugin', Remote_MCP_Admin::forced_off_message( 'filter' ) );

		// The constant can't be defined here without leaking into every later test; its copy is
		// checked through the same function the panel calls.
		$this->assertStringContainsString( 'MAINWP_DISABLE_REMOTE_MCP', Remote_MCP_Admin::forced_off_message( 'constant' ) );
		$this->assertStringContainsString( 'wp-config.php', Remote_MCP_Admin::forced_off_message( 'constant' ) );

		// Forced off while the stored toggle is on: the panel still says it's off, and why.
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$html = $this->admin()->render();
		$this->assertStringContainsString( 'disabled', $this->toggle_button( $html ) );
		$this->assertStringContainsString( esc_html( Remote_MCP_Admin::forced_off_message( 'filter' ) ), $html );
	}

	public function test_enable_post_refused_while_forced_off() {
		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$result = $this->post( 'toggle', 'on' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'forced_off', $result['code'] );
		$this->assertFalse( Remote_MCP_Settings::enabled(), 'a valid nonce changes nothing' );

		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertTrue( $this->post( 'toggle', 'on' )['ok'] );
		$this->assertTrue( Remote_MCP_Settings::enabled() );
	}
}
