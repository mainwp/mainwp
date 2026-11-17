<?php
/**
 * MainWP Dashboard uninstall.
 *
 * Only the Remote MCP module cleans up on uninstall; the rest of the Dashboard keeps its data as before.
 *
 * @package MainWP\Dashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( file_exists( __DIR__ . '/modules/remote-mcp/uninstall.php' ) ) {
    require_once __DIR__ . '/modules/remote-mcp/uninstall.php'; // NOSONAR - WP compatible.
}
