<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Mainwp
 */

// Load Composer autoloader for test classes.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // WPCS: XSS ok.
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Disable log module during tests.
 *
 * The log module tries to create archive tables from `wp_logs` during plugins_loaded,
 * but this fires BEFORE _mainwp_install_tables() runs, so the base table doesn't exist.
 * Enabling the module would require modifying the module to handle test environments.
 */
define( 'MAINWP_MODULE_LOG_ENABLED', false );

/**
 * Enable testing mode to allow test hooks.
 *
 * SECURITY WARNING - TEST ENVIRONMENT ONLY:
 * This constant enables the mainwp_fetch_url_authed_pre filter which allows tests
 * to mock child site communication responses. This is essential for unit testing
 * without real child sites, but would be a security vulnerability in production.
 *
 * This constant must ONLY be defined here in the PHPUnit bootstrap.
 * NEVER define MAINWP_TESTING_MODE in:
 * - Production code
 * - wp-config.php
 * - Plugin files
 * - Any file loaded outside of tests
 *
 * Doing so would allow malicious code to spoof child site responses.
 *
 * @see class-mainwp-connect.php for the filter implementation with security guards.
 */
define( 'MAINWP_TESTING_MODE', true );

/**
 * Enable REST API v2 for tests BEFORE plugin loads.
 *
 * The mainwp_rest_api_v2_enabled filter defaults to false in production
 * and is controlled by Rest_Api_V1::hook_rest_api_v2_enabled() at priority 10
 * which checks for enabled API keys in the database.
 *
 * In tests, we don't have API keys configured, so we override at priority 99
 * (AFTER the database check) to force REST v2 routes to be registered.
 */
tests_add_filter( 'mainwp_rest_api_v2_enabled', '__return_true', 99 );

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/mainwp.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

/**
 * Install MainWP database tables.
 *
 * The plugin activation hook doesn't run during tests, so we need to
 * manually trigger the database installation to create all required tables.
 */
function _mainwp_install_tables() {
	// Instantiate DB classes that register table creation hooks.
	\MainWP\Dashboard\MainWP_DB_Client::instance();
	\MainWP\Dashboard\MainWP_DB_Knowledge::instance();
	\MainWP\Dashboard\MainWP_DB_Site_Actions::instance();

	// Run the installation (creates all tables via dbDelta).
	\MainWP\Dashboard\MainWP_Install::instance()->install();
}
_mainwp_install_tables();

/**
 * Purge stale MainWP rows a previous phpunit process left behind.
 *
 * The per-test transaction rollback cannot protect against a test that runs
 * DDL (dbDelta, ALTER TABLE): the implicit commit makes every row inserted
 * before it durable, and the tear_down deletes that follow are themselves
 * rolled back. Those durable rows then persist across processes - install()
 * seeds no rows and every suite passes from empty tables, so any row present
 * here is a leak from an earlier run (a single stale site broke seven
 * Abilities tests by inflating site counts and resolving empty-input
 * lookups). Deleting here runs once per process, before any transaction, so
 * the cleanup itself is durable.
 */
function _mainwp_purge_stale_rows() {
	global $wpdb;
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mainwp_' ) . '%' ) );
	foreach ( $tables as $table ) {
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB -- table name from SHOW TABLES, test-DB cleanup.
	}
}
_mainwp_purge_stale_rows();

// Load custom test case base classes.
require_once __DIR__ . '/abilities/class-mainwp-abilities-test-case.php';
require_once __DIR__ . '/backup/class-mainwp-backup-test-case.php';
require_once __DIR__ . '/fixtures/Site_Fixture.php';
