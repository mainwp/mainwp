<?php
/**
 * Tests for intent-based tool-catalog subsetting.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Policies;

/**
 * Agent seam that captures the function-declaration names each generation
 * call advertised, alongside a canned-result queue.
 */
class Declaration_Capturing_Assistant extends MainWP_Assistant {

	/**
	 * Queue of canned results (GenerativeAiResult or WP_Error), one per call.
	 *
	 * @var array
	 */
	public $canned_results = array();

	/**
	 * Declaration-name lists captured per generation call, in call order.
	 *
	 * @var array
	 */
	public $captured_declarations = array();

	/**
	 * Override: record advertised declarations, pop a canned result.
	 *
	 * @param object $builder Configured prompt builder.
	 *
	 * @return object|\WP_Error
	 */
	protected function generate_ai_result( $builder ) {
		$this->captured_declarations[] = self::extract_declaration_names( $builder );

		if ( empty( $this->canned_results ) ) {
			return new \WP_Error( 'no_canned_response', 'Test forgot to queue a canned result.' );
		}

		return array_shift( $this->canned_results );
	}

	/**
	 * Extract advertised function-declaration names from a prompt builder.
	 *
	 * @param object $builder WP prompt builder wrapping the SDK builder.
	 *
	 * @return array Declaration names (empty when none were advertised).
	 */
	protected static function extract_declaration_names( $builder ) {
		try {
			$wp_ref = new \ReflectionProperty( $builder, 'builder' );
			$wp_ref->setAccessible( true );
			$sdk = $wp_ref->getValue( $builder );

			$config_ref = new \ReflectionProperty( $sdk, 'modelConfig' );
			$config_ref->setAccessible( true );
			$config = $config_ref->getValue( $sdk );

			$declarations = $config->getFunctionDeclarations();
			if ( ! is_array( $declarations ) ) {
				return array();
			}

			return array_map(
				static function ( $declaration ) {
					return $declaration->getName();
				},
				$declarations
			);
		} catch ( \Exception $e ) {
			return array();
		}
	}
}

/**
 * Test class for tool-catalog subsetting.
 *
 * Tests the category classifier, the advertising-time filter, the
 * expand-toolset escape hatch, and the kill switch.
 */
class Test_Assistant_Tool_Subsetting extends MainWP_Assistant_Test_Case {

	/**
	 * Clean up seeded schema transients.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_transient( 'mainwp_assistant_ability_schemas_v' . MainWP_Assistant::ABILITY_SCHEMA_CACHE_REV );
		parent::tearDown();
	}

	/**
	 * Invoke a private MainWP_Assistant method via reflection.
	 *
	 * @param object $assistant Assistant instance.
	 * @param string $method    Method name.
	 * @param mixed  ...$args   Method arguments.
	 *
	 * @return mixed
	 */
	protected function invoke_private( $assistant, string $method, ...$args ) {
		$reflection = new \ReflectionMethod( MainWP_Assistant::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $assistant, ...$args );
	}

	/**
	 * Seed three abilities across three categories.
	 *
	 * @return void
	 */
	protected function seed_three_category_tools(): void {
		$make = static function ( $label, $category ) {
			return array(
				'label'        => $label,
				'description'  => $label,
				'input_schema' => array( 'type' => 'object' ),
				'methods'      => array( 'GET', 'POST' ),
				'annotations'  => array( 'readonly' => true ),
				'category'     => $category,
			);
		};

		set_transient(
			'mainwp_assistant_ability_schemas_v' . MainWP_Assistant::ABILITY_SCHEMA_CACHE_REV,
			array(
				'mainwp/get-sites-basic-v1'   => $make( 'Sites tool', 'mainwp-sites' ),
				'mainwp/get-site-updates-v1'  => $make( 'Updates tool', 'mainwp-updates' ),
				'mainwp/get-backup-status-v1' => $make( 'Backups tool', 'mainwp-backups' ),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Test single-keyword classification includes the always-on sites category.
	 */
	public function test_classify_message_categories_matches_single_keyword() {
		$categories = $this->invoke_private( MainWP_Assistant::instance(), 'classify_message_categories', 'Check for plugin updates on child6-1' );

		$this->assertContains( 'mainwp-sites', $categories );
		$this->assertContains( 'mainwp-updates', $categories );
		$this->assertNotContains( 'mainwp-backups', $categories );
	}

	/**
	 * Test multiple keyword hits collect multiple categories.
	 */
	public function test_classify_message_categories_matches_multiple_categories() {
		$categories = $this->invoke_private( MainWP_Assistant::instance(), 'classify_message_categories', 'Back up every client site before the plugin update' );

		$this->assertContains( 'mainwp-backups', $categories );
		$this->assertContains( 'mainwp-clients', $categories );
		$this->assertContains( 'mainwp-updates', $categories );
		$this->assertContains( 'mainwp-sites', $categories );
	}

	/**
	 * Test no keyword match returns null so the full catalog is advertised.
	 */
	public function test_classify_message_categories_returns_null_when_no_keyword_matches() {
		$assistant = MainWP_Assistant::instance();

		$this->assertNull( $this->invoke_private( $assistant, 'classify_message_categories', 'hello there' ) );
		$this->assertNull( $this->invoke_private( $assistant, 'classify_message_categories', '' ) );
	}

	/**
	 * Test the category filter narrows build_filtered_tools without touching
	 * the unfiltered result cached in the same request.
	 */
	public function test_build_filtered_tools_respects_category_filter_and_cache_keys() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->seed_three_category_tools();

		$assistant = new Declaration_Capturing_Assistant();

		$all = $this->invoke_private( $assistant, 'build_filtered_tools', $user_id );
		$this->assertCount( 3, $all );

		$subset = $this->invoke_private( $assistant, 'build_filtered_tools', $user_id, array( 'mainwp-sites', 'mainwp-updates' ) );
		$this->assertSame( array( 'mainwp/get-sites-basic-v1', 'mainwp/get-site-updates-v1' ), array_keys( $subset ) );

		// The unfiltered set is still served for the security allow-list.
		$all_again = $this->invoke_private( $assistant, 'build_filtered_tools', $user_id );
		$this->assertCount( 3, $all_again );
	}

	/**
	 * Test the expand-toolset declaration ships only with a subset.
	 */
	public function test_expand_toolset_declared_only_when_subsetting_active() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->seed_three_category_tools();

		$assistant = new Declaration_Capturing_Assistant();

		$full = $this->invoke_private( $assistant, 'build_function_declarations', $user_id );
		$full_names = array_map(
			static function ( $declaration ) {
				return $declaration->getName();
			},
			$full
		);
		$this->assertNotContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $full_names );
		$this->assertCount( 3, $full );

		$subset = $this->invoke_private( $assistant, 'build_function_declarations', $user_id, array( 'mainwp-sites' ) );
		$subset_names = array_map(
			static function ( $declaration ) {
				return $declaration->getName();
			},
			$subset
		);
		$this->assertContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $subset_names );
		$this->assertCount( 2, $subset );
	}

	/**
	 * Test an expand-toolset call widens the next hop to the full catalog
	 * without producing an executed-ability chip.
	 */
	public function test_expand_toolset_call_widens_next_hop() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->seed_three_category_tools();

		add_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );

		try {
			$assistant                 = new Declaration_Capturing_Assistant();
			$assistant->canned_results = array(
				$this->make_ai_result(
					array(
						new \WordPress\AiClient\Messages\DTO\MessagePart(
							new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call_expand', MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, array() )
						),
					)
				),
				$this->make_text_result( 'Here is what I found.' ),
			);

			$method = new \ReflectionMethod( MainWP_Assistant::class, 'execute_ai_chat' );
			$method->setAccessible( true );

			// "plugin update" classifies to sites+updates, so backups is
			// initially outside the advertised subset.
			$result = $method->invoke( $assistant, 'Check plugin updates', 0, array(), false, $user_id );
		} finally {
			remove_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );
		}

		$this->assertNotWPError( $result );
		$this->assertSame( 'Here is what I found.', $result['response'] );
		$this->assertSame( array(), $result['executed_abilities'], 'Expanding the toolset is not a user-visible action.' );

		$this->assertCount( 2, $assistant->captured_declarations );

		// First hop: subsetted catalog plus the escape hatch, no backups tool.
		$first = $assistant->captured_declarations[0];
		$this->assertContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $first );
		$this->assertNotContains( 'wpab__mainwp__get-backup-status-v1', $first );

		// Second hop: full catalog, escape hatch gone.
		$second = $assistant->captured_declarations[1];
		$this->assertContains( 'wpab__mainwp__get-backup-status-v1', $second );
		$this->assertContains( 'wpab__mainwp__get-sites-basic-v1', $second );
		$this->assertNotContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $second );
	}

	/**
	 * Test the kill switch forces the full catalog even for classified messages.
	 */
	public function test_disable_filter_forces_full_catalog() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'WP AI Client not available in this WordPress version.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		$this->seed_three_category_tools();

		add_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );
		add_filter( 'mainwp_assistant_enable_tool_subsetting', '__return_false' );

		try {
			$assistant                 = new Declaration_Capturing_Assistant();
			$assistant->canned_results = array( $this->make_text_result( 'All good.' ) );

			$method = new \ReflectionMethod( MainWP_Assistant::class, 'execute_ai_chat' );
			$method->setAccessible( true );

			$result = $method->invoke( $assistant, 'Check plugin updates', 0, array(), false, $user_id );
		} finally {
			remove_filter( 'mainwp_assistant_agent_mode_available', '__return_true' );
			remove_filter( 'mainwp_assistant_enable_tool_subsetting', '__return_false' );
		}

		$this->assertNotWPError( $result );
		$first = $assistant->captured_declarations[0];
		$this->assertContains( 'wpab__mainwp__get-backup-status-v1', $first );
		$this->assertNotContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $first );
	}
}
