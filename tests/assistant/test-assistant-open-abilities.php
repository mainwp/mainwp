<?php
/**
 * Tests for registered abilities outside the built-in Assistant catalog.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Confirmation_State;
use MainWP\Dashboard\MainWP_Assistant_Policies;

/**
 * Capture declarations while keeping generation local to the test.
 */
class Open_Abilities_Assistant extends MainWP_Assistant {

	/** @var array Canned generation results. */
	public $results = array();

	/** @var array Function names per generation. */
	public $declarations = array();

	/**
	 * Capture declarations from the SDK configuration.
	 *
	 * @param object $builder Prompt builder.
	 * @return object|\WP_Error
	 */
	protected function generate_ai_result( $builder ) {
		$property = new \ReflectionProperty( $builder, 'builder' );
		$property->setAccessible( true );
		$sdk      = $property->getValue( $builder );
		$property = new \ReflectionProperty( $sdk, 'modelConfig' );
		$property->setAccessible( true );
		$config               = $property->getValue( $sdk );
		$this->declarations[] = array_map(
			static function ( $declaration ) {
				return $declaration->getName();
			},
			$config->getFunctionDeclarations()
		);

		return array_shift( $this->results ) ?? new \WP_Error( 'missing_result', 'No canned result queued.' );
	}
}

/**
 * Discovery, policy, confirmation, and provider-name coverage.
 */
class Test_Assistant_Open_Abilities extends MainWP_Assistant_Test_Case {

	/** @var array Temporary registered ability IDs. */
	private $ability_ids = array();

	/** @var array Inputs received by the fake callbacks. */
	private $calls = array();

	/** @var int Current administrator. */
	private $user_id;

	/** @var bool Whether this test registered WordPress's core abilities. */
	private $registered_core = false;

	/** @var string Temporary category. */
	private $category = 'example-addon-tools';

	/**
	 * Register a category whose label differs from its slug.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->set_user_access_mode( $this->user_id, MainWP_Assistant_Policies::MODE_FULL_ACCESS );
		\WP_Ability_Categories_Registry::get_instance()->register(
			$this->category,
			array( 'label' => 'MainWP Traffic Reports', 'description' => 'Traffic reporting.' )
		);
		$this->clear_catalog_cache();
	}

	/**
	 * Restore registry and request state for neighboring tests.
	 */
	public function tearDown(): void {
		if ( $this->registered_core ) {
			foreach ( array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' ) as $id ) {
				if ( \WP_Abilities_Registry::get_instance()->is_registered( $id ) ) {
					wp_unregister_ability( $id );
				}
			}
			foreach ( array( 'site', 'user' ) as $slug ) {
				if ( \WP_Ability_Categories_Registry::get_instance()->is_registered( $slug ) ) {
					wp_unregister_ability_category( $slug );
				}
			}
		}
		foreach ( $this->ability_ids as $id ) {
			wp_unregister_ability( $id );
		}
		wp_unregister_ability_category( $this->category );
		$this->clear_catalog_cache();
		parent::tearDown();
	}

	/**
	 * Invalidate the discovery cache between tests.
	 */
	private function clear_catalog_cache() {
		delete_transient( 'mainwp_assistant_ability_schemas_v' . MainWP_Assistant::ABILITY_SCHEMA_CACHE_REV );
	}

	/**
	 * Register a strict schema so injected server flags fail validation.
	 *
	 * @param string $id          Ability ID.
	 * @param array  $annotations Raw annotations.
	 * @param bool   $dry_run     Accept preview input.
	 * @param array  $meta        Additional ability metadata.
	 * @param array  $schema      Optional custom input schema.
	 * @return string
	 */
	private function register_ability( $id, $annotations = array(), $dry_run = false, $meta = array(), $schema = null ) {
		$properties = array( 'value' => array( 'type' => 'string' ) );
		if ( $dry_run ) {
			$properties['dry_run'] = array( 'type' => 'boolean' );
		}
		\WP_Abilities_Registry::get_instance()->register(
			$id,
			array(
				'label'               => 'Example operation',
				'description'         => 'Run the example operation.',
				'category'            => $this->category,
				'input_schema'        => $schema ?? array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'execute_callback'    => function ( $input ) use ( $id ) {
					$this->calls[ $id ][] = $input;
					return array( 'received' => $input );
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => $meta + array(
					'show_in_rest' => true,
					'annotations'  => $annotations,
				),
			)
		);
		if ( ! in_array( $id, $this->ability_ids, true ) ) {
			$this->ability_ids[] = $id;
		}
		return $id;
	}

	/**
	 * Register WordPress's core abilities, which the core test library unhooks.
	 *
	 * The registration functions refuse to run outside their init actions, and
	 * firing those actions would re-run every plugin's registrations.
	 */
	private function register_core_abilities() {
		global $wp_current_filter;
		if ( \WP_Abilities_Registry::get_instance()->is_registered( 'core/get-site-info' ) ) {
			return;
		}
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		wp_register_core_ability_categories();
		array_pop( $wp_current_filter );
		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_core_abilities();
		array_pop( $wp_current_filter );
		$this->registered_core = true;
		$this->clear_catalog_cache();
	}

	/**
	 * Invoke an Assistant method without changing its visibility.
	 *
	 * @param object $assistant Assistant instance.
	 * @param string $name      Method name.
	 * @param mixed  ...$args   Arguments.
	 * @return mixed
	 */
	private function invoke( $assistant, $name, ...$args ) {
		$method = new \ReflectionMethod( MainWP_Assistant::class, $name );
		$method->setAccessible( true );
		return $method->invoke( $assistant, ...$args );
	}

	/** Discovery has no category allow-list and retains REST visibility. */
	public function test_discovers_addon_category_and_excludes_hidden_ability() {
		$visible = $this->register_ability( 'example-addon/visible', array( 'readonly' => true ) );
		$hidden  = $this->register_ability( 'example-addon/hidden', array( 'readonly' => true ), false, array( 'show_in_rest' => false ) );
		$schemas = $this->invoke( new MainWP_Assistant(), 'get_ability_schemas' );
		$this->assertArrayHasKey( $visible, $schemas );
		$this->assertArrayNotHasKey( $hidden, $schemas );
		$this->assertSame( $this->category, $schemas[ $visible ]['category'] );
		$this->assertSame(
			array( 'label', 'description', 'input_schema', 'methods', 'annotations', 'category' ),
			array_keys( $schemas[ $visible ] )
		);
		$schema = wp_get_ability( $visible )->get_input_schema();
		$this->assertSame( function_exists( 'wp_prepare_json_schema_for_client' ) ? wp_prepare_json_schema_for_client( $schema ) : $schema, $schemas[ $visible ]['input_schema'] );
	}

	/** Unknown annotations require a card even with destructive-only confirmation. */
	public function test_unlisted_write_without_dry_run_gets_no_preview_card() {
		$id = $this->register_ability( 'example-addon/unknown-write' );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $this->user_id );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, array( 'value' => 'original' ), $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertArrayNotHasKey( 'preview', $result );
		$this->assertSame( array( 'value' => 'original' ), $result['params'] );
		$this->assertEmpty( $this->calls );
	}

	/** The presence of a dry_run field does not authorize a pre-confirmation call. */
	public function test_unlisted_write_with_dry_run_requires_preview_opt_in() {
		$id        = $this->register_ability( 'example-addon/untrusted-preview', array(), true );
		$params    = array( 'value' => 'original' );
		$ai_params = $params + array(
			'dry_run' => true,
			'confirm' => true,
		);
		$result    = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, $ai_params, $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertArrayNotHasKey( 'preview', $result );
		$this->assertSame( $params, $result['params'] );
		$this->assertEmpty( $this->calls );
	}

	/** Only an opt-in permits preview, and confirmation overrides the optional default. */
	public function test_unlisted_write_with_dry_run_previews_before_confirmation() {
		$schema    = array(
			'type'                 => 'object',
			'properties'           => array(
				'value'   => array( 'type' => 'string' ),
				'dry_run' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'additionalProperties' => false,
		);
		$id        = $this->register_ability( 'example-addon/preview-write', array(), true, array( 'mainwp_assistant_preview' => true ), $schema );
		$assistant = new MainWP_Assistant();
		$params    = array( 'value' => 'previewed' );
		$result    = $this->invoke( $assistant, 'execute_tool_call', $id, $params, $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertSame( array( 'received' => $params + array( 'dry_run' => true ) ), $result['preview'] );
		$this->assertSame( array( $params + array( 'dry_run' => true ) ), $this->calls[ $id ] );
		$this->assertSame( $params, $result['params'] );
		$confirmed = $this->confirm_card( $assistant, $result );
		$this->assertTrue( $confirmed['success'], wp_json_encode( $confirmed ) );
		$this->assertSame( array( $params + array( 'dry_run' => true ), $params + array( 'dry_run' => false ) ), $this->calls[ $id ] );
	}

	/** A warmed catalog and an existing card cannot authorize an ability that is now private. */
	public function test_hidden_ability_is_removed_from_warm_catalog_and_cannot_confirm() {
		$id        = $this->register_ability( 'example-addon/changes-visibility' );
		$assistant = new MainWP_Assistant();
		$tools     = $this->invoke( $assistant, 'build_filtered_tools', $this->user_id, null, false );
		$this->assertArrayHasKey( $id, $tools );
		$card = $this->invoke( $assistant, 'execute_tool_call', $id, array( 'value' => 'original' ), $this->user_id );
		$this->assertTrue( $card['requires_confirmation'] ?? false );
		wp_unregister_ability( $id );
		$this->register_ability( $id, array(), false, array( 'show_in_rest' => false ) );

		$tools = $this->invoke( $assistant, 'build_filtered_tools', $this->user_id, null, false );
		$this->assertArrayNotHasKey( $id, $tools );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, array(), $this->user_id );
		$this->assertSame( 'ability_not_found', $result['error'] ?? '' );
		$confirmed = $this->confirm_card( $assistant, $card );
		$this->assertFalse( $confirmed['success'] );
		$this->assertSame( 'Unknown ability.', $confirmed['data']['message'] );
		$this->assertEmpty( $this->calls );

		wp_unregister_ability( $id );
		$this->register_ability( $id );
		$this->assertArrayHasKey( $id, $this->invoke( $assistant, 'build_filtered_tools', $this->user_id, null, false ) );
	}

	/** Providers and the executor both exclude incompatible root input types. */
	public function test_non_object_input_is_not_advertised_or_executed() {
		$list_schema = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$scalar      = $this->register_ability( 'example-addon/scalar-input', array( 'readonly' => true ), false, array(), array( 'type' => 'string' ) );
		$list        = $this->register_ability( 'example-addon/list-input', array( 'readonly' => true ), false, array(), $list_schema );
		$object      = $this->register_ability( 'example-addon/object-input', array( 'readonly' => true ) );

		$assistant    = new MainWP_Assistant();
		$declarations = $this->invoke( $assistant, 'build_function_declarations', $this->user_id, array( $this->category ) );
		$names        = array_map( static fn( $declaration ) => $declaration->getName(), $declarations );
		$this->assertContains( \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $object ), $names );
		foreach ( array( $scalar, $list ) as $id ) {
			$this->assertNotContains( \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $id ), $names );
			$result = $this->invoke( $assistant, 'execute_tool_call', $id, array(), $this->user_id );
			$this->assertSame( 'ability_not_found', $result['error'] ?? '' );
		}
		$this->assertEmpty( $this->calls );
	}

	/** An explicitly additive write still follows the user's confirmation level. */
	public function test_unlisted_additive_write_follows_confirmation_level() {
		$id       = $this->register_ability( 'example-addon/additive-write', array( 'destructive' => false ) );
		$policies = MainWP_Assistant_Policies::instance();
		$this->set_user_access_mode( $this->user_id, MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM );
		$policies->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $this->user_id );
		$params = array( 'value' => 'additive' );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, $params, $this->user_id );
		$this->assertSame( array( 'received' => $params ), $result );
		$policies->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_ALL, $this->user_id );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, $params, $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertCount( 1, $this->calls[ $id ] );
	}

	/** Raw annotations distinguish unknown writes from explicitly additive writes. */
	public function test_third_party_access_modes_and_mainwp_groups() {
		$unknown     = $this->register_ability( 'example-addon/unknown' );
		$destructive = $this->register_ability( 'example-addon/destructive', array( 'destructive' => true ) );
		$readonly    = $this->register_ability( 'example-addon/readonly', array( 'readonly' => true ) );
		$policies    = MainWP_Assistant_Policies::instance();
		$this->set_user_access_mode( $this->user_id, $policies::MODE_READ_WRITE_CONFIRM );
		$this->assertFalse( $policies->is_ability_allowed( $unknown, $this->user_id ) );
		$this->assertFalse( $policies->is_ability_allowed( $destructive, $this->user_id ) );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $destructive, array(), $this->user_id );
		$this->assertSame( 'policy_denied', $result['error'] );
		$this->set_user_access_mode( $this->user_id, $policies::MODE_READ_ONLY );
		$this->assertTrue( $policies->is_ability_allowed( $readonly, $this->user_id ) );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $readonly, array(), $this->user_id );
		$this->assertSame( array( 'received' => array() ), $result );
		$this->set_user_access_mode( $this->user_id, $policies::MODE_FULL_ACCESS );
		$this->assertTrue( $policies->is_ability_allowed( $destructive, $this->user_id ) );
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $destructive, array(), $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertTrue( $policies->is_listed_ability( 'mainwp/delete-tag-v1' ) );
		$this->assertTrue( $policies->is_listed_ability( 'mainwp/add-tag-v1' ) );
		$this->assertTrue( $policies->requires_confirmation( 'mainwp/delete-tag-v1' ) );
		$this->assertFalse( $policies->requires_confirmation( 'mainwp/add-tag-v1' ) );
	}

	/** Existing MainWP actions keep their preview and confirmation-level behavior. */
	public function test_mainwp_confirmation_behavior_is_preserved_with_open_discovery() {
		$this->register_ability( 'example-addon/discovered', array( 'readonly' => true ) );
		$assistant = new MainWP_Assistant();
		$schemas   = $this->invoke( $assistant, 'get_ability_schemas' );
		$this->assertArrayHasKey( 'example-addon/discovered', $schemas );
		$tag_id = $this->create_test_tag( array( 'name' => 'Original tag' ) );
		$result = $this->invoke( $assistant, 'execute_tool_call', 'mainwp/delete-tag-v1', array( 'tag_id' => $tag_id ), $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
		$this->assertArrayHasKey( 'preview', $result );
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE, $this->user_id );
		$result = $this->invoke( $assistant, 'execute_tool_call', 'mainwp/add-tag-v1', array( 'name' => 'Added tag' ), $this->user_id );
		$this->assertArrayNotHasKey( 'requires_confirmation', $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->created_tag_ids[] = (int) $result['id'];
		MainWP_Assistant_Policies::instance()->set_confirmation_level( MainWP_Assistant_Policies::CONFIRM_ALL, $this->user_id );
		$result = $this->invoke( $assistant, 'execute_tool_call', 'mainwp/add-tag-v1', array( 'name' => 'Pending tag' ), $this->user_id );
		$this->assertTrue( $result['requires_confirmation'] ?? false );
	}

	/** A category label contributes keywords before add-on overrides run. */
	public function test_category_label_keywords_are_available_to_the_filter() {
		$this->register_ability( 'example-addon/traffic', array( 'readonly' => true ) );
		$map    = null;
		$filter = static function ( $keywords ) use ( &$map ) {
			$map = $keywords;
			return $keywords;
		};
		add_filter( 'mainwp_assistant_tool_category_keywords', $filter );
		try {
			$categories = $this->invoke( new MainWP_Assistant(), 'classify_message_categories', 'Show Traffic Reports' );
		} finally {
			remove_filter( 'mainwp_assistant_tool_category_keywords', $filter );
		}
		$this->assertContains( $this->category, $categories );
		$this->assertContains( 'traffic', $map[ $this->category ] );
		$this->assertContains( 'report', $map[ $this->category ] );
		$this->assertNotContains( 'mainwp', $map[ $this->category ] );
	}

	/** Core abilities pass policy filtering and site-info executes as the administrator. */
	public function test_core_site_info_is_reachable_for_an_admin() {
		$this->register_core_abilities();
		$this->set_user_access_mode( $this->user_id, MainWP_Assistant_Policies::MODE_READ_ONLY );
		$assistant = new MainWP_Assistant();
		$tools     = $this->invoke( $assistant, 'build_filtered_tools', $this->user_id, null, false );
		$this->assertArrayHasKey( 'core/get-site-info', $tools );
		foreach ( array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' ) as $id ) {
			$this->assertTrue( MainWP_Assistant_Policies::instance()->is_ability_allowed( $id, $this->user_id ) );
			$meta = wp_get_ability( $id )->get_meta();
			if ( ! empty( $meta['show_in_rest'] ) ) {
				$this->assertArrayHasKey( $id, $tools );
				$result = $this->invoke( $assistant, 'execute_tool_call', $id, array(), $this->user_id );
				$this->assertArrayNotHasKey( 'error', $result, $id );
			}
		}
	}

	/** WordPress requires null, rather than an empty object, for schema-less input. */
	public function test_schema_less_readonly_ability_accepts_empty_model_arguments() {
		$id = 'example-addon/no-input';
		$this->register_ability( $id, array( 'readonly' => true ) );
		$registry = \WP_Abilities_Registry::get_instance();
		$registry->unregister( $id );
		$registry->register(
			$id,
			array(
				'label'               => 'No input',
				'description'         => 'Read without arguments.',
				'category'            => $this->category,
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true ) ),
			)
		);
		$result = $this->invoke( new MainWP_Assistant(), 'execute_tool_call', $id, array(), $this->user_id );
		$this->assertSame( array(), $result );
	}

	/** A capped full catalog offers expansion, which widens to 128 tools for later hops. */
	public function test_unnarrowed_catalog_expands_beyond_96_for_the_rest_of_the_turn() {
		for ( $i = 0; $i < 130; ++$i ) {
			$this->register_ability( 'example-addon/read-' . $i, array( 'readonly' => true ) );
		}
		$assistant          = new Open_Abilities_Assistant();
		$assistant->results = array(
			$this->function_result( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME ),
			$this->function_result( 'wpab__example-addon__read-99' ),
			$this->make_text_result( 'Complete.' ),
		);
		$result = $this->invoke( $assistant, 'execute_ai_chat', 'Hello there', 0, array(), false, $this->user_id );
		$this->assertNotWPError( $result );
		$this->assertContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $assistant->declarations[0] );
		$this->assertCount( 97, $assistant->declarations[0] );
		$this->assertCount( MainWP_Assistant::MAX_EXPANDED_TOOL_DECLARATIONS, $assistant->declarations[1] );
		$this->assertSame( $assistant->declarations[1], $assistant->declarations[2] );
		$this->assertNotContains( MainWP_Assistant::EXPAND_TOOLSET_FUNCTION_NAME, $assistant->declarations[1] );
		$this->assertCount( 1, $this->calls['example-addon/read-99'] );
	}

	/**
	 * Create a local function-call generation result.
	 *
	 * @param string $name Function name.
	 * @return object
	 */
	private function function_result( $name ) {
		return $this->make_ai_result(
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( new \WordPress\AiClient\Tools\DTO\FunctionCall( 'call-' . wp_generate_uuid4(), $name, array() ) ) )
		);
	}

	/** Short and shortened names resolve through the same allowed catalog used by the loop. */
	public function test_function_names_round_trip_core_and_long_hyphenated_namespace() {
		$this->register_core_abilities();
		$long = 'my-plugin-name-with-a-very-long-hyphenated-namespace-for-testing/do-thing';
		$this->register_ability( $long, array( 'readonly' => true ) );
		$this->register_ability( 'my-plugin-name/do-thing', array( 'readonly' => true ) );
		$assistant = new Open_Abilities_Assistant();
		$tools     = $this->invoke( $assistant, 'build_filtered_tools', $this->user_id, null, false );
		$map       = $this->invoke( $assistant, 'build_ability_function_map', $tools );
		$names     = array();
		foreach ( $this->invoke( $assistant, 'build_function_declarations', $this->user_id, null, false ) as $declaration ) {
			$names[] = $declaration->getName();
			$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $declaration->getName() );
		}
		$this->assertSame( 'core/get-site-info', $map['wpab__core__get-site-info'] );
		$this->assertSame( 'my-plugin-name/do-thing', $map['wpab__my-plugin-name__do-thing'] );
		$alias = array_search( $long, $map, true );
		$this->assertContains( $alias, $names );
		$this->assertSame( 64, strlen( $alias ) );
		$this->assertSame( substr( \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $long ), 0, 51 ) . '_' . substr( hash( 'sha256', $long ), 0, 12 ), $alias );
		$assistant->results = array( $this->function_result( $alias ), $this->function_result( 'wpab__core__get-site-info' ), $this->make_text_result( 'Complete.' ) );
		$result = $this->invoke( $assistant, 'execute_ai_chat', 'Hello there', 0, array(), false, $this->user_id );
		$this->assertNotWPError( $result );
		$this->assertCount( 1, $this->calls[ $long ] );
		$this->assertSame( array( $long, 'core/get-site-info' ), array_column( $result['executed_abilities'], 'ability' ) );
	}

	/** Confirming a strict no-preview write uses stored input once and audits that same input. */
	public function test_confirm_no_preview_uses_original_params_once_and_fires_hook() {
		$id        = $this->register_ability( 'example-addon/confirm-once' );
		$assistant = new MainWP_Assistant();
		$params    = array( 'value' => 'original' );
		$card      = $this->invoke( $assistant, 'execute_tool_call', $id, $params, $this->user_id );
		$this->assertTrue( $card['requires_confirmation'] ?? false );
		$state = MainWP_Assistant_Confirmation_State::instance();
		$token = $state->arm_transient( $card, array(), array(), $this->user_id );
		update_user_meta( $this->user_id, 'mainwp_assistant_history_opt_out', true );
		$confirmed = array();
		$spy       = static function ( $ability, $input ) use ( &$confirmed ) {
			$confirmed[] = array( $ability, $input );
		};
		add_action( 'mainwp_assistant_ability_confirmed', $spy, 10, 2 );
		try {
			$request = array( 'ability' => $id, 'params' => wp_json_encode( array( 'value' => 'tampered' ) ), 'conversation_id' => 0, 'request_id' => $token );
			$first   = $this->confirm_request( $assistant, $request );
			$second  = $this->confirm_request( $assistant, $request );
		} finally {
			remove_action( 'mainwp_assistant_ability_confirmed', $spy, 10 );
			delete_user_meta( $this->user_id, 'mainwp_assistant_history_opt_out' );
			delete_transient( MainWP_Assistant_Confirmation_State::TRANSIENT_PREFIX . $token );
			delete_option( MainWP_Assistant_Confirmation_State::CLAIM_OPTION_PREFIX . $token );
		}
		$this->assertTrue( $first['success'] );
		$this->assertFalse( $second['success'] );
		$this->assertSame( array( $params ), $this->calls[ $id ] );
		$this->assertSame( array( array( $id, $params ) ), $confirmed );
	}

	/** A schema that requires both run flags gets server-set values for preview and for the confirmed run. */
	public function test_unlisted_write_requiring_run_flags_previews_then_confirms() {
		$id = $this->register_ability( 'example-addon/strict-delete', array( 'destructive' => true ), true );
		\WP_Abilities_Registry::get_instance()->unregister( $id );
		\WP_Abilities_Registry::get_instance()->register(
			$id,
			array(
				'label'               => 'Strict delete',
				'description'         => 'Preview or delete.',
				'category'            => $this->category,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'value'   => array( 'type' => 'string' ),
						'dry_run' => array( 'type' => 'boolean' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'value', 'dry_run', 'confirm' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => function ( $input ) use ( $id ) {
					$this->calls[ $id ][] = $input;
					return array( 'received' => $input );
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'show_in_rest'             => true,
					'annotations'              => array( 'destructive' => true ),
					'mainwp_assistant_preview' => true,
				),
			)
		);
		$this->clear_catalog_cache();
		$assistant = new MainWP_Assistant();
		$card      = $this->invoke( $assistant, 'execute_tool_call', $id, array( 'value' => 'x', 'dry_run' => false, 'confirm' => true ), $this->user_id );
		$this->assertArrayNotHasKey( 'error', $card, $card['message'] ?? '' );
		$this->assertTrue( $card['requires_confirmation'] ?? false );
		$this->assertSame( array( 'value' => 'x' ), $card['params'] );
		$this->assertEquals( array( array( 'value' => 'x', 'dry_run' => true, 'confirm' => false ) ), $this->calls[ $id ] );
		$confirmed = $this->confirm_card( $assistant, $card );
		$this->assertTrue( $confirmed['success'], wp_json_encode( $confirmed ) );
		$this->assertCount( 2, $this->calls[ $id ] );
		$this->assertEquals( array( 'value' => 'x', 'dry_run' => false, 'confirm' => true ), $this->calls[ $id ][1] );
	}

	/** Filler words and the always-included site category do not narrow the catalog. */
	public function test_generic_category_words_do_not_narrow_the_catalog() {
		$categories = \WP_Ability_Categories_Registry::get_instance();
		$categories->register( 'example-utilities', array( 'label' => 'Tools and Utilities', 'description' => 'Utilities.' ) );
		$id = 'example-addon/utilities';
		\WP_Abilities_Registry::get_instance()->register(
			$id,
			array(
				'label'               => 'List utilities',
				'description'         => 'List utilities.',
				'category'            => 'example-utilities',
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true ) ),
			)
		);
		$this->ability_ids[] = $id;
		$this->clear_catalog_cache();
		try {
			$assistant = new MainWP_Assistant();
			$this->assertNull( $this->invoke( $assistant, 'classify_message_categories', 'How do I handle this?' ) );
			$this->assertNull( $this->invoke( $assistant, 'classify_message_categories', 'Tell me about my website' ) );
			$this->assertNull( $this->invoke( $assistant, 'classify_message_categories', 'Is my site slow?' ) );
			$this->assertContains( 'example-utilities', (array) $this->invoke( $assistant, 'classify_message_categories', 'Which utilities are installed?' ) );
		} finally {
			wp_unregister_ability( $id );
			$this->ability_ids = array_diff( $this->ability_ids, array( $id ) );
			$categories->unregister( 'example-utilities' );
		}
	}

	/**
	 * Confirm a pending action with conversation history disabled.
	 *
	 * @param MainWP_Assistant $assistant Assistant instance.
	 * @param array            $card      Pending action.
	 * @return array
	 */
	private function confirm_card( $assistant, $card ) {
		$token = MainWP_Assistant_Confirmation_State::instance()->arm_transient( $card, array(), array(), $this->user_id );
		update_user_meta( $this->user_id, 'mainwp_assistant_history_opt_out', true );
		try {
			return $this->confirm_request(
				$assistant,
				array(
					'ability'         => $card['ability'],
					'params'          => wp_json_encode( $card['params'] ),
					'conversation_id' => 0,
					'request_id'      => $token,
				)
			);
		} finally {
			delete_user_meta( $this->user_id, 'mainwp_assistant_history_opt_out' );
			delete_transient( MainWP_Assistant_Confirmation_State::TRANSIENT_PREFIX . $token );
			delete_option( MainWP_Assistant_Confirmation_State::CLAIM_OPTION_PREFIX . $token );
		}
	}

	/**
	 * Send an AJAX confirmation without terminating PHPUnit.
	 *
	 * @param object $assistant Assistant instance.
	 * @param array  $request   Request body.
	 * @return array
	 */
	private function confirm_request( $assistant, $request ) {
		$die_handler = static function () {
			return static function () {
				throw new \RuntimeException( 'confirmation_response_complete' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = $request + array( 'security' => wp_create_nonce( MainWP_Assistant::AJAX_CONFIRM_ACTION ) );
		$_REQUEST = $_POST;
		$level    = error_reporting();
		error_reporting( $level & ~E_WARNING );
		ob_start();
		try {
			$assistant->handle_confirm_action();
		} catch ( \RuntimeException $exception ) {
			if ( 'confirmation_response_complete' !== $exception->getMessage() ) {
				throw $exception;
			}
		} finally {
			$output = ob_get_clean();
			error_reporting( $level );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $output, true );
	}
}
