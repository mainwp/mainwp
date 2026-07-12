<?php
/**
 * MainWP Abilities Test Case Base Class
 *
 * This is the base class for all MainWP Abilities API tests. It provides fixtures,
 * cleanup helpers, and common testing utilities.
 *
 * ## Test Environment Setup
 *
 * All commands should be run from the repository root directory.
 *
 * Before running Abilities tests, you must:
 *
 * 1. **Install the WordPress test harness** (one-time setup):
 *    ```bash
 *    # Source your environment file (path varies by setup)
 *    source /path/to/your/.env
 *    bin/install-wp-tests.sh mainwp_tests root root "localhost:$DB_SOCKET" latest
 *    ```
 *
 * 2. **Source environment variables** before each test session:
 *    ```bash
 *    source /path/to/your/.env
 *    ```
 *
 * 3. **Run tests** with the WP_TESTS_DIR environment variable:
 *    ```bash
 *    WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Abilities
 *    ```
 *
 * ## Quick Reference
 *
 * ```bash
 * # Run all Abilities tests (from repo root)
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Abilities
 *
 * # Run a specific test file
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit tests/abilities/test-list-sites-ability.php
 *
 * # Run tests matching a pattern
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --filter="test_list_sites"
 * ```
 *
 * For full setup instructions, see CLAUDE.md in the project root.
 *
 * @package MainWP\Dashboard\Tests
 * @see CLAUDE.md for complete test setup documentation
 */

namespace MainWP\Dashboard\Tests;

use WP_UnitTestCase;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Base class for MainWP Abilities tests.
 *
 * Provides helper methods for creating test fixtures and mocking MainWP behavior.
 */
/**
 * Skip guard for capability-denial tests.
 *
 * `mainwp_current_user_have_right()` returns true unconditionally while
 * DOING_CRON, WP_CLI, or MAINWP_REST_API_DOING is defined, and a constant
 * defined by any earlier test lives for the whole phpunit process. Denial
 * tests use this guard so a single-process aggregate run (bare
 * `vendor/bin/phpunit` with no --testsuite) skips them loudly instead of
 * failing confusingly; the per-suite invocations CI and `composer test`
 * use never define these constants, so nothing skips there. A trait so
 * test classes outside MainWP_Abilities_Test_Case share the one
 * implementation.
 */
trait MainWP_Capability_Bypass_Guard { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- shared guard beside the base case, both loaded from the bootstrap.

	/**
	 * Whether a bypass constant is defined for this process.
	 *
	 * @return bool
	 */
	protected function capabilities_bypassed() {
		return ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING );
	}

	/**
	 * Skip a denial test when another suite in this process defined one of
	 * the constants that bypass capability checks by design.
	 *
	 * @return void
	 */
	protected function skip_when_capabilities_bypassed() {
		if ( $this->capabilities_bypassed() ) {
			$this->markTestSkipped( 'Capability checks are bypassed by design under cron, WP-CLI, and REST constants; another suite defined one for this process.' );
		}
	}
}

abstract class MainWP_Abilities_Test_Case extends WP_UnitTestCase { //phpcs:ignore -- NOSONAR - multi methods.

	use MainWP_Capability_Bypass_Guard;

	/**
	 * Track created sync job IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_sync_job_ids = [];

	/**
	 * Track created update job IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_update_job_ids = [];

	/**
	 * Track created batch job IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_batch_job_ids = [];

	/**
	 * Whether abilities have been initialized for tests.
	 *
	 * @var bool
	 */
	private static $abilities_initialized = false;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset job ID tracking.
		$this->created_sync_job_ids   = [];
		$this->created_update_job_ids = [];
		$this->created_batch_job_ids  = [];

		// Ensure abilities are registered (only once across all tests).
		if ( ! self::$abilities_initialized && function_exists( 'wp_get_ability' ) ) {
			// Check if abilities are already registered.
			$test_ability = wp_get_ability( 'mainwp/list-sites-v1' );
			if ( ! $test_ability ) {
				// Abilities not yet registered, initialize them.
				\MainWP\Dashboard\MainWP_Abilities::init();
				do_action( 'wp_abilities_api_categories_init' );
				do_action( 'wp_abilities_api_init' );
			}
			self::$abilities_initialized = true;
		}
	}

	/**
	 * Clean up test sites after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		// Suppress errors during cleanup - tables may not exist in all test scenarios.
		$wpdb->suppress_errors( true );

		// Clean up test sites.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%'" );

		// Clean up test clients (correct table name is mainwp_wp_clients).
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_clients WHERE name LIKE 'Test Client%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_clients WHERE client_email LIKE 'test-%@example.com'" );

		// Clean up test tags.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_group WHERE name LIKE 'Test Tag%'" );

		// Clean up site-tag junction table (mainwp_wp_group) for test sites and tags.
		$wpdb->query(
			"DELETE g FROM {$wpdb->prefix}mainwp_wp_group g
			INNER JOIN {$wpdb->prefix}mainwp_wp w ON g.wpid = w.id
			WHERE w.url LIKE 'https://test-%'"
		);
		$wpdb->query(
			"DELETE g FROM {$wpdb->prefix}mainwp_wp_group g
			LEFT JOIN {$wpdb->prefix}mainwp_group t ON g.groupid = t.id
			WHERE t.id IS NULL"
		);

		// Restore error reporting.
		$wpdb->suppress_errors( false );

		// Clean up job transients created during tests.
		foreach ( $this->created_sync_job_ids as $job_id ) {
			delete_transient( 'mainwp_sync_job_' . $job_id );
			wp_clear_scheduled_hook( 'mainwp_process_sync_job', [ $job_id ] );
		}

		foreach ( $this->created_update_job_ids as $job_id ) {
			delete_transient( 'mainwp_update_job_' . $job_id );
			wp_clear_scheduled_hook( 'mainwp_process_update_job', [ $job_id ] );
		}

		foreach ( $this->created_batch_job_ids as $job_id ) {
			delete_transient( 'mainwp_batch_job_' . $job_id );
			wp_clear_scheduled_hook( 'mainwp_process_batch_job', [ $job_id ] );
		}

		// Remove any mock filters.
		remove_all_filters( 'mainwp_sync_site_result' );
		remove_all_filters( 'mainwp_run_update_result' );
		remove_all_filters( 'mainwp_check_site_access' );
		remove_all_filters( 'mainwp_fetch_url_authed_pre' );

		parent::tearDown();
	}

	/**
	 * Create a test site in the MainWP database.
	 *
	 * Creates a site in mainwp_wp table and corresponding records in
	 * mainwp_wp_sync and mainwp_wp_options tables as needed.
	 *
	 * @param array $args Optional. Site properties to override defaults.
	 *                    Supports 'verify_method' (stored in options) and
	 *                    'version' (stored in sync table) as convenience keys.
	 * @return int Site ID.
	 */
	protected function create_test_site( array $args = [] ): int {
		global $wpdb;

		// Extract values that go to other tables (not columns in mainwp_wp).
		$verify_method = $args['verify_method'] ?? 1;
		$version       = $args['version'] ?? '5.0.0';
		$sync_errors   = $args['sync_errors'] ?? '';

		// Remove non-column fields from args before merging.
		unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

		// Defaults for mainwp_wp table columns only.
		// Use current user ID if available, otherwise use 1.
		$current_user_id = get_current_user_id();
		$defaults        = [
			'userid'               => $current_user_id > 0 ? $current_user_id : 1,
			'url'                  => 'https://test-' . wp_generate_uuid4() . '.example.com/',
			'name'                 => 'Test Site',
			'adminname'            => 'admin',
			'pubkey'               => 'test-pubkey',
			'privkey'              => 'test-privkey',
			'ssl_version'          => 0,
			'http_user'            => '',
			'http_pass'            => '',
			'suspended'            => 0,
			'offline_check_result' => 1, // 1 = online, -1 = offline.
			'client_id'            => 0,
			'is_staging'           => 0, // Required: queries filter by is_staging = 0.
			// Initialize upgrade fields to empty string to avoid json_decode(null) warnings.
			// Column names per class-mainwp-install.php table definition.
			'plugin_upgrades'      => '',
			'theme_upgrades'       => '',
			'translation_upgrades' => '',
			'premium_upgrades'     => '',
		];

		// Format specifiers matching the column types.
		$formats = [
			'userid'               => '%d',
			'url'                  => '%s',
			'name'                 => '%s',
			'adminname'            => '%s',
			'pubkey'               => '%s',
			'privkey'              => '%s',
			'ssl_version'          => '%d',
			'http_user'            => '%s',
			'http_pass'            => '%s',
			'suspended'            => '%d',
			'offline_check_result' => '%d',
			'client_id'            => '%d',
			'is_staging'           => '%d',
			'plugin_upgrades'      => '%s',
			'theme_upgrades'       => '%s',
			'translation_upgrades' => '%s',
			'premium_upgrades'     => '%s',
		];

		$data = array_merge( $defaults, $args );

		// Build format array in same order as data keys.
		$format_array = [];
		foreach ( array_keys( $data ) as $key ) {
			$format_array[] = $formats[ $key ] ?? '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			$data,
			$format_array
		);

		$site_id = (int) $wpdb->insert_id;

		// Store verify_method in options table.
		$this->set_site_option( $site_id, 'verify_method', $verify_method );

		// Create sync record with version and sync_errors.
		$this->create_test_site_sync(
			$site_id,
			[
				'version'     => $version,
				'sync_errors' => $sync_errors,
			]
		);

		return $site_id;
	}

	/**
	 * Create a sync record for a test site.
	 *
	 * Creates a record in mainwp_wp_sync table with version and sync status data.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Optional. Sync properties to override defaults.
	 * @return void
	 */
	protected function create_test_site_sync( int $site_id, array $args = [] ): void {
		global $wpdb;

		$defaults = [
			'wpid'        => $site_id,
			'version'     => '5.0.0',
			'sync_errors' => '',
		];

		$data = array_merge( $defaults, $args );

		// Ensure wpid is set correctly.
		$data['wpid'] = $site_id;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			$data,
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Set current user as an admin with MainWP permissions.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_admin(): int {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		// Grant MainWP API access if required.
		update_user_meta( $user_id, 'mainwp_api_enabled', 1 );

		return $user_id;
	}

	/**
	 * Set the current user to a subscriber (no manage_options capability).
	 *
	 * Use this to test that abilities properly deny low-privilege users.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_subscriber(): int {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Create a REST API key for testing.
	 *
	 * Creates an API key in the mainwp_api_keys table and returns the
	 * consumer key and secret for use in test requests.
	 *
	 * @param int    $user_id     User ID to associate with the key.
	 * @param string $permissions Permissions level: 'read', 'write', or 'read_write'.
	 * @return array Array with 'consumer_key' and 'consumer_secret'.
	 */
	protected function create_rest_api_key( int $user_id, string $permissions = 'read_write' ): array {
		global $wpdb;

		$consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );

		$table = $wpdb->prefix . 'mainwp_api_keys';

		// Hash using the same method as MainWP (mainwp_api_hash function).
		$hashed_key = mainwp_api_hash( $consumer_key );

		$wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'description'     => 'Test API Key',
				'permissions'     => $permissions,
				'consumer_key'    => $hashed_key,
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
				'enabled'         => 1,
				'last_access'     => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		return [
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		];
	}

	/**
	 * Mock a sync failure for specific site(s).
	 *
	 * When site IDs are provided, only those sites will fail; others will
	 * return their original result. When no site IDs are provided (or null),
	 * all sites will fail (backwards compatible behavior).
	 *
	 * @param string         $error_code    Error code to return.
	 * @param string         $error_message Error message to return.
	 * @param int|array|null $site_ids      Optional. Site ID or array of site IDs to fail.
	 *                                      If null, all sites fail.
	 * @return void
	 */
	protected function mock_sync_failure( string $error_code, string $error_message, $site_ids = null ): void {
		// Normalize to array if single ID provided.
		if ( null !== $site_ids && ! is_array( $site_ids ) ) {
			$site_ids = [ $site_ids ];
		}

		add_filter(
			'mainwp_sync_site_result',
			function ( $result, $site_id ) use ( $error_code, $error_message, $site_ids ) {
				// If no specific site IDs provided, fail all sites.
				if ( null === $site_ids ) {
					return new \WP_Error( $error_code, $error_message );
				}

				// Only fail if site_id is in the list.
				if ( in_array( (int) $site_id, array_map( 'intval', $site_ids ), true ) ) {
					return new \WP_Error( $error_code, $error_message );
				}

				// Return original result for non-targeted sites.
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Mock a partial update result for testing batch operations.
	 *
	 * The mainwp_run_update_result filter receives:
	 * - $result: null initially, or previous filter result
	 * - $site_id: Site ID being processed
	 * - $types: Array of update types being applied
	 *
	 * @param array $successes Array of successful site IDs.
	 * @param array $failures  Array of failed site IDs with error info.
	 * @return void
	 */
	protected function mock_partial_update_result( array $successes, array $failures ): void {
		add_filter(
			'mainwp_run_update_result',
			function ( $result, $site_id, $types = [] ) use ( $successes, $failures ) {
				if ( in_array( $site_id, $successes, true ) ) {
					// Return null to let the real execution proceed.
					return null;
				}
				foreach ( $failures as $failure ) {
					if ( $failure['site_id'] === $site_id ) {
						return new \WP_Error( $failure['code'], $failure['message'] );
					}
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * Mock a child site communication response to bypass OpenSSL signing.
	 *
	 * This method uses the mainwp_fetch_url_authed_pre filter to return
	 * a mock response before any HTTP communication or signing occurs.
	 * Useful for tests that would otherwise fail due to invalid test keys.
	 *
	 * IMPORTANT: This hook is TEST-ONLY. It only works because MAINWP_TESTING_MODE
	 * is defined in tests/bootstrap.php. The filter is protected by security guards
	 * in class-mainwp-connect.php that require PHPUnit environment constants.
	 * Enabling this outside of tests would undermine the security of child communication.
	 *
	 * @param int   $site_id  Site ID to mock responses for.
	 * @param array $response Response data to return from the mock.
	 * @return void
	 */
	protected function mock_child_site_response( int $site_id, array $response ): void {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $site_id, $response ) {
				if ( (int) $website->id === $site_id ) {
					return $response;
				}
				return $result;
			},
			10,
			4
		);
	}

	/**
	 * Skip test if Abilities API is not available.
	 *
	 * @return void
	 */
	protected function skip_if_no_abilities_api(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available.' );
		}
	}

	/**
	 * Get a test site by ID.
	 *
	 * @param int $site_id Site ID.
	 * @return object|null Site object or null if not found.
	 */
	protected function get_test_site( int $site_id ): ?object {
		global $wpdb;

		$site = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mainwp_wp WHERE id = %d",
				$site_id
			)
		);

		return $site ?: null;
	}

	/**
	 * Execute an ability by name with given input.
	 *
	 * @param string $ability_name Ability name (e.g., 'mainwp/list-sites-v1').
	 * @param array  $input        Input parameters for the ability.
	 * @return mixed Ability execution result.
	 */
	protected function execute_ability( string $ability_name, array $input = [] ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'abilities_unavailable', 'Abilities API not available.' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', "Ability {$ability_name} not found." );
		}

		return $ability->execute( $input );
	}

	/**
	 * Track a sync job ID for cleanup in tearDown.
	 *
	 * @param string $job_id Sync job ID.
	 * @return void
	 */
	protected function track_sync_job( string $job_id ): void {
		$this->created_sync_job_ids[] = $job_id;
	}

	/**
	 * Track an update job ID for cleanup in tearDown.
	 *
	 * @param string $job_id Update job ID.
	 * @return void
	 */
	protected function track_update_job( string $job_id ): void {
		$this->created_update_job_ids[] = $job_id;
	}

	/**
	 * Track a batch job ID for cleanup in tearDown.
	 *
	 * @param string $job_id Batch job ID.
	 * @return void
	 */
	protected function track_batch_job( string $job_id ): void {
		$this->created_batch_job_ids[] = $job_id;
	}

	// =========================================================================
	// Site Data Seeding Helpers
	// =========================================================================

	/**
	 * Set plugins data for a test site.
	 *
	 * Seeds the 'plugins' column in mainwp_wp table with the given plugin data.
	 * This is the installed plugins list, not the upgrades list.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $plugins Array of plugin data. Each plugin should have keys like:
	 *                       'Name', 'Version', 'active' (0 or 1), etc.
	 *                       Array keys should be plugin slugs (e.g., 'akismet/akismet.php').
	 * @return void
	 */
	protected function set_site_plugins( int $site_id, array $plugins ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'plugins' => wp_json_encode( $plugins ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set plugin upgrades data for a test site.
	 *
	 * Seeds the 'plugin_upgrades' column in mainwp_wp table.
	 * This is the list of available plugin updates.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades Array of plugin upgrade data. Each upgrade should have:
	 *                        'Name', 'Version' (current), 'new_version', etc.
	 *                        Array keys should be plugin slugs.
	 * @return void
	 */
	protected function set_site_plugin_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'plugin_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set themes data for a test site.
	 *
	 * Seeds the 'themes' column in mainwp_wp table with the given theme data.
	 * This is the installed themes list, not the upgrades list.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $themes  Array of theme data. Each theme should have keys like:
	 *                       'Name', 'Version', 'active' (0 or 1), etc.
	 *                       Array keys should be theme slugs (e.g., 'twentytwentyfour').
	 * @return void
	 */
	protected function set_site_themes( int $site_id, array $themes ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'themes' => wp_json_encode( $themes ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set theme upgrades data for a test site.
	 *
	 * Seeds the 'theme_upgrades' column in mainwp_wp table.
	 * This is the list of available theme updates.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades Array of theme upgrade data. Each upgrade should have:
	 *                        'Name', 'Version' (current), 'new_version', etc.
	 *                        Array keys should be theme slugs.
	 * @return void
	 */
	protected function set_site_theme_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'theme_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set ignored plugins data for a test site.
	 *
	 * Seeds the 'ignored_plugins' column in mainwp_wp table.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $ignored Array of ignored plugin data. Keys are plugin slugs,
	 *                       values are plugin info arrays with 'Name', 'ignored_versions', etc.
	 * @return void
	 */
	protected function set_site_ignored_plugins( int $site_id, array $ignored ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'ignored_plugins' => wp_json_encode( $ignored ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set ignored themes data for a test site.
	 *
	 * Seeds the 'ignored_themes' column in mainwp_wp table.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $ignored Array of ignored theme data. Keys are theme slugs,
	 *                       values are theme info arrays with 'Name', 'ignored_versions', etc.
	 * @return void
	 */
	protected function set_site_ignored_themes( int $site_id, array $ignored ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'ignored_themes' => wp_json_encode( $ignored ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set translation upgrades data for a test site.
	 *
	 * Seeds the 'translation_upgrades' column in mainwp_wp table.
	 * This is the list of available translation updates.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades Array of translation upgrade data. Each should have:
	 *                        'slug', 'language', 'version', 'new_version', etc.
	 * @return void
	 */
	protected function set_site_translation_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'translation_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set WordPress core upgrade info for a test site.
	 *
	 * Seeds the 'wp_upgrades' column in mainwp_wp table.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades WordPress core upgrade data with 'current', 'new_version', etc.
	 * @return void
	 */
	protected function set_site_wp_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'wp_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set a site option via MainWP's wp_options table.
	 *
	 * Uses the same pattern as MainWP_DB::update_website_option() but
	 * directly for tests without needing the full DB class.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $option  Option name.
	 * @param mixed  $value   Option value (will be serialized if needed).
	 * @return void
	 */
	protected function set_site_option( int $site_id, string $option, $value ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mainwp_wp_options';

		// Check if option exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE wpid = %d AND name = %s",
				$site_id,
				$option
			)
		);

		$serialized = is_scalar( $value ) ? $value : maybe_serialize( $value );

		if ( $exists ) {
			$wpdb->update(
				$table,
				[ 'value' => $serialized ],
				[
					'wpid' => $site_id,
					'name' => $option,
				],
				[ '%s' ],
				[ '%d', '%s' ]
			);
		} else {
			$wpdb->insert(
				$table,
				[
					'wpid'  => $site_id,
					'name'  => $option,
					'value' => $serialized,
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	/**
	 * Create a test client in the MainWP database.
	 *
	 * @param array $args Optional. Client properties to override defaults.
	 *                    Supports 'groups' as a convenience key to associate
	 *                    the client with tags via a site relationship.
	 * @return int Client ID.
	 */
	protected function create_test_client( array $args = [] ): int {
		// Extract groups before passing to update_client (not a valid column).
		$groups = $args['groups'] ?? [];
		unset( $args['groups'] );

		$defaults = [
			'name'             => 'Test Client ' . wp_generate_uuid4(),
			'client_email'     => 'test-' . wp_generate_uuid4() . '@example.com',
			'client_phone'     => '',
			'address_1'        => '',
			'address_2'        => '',
			'city'             => '',
			'state'            => '',
			'zip'              => '',
			'country'          => '',
			'note'             => '',
			'suspended'        => 0,
			'created'          => time(),
			'client_facebook'  => '',
			'client_twitter'   => '',
			'client_instagram' => '',
			'client_linkedin'  => '',
		];

		$data = array_merge( $defaults, $args );

		$client = MainWP_DB_Client::instance()->update_client( $data, true );

		$client_id = (int) $client->client_id;

		// If groups specified, create site relationship to associate client with tags.
		// Clients are linked to tags indirectly through their associated sites.
		if ( ! empty( $groups ) ) {
			$site_id = $this->create_test_site(
				[
					'name'      => 'Site for ' . $data['name'],
					'client_id' => $client_id,
				]
			);
			foreach ( $groups as $group_id ) {
				$this->assign_site_to_tag( $site_id, (int) $group_id );
			}
		}

		return $client_id;
	}

	/**
	 * Assign a site to a tag/group.
	 *
	 * Creates a record in the mainwp_wp_group junction table.
	 *
	 * @param int $site_id Site ID.
	 * @param int $tag_id  Tag/Group ID.
	 * @return void
	 */
	protected function assign_site_to_tag( int $site_id, int $tag_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_group',
			[
				'wpid'    => $site_id,
				'groupid' => $tag_id,
			],
			[ '%d', '%d' ]
		);
	}

	/**
	 * Get a test client by ID.
	 *
	 * @param int $client_id Client ID.
	 * @return object|null Client object or null if not found.
	 */
	protected function get_test_client( int $client_id ): ?object {
		return MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
	}

	/**
	 * Create a test tag in the MainWP database.
	 *
	 * @param array $args Optional. Tag properties to override defaults.
	 * @return int Tag ID.
	 */
	protected function create_test_tag( array $args = [] ): int {
		$defaults = array(
			'name'  => 'Test Tag ' . wp_generate_uuid4(),
			'color' => '#3498db',
		);
		$data     = array_merge( $defaults, $args );
		$tag      = \MainWP\Dashboard\MainWP_DB_Common::instance()->add_tag( $data );
		return (int) $tag->id;
	}
}
