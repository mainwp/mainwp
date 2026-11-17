<?php
/**
 * MainWP Assistant Test Case Base Class
 *
 * This is the base class for all MainWP Assistant tests. It provides fixtures,
 * cleanup helpers, and common testing utilities for assistant-specific functionality.
 *
 * ## Test Environment Setup
 *
 * Before running Assistant tests, you must:
 *
 * 1. **Source environment variables** before each test session:
 *    ```bash
 *    source /path/to/your/.env
 *    ```
 *
 * 2. **Run tests** with the WP_TESTS_DIR environment variable:
 *    ```bash
 *    WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Assistant
 *    ```
 *
 * ## Quick Reference
 *
 * ```bash
 * # Run all Assistant tests (from repo root)
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite=Assistant
 *
 * # Run a specific test file
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit tests/assistant/test-db-assistant.php
 *
 * # Run tests matching a pattern
 * WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --filter="test_conversation"
 * ```
 *
 * @package MainWP\Dashboard\Tests
 * @see CLAUDE.md for complete test setup documentation
 */

namespace MainWP\Dashboard\Tests;

use WP_UnitTestCase;
use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Policies;

/**
 * Base class for MainWP Assistant tests.
 *
 * Provides helper methods for creating test fixtures and mocking assistant behavior.
 */
abstract class MainWP_Assistant_Test_Case extends WP_UnitTestCase {

	/**
	 * Track created conversation IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_conversation_ids = array();

	/**
	 * Track created provider IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_provider_ids = array();

	/**
	 * Track user IDs that have been modified for cleanup.
	 *
	 * @var array
	 */
	protected $modified_user_ids = array();

	/**
	 * Track test tag IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_tag_ids = array();

	/**
	 * Track test site IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_site_ids = array();

	/**
	 * Whether encryption mocking is active.
	 *
	 * @var bool
	 */
	protected $encryption_mocked = false;

	/**
	 * Output buffer level at setUp, used to restore in tearDown.
	 *
	 * @var int
	 */
	protected $ob_level = 0;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Record the OB level so tearDown only drains buffers we opened.
		$this->ob_level = ob_get_level();

		// Reset tracking arrays.
		$this->created_conversation_ids = array();
		$this->created_provider_ids     = array();
		$this->modified_user_ids        = array();
		$this->created_tag_ids          = array();
		$this->created_site_ids         = array();
		$this->encryption_mocked        = false;
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		// Suppress errors during cleanup - tables may not exist in all test scenarios.
		$wpdb->suppress_errors( true );

		// Clean up test conversations and their messages.
		$this->cleanup_test_conversations();

		// Clean up test providers (user meta).
		$this->cleanup_test_providers();

		// Clean up user meta for assistant settings.
		$this->cleanup_user_meta();

		// Clean up test tags.
		$this->cleanup_test_tags();

		// Clean up test sites.
		$this->cleanup_test_sites();

		// Restore error reporting.
		$wpdb->suppress_errors( false );

		// Remove any mock filters.
		remove_all_filters( 'mainwp_encrypt_key_value' );
		remove_all_filters( 'mainwp_decrypt_key_value' );
		remove_all_filters( 'mainwp_delete_key_file' );

		// Clean up request superglobals to prevent test pollution.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test cleanup.
		$_POST = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test cleanup.
		$_GET = array();
		$_REQUEST = array();

		// Clean up any output buffers opened during the test, without closing PHPUnit's own buffers.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress OB errors in cleanup.
		while ( ob_get_level() > $this->ob_level ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress OB errors in cleanup.
			@ob_end_clean();
		}

		parent::tearDown();
	}

	// =========================================================================
	// AI CLIENT FIXTURE HELPERS
	// =========================================================================

	/**
	 * Build a canned GenerativeAiResult from message parts.
	 *
	 * Requires the WP AI Client (WordPress 7.0+); callers must guard with
	 * function_exists( 'wp_ai_client_prompt' ) / markTestSkipped first.
	 * Never call from a data provider — providers run before skip guards.
	 *
	 * @param array  $parts       MessagePart instances for the model message.
	 * @param array  $usage       Optional [prompt, completion, total] token counts.
	 * @param string $provider_id Provider ID for metadata.
	 * @param string $model_id    Model ID for metadata.
	 *
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 */
	protected function make_ai_result( array $parts, array $usage = array( 10, 5, 15 ), string $provider_id = 'openai', string $model_id = 'gpt-test' ) {
		$message   = new \WordPress\AiClient\Messages\DTO\ModelMessage( $parts );
		$candidate = new \WordPress\AiClient\Results\DTO\Candidate(
			$message,
			\WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
		);

		return new \WordPress\AiClient\Results\DTO\GenerativeAiResult(
			'test-result-id',
			array( $candidate ),
			new \WordPress\AiClient\Results\DTO\TokenUsage( $usage[0], $usage[1], $usage[2] ),
			new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
				$provider_id,
				ucfirst( $provider_id ),
				\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
			),
			new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata( $model_id, $model_id, array(), array() )
		);
	}

	/**
	 * Build a canned text-only GenerativeAiResult.
	 *
	 * @param string $text Text content.
	 *
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 */
	protected function make_text_result( string $text ) {
		return $this->make_ai_result( array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) ) );
	}

	// =========================================================================
	// USER HELPERS
	// =========================================================================

	/**
	 * Set current user as an admin with MainWP permissions.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_admin(): int {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Grant MainWP API access if required.
		update_user_meta( $user_id, 'mainwp_api_enabled', 1 );

		// Track for cleanup.
		$this->modified_user_ids[] = $user_id;

		return $user_id;
	}

	/**
	 * Set the current user to a subscriber (no manage_options capability).
	 *
	 * Use this to test that operations properly deny low-privilege users.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_subscriber(): int {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Track for cleanup.
		$this->modified_user_ids[] = $user_id;

		return $user_id;
	}

	/**
	 * Set the current user to a chat-only MainWP user.
	 *
	 * Simulates a Team Control style permission split: the user holds
	 * access_global_dashboard (can use the assistant chat) but not
	 * manage_dashboard_settings. mainwp_current_user_can() defaults to
	 * true for every capability, so the split needs an explicit filter.
	 *
	 * @return int User ID.
	 */
	protected function set_current_user_as_chat_only(): int {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		add_filter(
			'mainwp_currentusercan',
			function ( $result, $cap_type, $cap ) {
				if ( 'dashboard' === $cap_type ) {
					if ( 'access_global_dashboard' === $cap ) {
						return true;
					}
					if ( 'manage_dashboard_settings' === $cap ) {
						return false;
					}
				}
				return $result;
			},
			10,
			3
		);

		// Track for cleanup.
		$this->modified_user_ids[] = $user_id;

		return $user_id;
	}

	/**
	 * Create a second admin user for cross-user tests.
	 *
	 * @return int User ID.
	 */
	protected function create_other_admin(): int {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'mainwp_api_enabled', 1 );
		$this->modified_user_ids[] = $user_id;
		return $user_id;
	}

	// =========================================================================
	// CONVERSATION & MESSAGE HELPERS
	// =========================================================================

	/**
	 * Create a test conversation.
	 *
	 * @param int    $user_id  User ID who owns the conversation.
	 * @param string $title    Optional conversation title.
	 * @param array  $metadata Optional metadata.
	 *
	 * @return int Conversation ID.
	 */
	protected function create_test_conversation( int $user_id, string $title = 'Test Conversation', array $metadata = array() ): int {
		$conversation_id = MainWP_DB_Assistant::instance()->create_conversation( $user_id, $title, $metadata );

		if ( ! is_wp_error( $conversation_id ) ) {
			$this->created_conversation_ids[] = $conversation_id;
		}

		return $conversation_id;
	}

	/**
	 * Add a test message to a conversation.
	 *
	 * @param int      $conversation_id Conversation ID.
	 * @param string   $role            Message role (user, assistant, system).
	 * @param string   $content         Message content.
	 * @param int|null $user_id         Optional user ID for ownership verification.
	 *
	 * @return int|WP_Error Message ID or WP_Error on failure.
	 */
	protected function add_test_message( int $conversation_id, string $role, string $content, ?int $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		return MainWP_DB_Assistant::instance()->add_message( $conversation_id, $role, $content, $user_id );
	}

	/**
	 * Clean up test conversations.
	 *
	 * @return void
	 */
	protected function cleanup_test_conversations(): void {
		global $wpdb;

		// Delete messages and conversations by ID.
		foreach ( $this->created_conversation_ids as $conversation_id ) {
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_assistant_messages',
				array( 'conversation_id' => $conversation_id ),
				array( '%d' )
			);
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_assistant_conversations',
				array( 'id' => $conversation_id ),
				array( '%d' )
			);
		}

		// Also clean up any conversations with test titles.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_assistant_conversations WHERE title LIKE 'Test %'" );
	}

	// =========================================================================
	// PROVIDER HELPERS
	// =========================================================================

	/**
	 * Clean up test providers.
	 *
	 * @return void
	 */
	protected function cleanup_test_providers(): void {
		foreach ( $this->created_provider_ids as $user_id => $provider_ids ) {
			// Clear all providers for this user.
			delete_user_meta( $user_id, 'mainwp_assistant_providers' );
			delete_user_meta( $user_id, 'mainwp_assistant_active_provider' );
		}
	}

	/**
	 * Mock the encryption filter for testing.
	 *
	 * This provides a simple reversible encryption for tests without requiring
	 * the full MainWP_Keys_Manager infrastructure.
	 *
	 * @return void
	 */
	protected function mock_encryption_filter(): void {
		if ( $this->encryption_mocked ) {
			return;
		}

		// Mock encryption filter.
		add_filter(
			'mainwp_encrypt_key_value',
			function ( $result, $value, $prefix, $existing_key_file ) {
				// Simple base64 encoding for test purposes.
				$encrypted = base64_encode( 'encrypted:' . $value );
				$key_file  = $existing_key_file ?: 'test_key_' . wp_generate_uuid4();
				return array(
					'encrypted_val' => $encrypted,
					'file_key'      => $key_file,
				);
			},
			10,
			4
		);

		// Mock decryption filter.
		add_filter(
			'mainwp_decrypt_key_value',
			function ( $result, $encrypted_data, $default ) {
				if ( empty( $encrypted_data['encrypted_val'] ) ) {
					return $default;
				}
				$decoded = base64_decode( $encrypted_data['encrypted_val'] );
				if ( 0 === strpos( $decoded, 'encrypted:' ) ) {
					return substr( $decoded, 10 );
				}
				return $default;
			},
			10,
			3
		);

		// Mock key file deletion action.
		add_action(
			'mainwp_delete_key_file',
			function ( $key_file ) {
				// No-op for tests.
			}
		);

		$this->encryption_mocked = true;
	}

	// =========================================================================
	// ACCESS POLICY HELPERS
	// =========================================================================

	/**
	 * Set the access mode for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    Access mode (read-only, read-write-confirm, full-access).
	 *
	 * @return void
	 */
	protected function set_user_access_mode( int $user_id, string $mode ): void {
		update_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_ACCESS_MODE, $mode );
		$this->modified_user_ids[] = $user_id;
	}

	/**
	 * Set the protected tags for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $tag_ids Array of tag IDs to protect.
	 *
	 * @return void
	 */
	protected function set_user_protected_tags( int $user_id, array $tag_ids ): void {
		update_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_PROTECTED_TAGS, $tag_ids );
		$this->modified_user_ids[] = $user_id;
	}

	/**
	 * Enable or disable the global assistant.
	 *
	 * Uses integer values (1/0) instead of boolean to avoid WordPress option storage issues.
	 * WordPress may not create an option when the value is boolean false.
	 *
	 * @param bool $enabled Whether to enable.
	 *
	 * @return void
	 */
	protected function set_assistant_enabled( bool $enabled ): void {
		// Use integer value to ensure WordPress stores the option.
		// Boolean false can cause issues with update_option/add_option.
		$value = $enabled ? 1 : 0;
		update_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED, $value );
		update_option( 'mainwp_enable_guided_chatbase', $value );
	}

	/**
	 * Grant or revoke assistant consent for tests.
	 *
	 * @param bool $granted Whether consent should be active.
	 *
	 * @return void
	 */
	protected function set_assistant_consent( bool $granted ): void {
		update_option( MainWP_Assistant_Policies::OPTION_CONSENT, $granted ? 1 : 0 );
		update_option(
			MainWP_Assistant_Policies::OPTION_CONSENT_VERSION,
			$granted ? MainWP_Assistant_Policies::CURRENT_CONSENT_VERSION : 0
		);
	}

	/**
	 * Clean up user meta for assistant settings.
	 *
	 * @return void
	 */
	protected function cleanup_user_meta(): void {
		foreach ( array_unique( $this->modified_user_ids ) as $user_id ) {
			delete_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_ACCESS_MODE );
			delete_user_meta( $user_id, MainWP_Assistant_Policies::USER_META_PROTECTED_TAGS );
			delete_user_meta( $user_id, 'mainwp_assistant_providers' );
			delete_user_meta( $user_id, 'mainwp_assistant_active_provider' );
			delete_user_meta( $user_id, 'mainwp_assistant_legacy_migrated' );
		}

		// Clean up global options.
		delete_option( MainWP_Assistant_Policies::OPTION_ASSISTANT_ENABLED );
		delete_option( MainWP_Assistant_Policies::OPTION_CONSENT );
		delete_option( MainWP_Assistant_Policies::OPTION_CONSENT_VERSION );
		delete_option( 'mainwp_enable_guided_chatbase' );
		delete_option( 'mainwp_assistant_legacy_key_migrated' );
	}

	// =========================================================================
	// TAG HELPERS
	// =========================================================================

	/**
	 * Create a test tag.
	 *
	 * @param array $args Optional. Tag properties to override defaults.
	 *
	 * @return int Tag ID.
	 */
	protected function create_test_tag( array $args = array() ): int {
		$defaults = array(
			'name'  => 'Test Tag ' . wp_generate_uuid4(),
			'color' => '#3498db',
		);
		$data     = array_merge( $defaults, $args );
		$tag      = \MainWP\Dashboard\MainWP_DB_Common::instance()->add_tag( $data );
		$tag_id   = (int) $tag->id;

		$this->created_tag_ids[] = $tag_id;

		return $tag_id;
	}

	/**
	 * Clean up test tags.
	 *
	 * @return void
	 */
	protected function cleanup_test_tags(): void {
		global $wpdb;

		foreach ( $this->created_tag_ids as $tag_id ) {
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_group',
				array( 'id' => $tag_id ),
				array( '%d' )
			);
		}

		// Also clean up any tags with test names.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_group WHERE name LIKE 'Test Tag%'" );
	}

	// =========================================================================
	// SITE HELPERS
	// =========================================================================

	/**
	 * Create a test site in the MainWP database.
	 *
	 * @param array $args Optional. Site properties to override defaults.
	 *
	 * @return int Site ID.
	 */
	protected function create_test_site( array $args = array() ): int {
		global $wpdb;

		$current_user_id = get_current_user_id();
		$defaults        = array(
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
			'offline_check_result' => 1,
			'client_id'            => 0,
			'is_staging'           => 0,
			'plugin_upgrades'      => '',
			'theme_upgrades'       => '',
			'translation_upgrades' => '',
			'premium_upgrades'     => '',
		);

		$data = array_merge( $defaults, $args );

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		$site_id = (int) $wpdb->insert_id;

		// Sync row: get_website_by_id()'s joins require it, so sites created
		// without one are invisible to resolve_site()-based code paths.
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			array(
				'wpid'        => $site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			),
			array( '%d', '%s', '%s' )
		);

		$this->created_site_ids[] = $site_id;

		return $site_id;
	}

	/**
	 * Assign a site to a tag.
	 *
	 * @param int $site_id Site ID.
	 * @param int $tag_id  Tag ID.
	 *
	 * @return void
	 */
	protected function assign_site_to_tag( int $site_id, int $tag_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_group',
			array(
				'wpid'    => $site_id,
				'groupid' => $tag_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Clean up test sites.
	 *
	 * @return void
	 */
	protected function cleanup_test_sites(): void {
		global $wpdb;

		foreach ( $this->created_site_ids as $site_id ) {
			// Clean up site-tag associations.
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_wp_group',
				array( 'wpid' => $site_id ),
				array( '%d' )
			);
			// Clean up site.
			$wpdb->delete(
				$wpdb->prefix . 'mainwp_wp',
				array( 'id' => $site_id ),
				array( '%d' )
			);
		}

		// Also clean up any sites with test URLs.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%'" );
	}

	// =========================================================================
	// ASSERTION HELPERS
	// =========================================================================

	/**
	 * Assert that a value is a WP_Error with specific code.
	 *
	 * @param mixed  $actual        The value to check.
	 * @param string $expected_code Expected error code.
	 * @param string $message       Optional message.
	 *
	 * @return void
	 */
	public function assertWPErrorWithCode( $actual, $expected_code, $message = '' ) {
		$this->assertInstanceOf( \WP_Error::class, $actual, $message ?: 'Expected WP_Error instance.' );
		$this->assertEquals( $expected_code, $actual->get_error_code(), $message ?: "Expected error code '{$expected_code}'." );
	}

	/**
	 * Assert that a value is NOT a WP_Error.
	 *
	 * @param mixed  $actual  The value to check.
	 * @param string $message Optional message.
	 *
	 * @return void
	 */
	public function assertNotWPError( $actual, $message = '' ) {
		if ( is_wp_error( $actual ) ) {
			$error_message = $message ?: 'Expected value to not be a WP_Error.';
			$error_message .= sprintf( ' Got error: %s (%s)', $actual->get_error_message(), $actual->get_error_code() );
			$this->fail( $error_message );
		}
		$this->assertNotInstanceOf( \WP_Error::class, $actual, $message ?: 'Expected value to not be a WP_Error.' );
	}
}
