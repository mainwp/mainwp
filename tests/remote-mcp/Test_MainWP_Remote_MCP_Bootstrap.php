<?php
/**
 * Remote MCP bootstrap tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Bootstrap
 */
class Test_MainWP_Remote_MCP_Bootstrap extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
		$GLOBALS['remote_mcp_bootstrap_probe'] = array();
	}

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

	public function test_below_minimum_php_loads_only_requirements_and_fallback_panel() {
		$dir    = __DIR__ . '/fixtures/bootstrap';
		$panel  = Remote_MCP_Test_Fallback_Panel::class;
		$decide = '\\MainWP\\Dashboard\\Remote_MCP\\bootstrap_module';

		$before = $this->hook_count();
		$this->assertSame( 'fallback', $decide( '7.4.33', false, $dir, $panel ) );
		$this->assertSame( array( 'requirements', 'fallback_panel', 'panel_registered' ), $GLOBALS['remote_mcp_bootstrap_probe'], 'the loader is never required' );
		$this->assertSame( $before + 1, $this->hook_count(), 'only the fallback panel hooks in' );
		$this->assertSame( 'fallback', $decide( '8.0.30', false, $dir, $panel ) );

		// Multisite registers nothing at all, whatever the PHP version.
		$GLOBALS['remote_mcp_bootstrap_probe'] = array();
		$before                                = $this->hook_count();
		$this->assertSame( 'none', $decide( '7.4.33', true, $dir, $panel ) );
		$this->assertSame( 'none', $decide( '8.4.0', true, $dir, $panel ) );
		$this->assertSame( array(), $GLOBALS['remote_mcp_bootstrap_probe'] );
		$this->assertSame( $before, $this->hook_count() );

		// A missing fallback panel loads nothing and still never fatals.
		$this->assertSame( 'fallback', $decide( '7.4.33', false, __DIR__ . '/fixtures/no-such-dir', $panel ) );
		$this->assertSame( array(), $GLOBALS['remote_mcp_bootstrap_probe'] );

		// At or above the minimum the module loads as before, and neither fallback file does.
		$this->assertSame( 'module', $decide( '8.1.0', false, $dir, $panel ) );
		$this->assertSame( array( 'loader' ), $GLOBALS['remote_mcp_bootstrap_probe'] );
	}

	public function test_boot_registers_the_revocation_hooks() {
		$provider = \MainWP\Dashboard\Remote_MCP\Remote_MCP::instance()->services()->get( 'hooks_revocation' );
		$this->assertNotFalse( has_action( 'set_user_role', array( $provider, 'on_set_user_role' ) ) );
		$this->assertNotFalse( has_action( 'deleted_user', array( $provider, 'on_deleted_user' ) ) );
	}
}
