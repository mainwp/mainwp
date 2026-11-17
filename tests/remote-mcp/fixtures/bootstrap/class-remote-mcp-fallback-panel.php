<?php
// Stands in for the fallback panel: records that it loaded, and registers its one hook.
namespace MainWP\Dashboard\Tests;

$GLOBALS['remote_mcp_bootstrap_probe'][] = 'fallback_panel';

if ( ! class_exists( Remote_MCP_Test_Fallback_Panel::class, false ) ) {
	class Remote_MCP_Test_Fallback_Panel {
		public static function register_hooks() {
			$GLOBALS['remote_mcp_bootstrap_probe'][] = 'panel_registered';
			add_filter( 'mainwp_mcp_direct_view', '__return_null' );
		}
	}
}
