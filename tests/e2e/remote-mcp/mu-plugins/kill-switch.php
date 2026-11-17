<?php
/**
 * Plugin Name: MainWP remote MCP harness: kill switch
 * Description: E2E harness only. Sets the kill-switch constant the way wp-config.php would.
 *
 * @package MainWP\Dashboard\Tests
 */

if ( ! defined( 'MAINWP_DISABLE_REMOTE_MCP' ) ) {
    define( 'MAINWP_DISABLE_REMOTE_MCP', true );
}
