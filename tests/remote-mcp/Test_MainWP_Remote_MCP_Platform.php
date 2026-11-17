<?php
/**
 * Remote MCP platform tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Requirements;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Self_Test;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/panel-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Platform
 */
class Test_MainWP_Remote_MCP_Platform extends \WP_UnitTestCase {

	use Remote_MCP_Panel_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Hook callbacks registered, counted across every hook.
	 *
	 * @return int
	 */
	private function hook_count(): int {
		global $wp_filter;
		$count = 0;
		foreach ( $wp_filter as $hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				$count += count( $callbacks );
			}
		}
		return $count;
	}

	/**
	 * A panel that sees the given platform.
	 *
	 * @param array $requirements Requirements result.
	 * @return Remote_MCP_Admin
	 */
	private function admin_on( array $requirements ): Remote_MCP_Admin {
		return new class( $this->panel_services(), $requirements ) extends Remote_MCP_Admin {
			private $platform;
			public function __construct( $services, array $platform ) {
				parent::__construct( $services );
				$this->platform = $platform;
			}
			protected function requirements(): array {
				return $this->platform;
			}
		};
	}

	public function test_multisite_registers_nothing_and_hides_panel() {
		$decide = '\\MainWP\\Dashboard\\Remote_MCP\\bootstrap_module';
		$before = $this->hook_count();
		$this->assertSame( 'none', $decide( PHP_VERSION, true, dirname( __DIR__, 2 ) . '/modules/remote-mcp' ) );
		$this->assertSame( 'none', $decide( '7.4.33', true, dirname( __DIR__, 2 ) . '/modules/remote-mcp' ) );
		$this->assertSame( $before, $this->hook_count(), 'nothing registers on multisite' );

		$multisite = Remote_MCP_Requirements::evaluate( PHP_VERSION, '7.0', true );
		$this->assertFalse( $multisite['ok'] );
		$this->assertSame( 'multisite', $multisite['reason'] );

		// The panel never lists itself there, even if something loaded it.
		$this->assertNull( $this->admin_on( $multisite )->direct_view( null ) );
		$this->assertIsArray( $this->admin_on( Remote_MCP_Requirements::evaluate( PHP_VERSION, '7.0', false ) )->direct_view( null ) );
	}

	public function test_wordpress_below_6_9_not_offered() {
		$old = Remote_MCP_Requirements::evaluate( PHP_VERSION, '6.8.3', false );
		$this->assertFalse( $old['ok'] );
		$this->assertSame( 'wordpress', $old['reason'] );
		$this->assertTrue( Remote_MCP_Requirements::evaluate( PHP_VERSION, '6.9', false )['ok'] );
		$this->assertTrue( Remote_MCP_Requirements::evaluate( PHP_VERSION, '7.0-beta1', false )['ok'] );

		$admin = $this->admin_on( $old );
		$html  = $admin->render();
		$this->assertStringContainsString( 'Remote AI connections need WordPress 6.9 or newer', $html );
		$this->assertStringNotContainsString( 'value="toggle"', $html, 'no switch to turn it on' );

		$result = $this->post( 'toggle', 'on', array(), null, $admin );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'requirements', $result['code'] );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		$self_test = new class( $this->panel_services(), $old ) extends Remote_MCP_Self_Test {
			private $platform;
			public function __construct( $services, array $platform ) {
				parent::__construct( $services );
				$this->platform = $platform;
			}
			protected function requirements(): array {
				return $this->platform;
			}
		};
		$this->assertContains( 'wordpress_version', $self_test->blocking_reasons() );
	}
}
