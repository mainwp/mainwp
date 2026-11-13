<?php
/**
 * Tests for AI schema normalization in MainWP_Assistant.
 *
 * Verifies that JSON Schema array types are normalized to single strings
 * for compatibility with OpenAI and Anthropic function-calling APIs.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use ReflectionMethod;

/**
 * Test class for schema normalization.
 */
class Test_Assistant_Schema_Normalization extends MainWP_Assistant_Test_Case {

	/**
	 * Assistant instance.
	 *
	 * @var MainWP_Assistant
	 */
	private $assistant;

	/**
	 * Reflection method for normalize_schema_for_ai.
	 *
	 * @var ReflectionMethod
	 */
	private $normalize_method;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->assistant        = MainWP_Assistant::instance();
		$this->normalize_method = new ReflectionMethod( MainWP_Assistant::class, 'normalize_schema_for_ai' );
		$this->normalize_method->setAccessible( true );
	}

	/**
	 * Helper to invoke the private normalize method.
	 *
	 * @param array $schema Schema to normalize.
	 *
	 * @return array Normalized schema.
	 */
	private function normalize( $schema ) {
		return $this->normalize_method->invoke( $this->assistant, $schema );
	}

	// =========================================================================
	// TYPE NORMALIZATION
	// =========================================================================

	/**
	 * Test array type with null picks first non-null type.
	 */
	public function test_array_type_with_null_returns_object() {
		$schema = array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['type'] );
		$this->assertInstanceOf( \stdClass::class, $result['properties'], 'Empty properties array should become stdClass for JSON {}' );
	}

	/**
	 * Test empty properties array is coerced to stdClass for JSON {} encoding.
	 */
	public function test_empty_properties_array_becomes_stdclass() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$result = $this->normalize( $schema );

		$this->assertInstanceOf( \stdClass::class, $result['properties'], 'Empty properties array should become stdClass for JSON {}' );
		$this->assertSame( '{}', wp_json_encode( $result['properties'] ) );
	}

	/**
	 * Test array type with multiple non-null uses priority order.
	 */
	public function test_multi_non_null_uses_priority() {
		$schema = array( 'type' => array( 'integer', 'string' ) );
		$result = $this->normalize( $schema );

		// string comes before integer in priority: object > array > string > integer.
		$this->assertSame( 'string', $result['type'] );
	}

	/**
	 * Test array type picks object over other types.
	 */
	public function test_object_has_highest_priority() {
		$schema = array( 'type' => array( 'string', 'object', 'null' ) );
		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['type'] );
	}

	/**
	 * Test array type picks array second highest.
	 */
	public function test_array_type_priority() {
		$schema = array( 'type' => array( 'string', 'array', 'null' ) );
		$result = $this->normalize( $schema );

		$this->assertSame( 'array', $result['type'] );
	}

	/**
	 * Test string type passes through unchanged.
	 */
	public function test_string_type_passthrough() {
		$schema = array(
			'type'       => 'string',
			'minLength'  => 1,
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'string', $result['type'] );
		$this->assertSame( 1, $result['minLength'] );
	}

	/**
	 * Test missing type defaults to object.
	 */
	public function test_missing_type_defaults_to_object() {
		$schema = array(
			'properties' => array(
				'name' => array( 'type' => 'string' ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['type'] );
	}

	/**
	 * Test object type without properties gets empty properties added.
	 *
	 * OpenAI requires object schemas to have a properties field.
	 */
	public function test_object_without_properties_gets_empty_properties() {
		$schema = array(
			'type'    => array( 'object', 'null' ),
			'default' => array(),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['type'] );
		$this->assertInstanceOf( \stdClass::class, $result['properties'], 'Empty properties should be stdClass for JSON {}' );
		$this->assertSame( '{}', wp_json_encode( $result['properties'] ) );
	}

	/**
	 * Test object with existing properties is not overwritten.
	 */
	public function test_object_with_properties_not_overwritten() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertIsArray( $result['properties'] );
		$this->assertArrayHasKey( 'id', $result['properties'] );
	}

	/**
	 * Test missing type is not added when oneOf is present.
	 */
	public function test_missing_type_skipped_with_oneof() {
		$schema = array(
			'oneOf' => array(
				array( 'type' => 'string' ),
				array( 'type' => 'integer' ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertArrayNotHasKey( 'type', $result );
	}

	// =========================================================================
	// RECURSIVE NORMALIZATION
	// =========================================================================

	/**
	 * Test nested properties are normalized recursively.
	 */
	public function test_nested_properties_normalized() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name'  => array( 'type' => array( 'string', 'null' ) ),
				'count' => array( 'type' => array( 'integer', 'null' ) ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'string', $result['properties']['name']['type'] );
		$this->assertSame( 'integer', $result['properties']['count']['type'] );
	}

	/**
	 * Test array items schema is normalized.
	 */
	public function test_items_schema_normalized() {
		$schema = array(
			'type'  => 'array',
			'items' => array(
				'type'       => array( 'object', 'null' ),
				'properties' => array(
					'id' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['items']['type'] );
		$this->assertSame( 'integer', $result['items']['properties']['id']['type'] );
	}

	/**
	 * Test oneOf entries are normalized.
	 */
	public function test_oneof_entries_normalized() {
		$schema = array(
			'oneOf' => array(
				array( 'type' => array( 'string', 'null' ) ),
				array( 'type' => array( 'integer', 'null' ) ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'string', $result['oneOf'][0]['type'] );
		$this->assertSame( 'integer', $result['oneOf'][1]['type'] );
	}

	/**
	 * Test anyOf entries are normalized.
	 */
	public function test_anyof_entries_normalized() {
		$schema = array(
			'anyOf' => array(
				array( 'type' => array( 'object', 'null' ) ),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['anyOf'][0]['type'] );
	}

	/**
	 * Test allOf entries are normalized.
	 */
	public function test_allof_entries_normalized() {
		$schema = array(
			'allOf' => array(
				array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'field' => array( 'type' => array( 'boolean', 'null' ) ),
					),
				),
			),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'object', $result['allOf'][0]['type'] );
		$this->assertSame( 'boolean', $result['allOf'][0]['properties']['field']['type'] );
	}

	// =========================================================================
	// ATTRIBUTE PRESERVATION
	// =========================================================================

	/**
	 * Test non-type attributes are preserved during normalization.
	 */
	public function test_attributes_preserved() {
		$schema = array(
			'type'                 => array( 'object', 'null' ),
			'required'             => array( 'name', 'id' ),
			'description'          => 'A test schema',
			'additionalProperties' => false,
			'properties'           => array(
				'name' => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'The name',
					'enum'        => array( 'foo', 'bar' ),
					'default'     => 'foo',
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'count' => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 0,
					'maximum' => 100,
					'format'  => 'int32',
				),
			),
		);

		$result = $this->normalize( $schema );

		// Top-level attributes preserved.
		$this->assertSame( array( 'name', 'id' ), $result['required'] );
		$this->assertSame( 'A test schema', $result['description'] );
		$this->assertFalse( $result['additionalProperties'] );

		// Property attributes preserved.
		$name = $result['properties']['name'];
		$this->assertSame( 'string', $name['type'] );
		$this->assertSame( 'The name', $name['description'] );
		$this->assertSame( array( 'foo', 'bar' ), $name['enum'] );
		$this->assertSame( 'foo', $name['default'] );
		$this->assertSame( 1, $name['minLength'] );
		$this->assertSame( 255, $name['maxLength'] );

		// Numeric attributes preserved.
		$count = $result['properties']['count'];
		$this->assertSame( 'integer', $count['type'] );
		$this->assertSame( 0, $count['minimum'] );
		$this->assertSame( 100, $count['maximum'] );
		$this->assertSame( 'int32', $count['format'] );
	}

	/**
	 * Gemini tool declarations must omit array uniqueness at every schema level.
	 */
	public function test_tool_schema_omits_unique_items_and_preserves_other_constraints() {
		$array_schema = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'maxItems'    => 100,
			'items'       => array( 'type' => 'integer' ),
		);
		$schema       = array(
			'type'       => 'object',
			'properties' => array(
				'site_ids' => $array_schema,
				'groups'   => array(
					'type'  => 'array',
					'items' => array( 'anyOf' => array( $array_schema, array( 'type' => 'string' ) ) ),
				),
			),
		);

		$result = $this->normalize( $schema );

		$expected = $array_schema;
		unset( $expected['uniqueItems'] );
		$this->assertSame( $expected, $result['properties']['site_ids'] );
		$this->assertSame( $expected, $result['properties']['groups']['items']['anyOf'][0] );
		$this->assertTrue( $schema['properties']['site_ids']['uniqueItems'] );
	}

	// =========================================================================
	// ENUM MEMBER NORMALIZATION
	// =========================================================================

	/**
	 * Test integer enum members move into the description for non-string types.
	 *
	 * Google's Schema proto types enum as repeated string, so integer members
	 * (the 0/1/2 verify_certificate flags) fail the whole request with a 400.
	 */
	public function test_integer_enum_moves_into_description() {
		$schema = array(
			'type'        => 'integer',
			'description' => 'Verify certificate.',
			'enum'        => array( 0, 1, 2 ),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'integer', $result['type'] );
		$this->assertArrayNotHasKey( 'enum', $result );
		$this->assertSame( 'Verify certificate. Allowed values: 0, 1, 2.', $result['description'] );
	}

	/**
	 * Test integer enum without a description still documents the values.
	 */
	public function test_integer_enum_without_description_still_documents_values() {
		$schema = array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		);

		$result = $this->normalize( $schema );

		$this->assertArrayNotHasKey( 'enum', $result );
		$this->assertSame( 'Allowed values: 0, 1.', $result['description'] );
	}

	/**
	 * Test mixed enum members under a collapsed string type are cast to strings.
	 *
	 * A union type like array('string','integer') collapses to string, which
	 * would leave integer enum members under a string-typed schema.
	 */
	public function test_mixed_enum_under_collapsed_string_type_cast_to_strings() {
		$schema = array(
			'type' => array( 'string', 'integer' ),
			'enum' => array( 'auto', 0, 1 ),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( 'string', $result['type'] );
		$this->assertSame( array( 'auto', '0', '1' ), $result['enum'] );
	}

	/**
	 * Test all-string enum members are left untouched.
	 */
	public function test_all_string_enum_untouched() {
		$schema = array(
			'type' => 'string',
			'enum' => array( 'foo', 'bar' ),
		);

		$result = $this->normalize( $schema );

		$this->assertSame( array( 'foo', 'bar' ), $result['enum'] );
		$this->assertArrayNotHasKey( 'description', $result );
	}

	/**
	 * Test integer enums nested in properties are normalized.
	 */
	public function test_nested_integer_enum_normalized() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'verify_certificate' => array(
					'type'        => 'integer',
					'description' => 'SSL verification level.',
					'enum'        => array( 0, 1, 2 ),
				),
			),
		);

		$result = $this->normalize( $schema );

		$prop = $result['properties']['verify_certificate'];
		$this->assertSame( 'integer', $prop['type'] );
		$this->assertArrayNotHasKey( 'enum', $prop );
		$this->assertSame( 'SSL verification level. Allowed values: 0, 1, 2.', $prop['description'] );
	}

	// =========================================================================
	// INTEGRATION: TOOL BUILDER OUTPUT
	// =========================================================================

	/**
	 * Recursively assert all type fields in a schema are strings.
	 *
	 * @param array  $schema  Schema to check.
	 * @param string $context Context label for assertion messages.
	 *
	 * @return void
	 */
	private function assert_schema_has_string_types( $schema, $context = '' ) {
		if ( ! is_array( $schema ) ) {
			return;
		}

		if ( isset( $schema['type'] ) ) {
			$this->assertIsString(
				$schema['type'],
				sprintf( '%s: type must be a string, got %s', $context, gettype( $schema['type'] ) )
			);
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $prop ) {
				$this->assert_schema_has_string_types( $prop, "{$context}.properties.{$key}" );
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$this->assert_schema_has_string_types( $schema['items'], "{$context}.items" );
		}

		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $combiner ) {
			if ( isset( $schema[ $combiner ] ) && is_array( $schema[ $combiner ] ) ) {
				foreach ( $schema[ $combiner ] as $i => $sub ) {
					$this->assert_schema_has_string_types( $sub, "{$context}.{$combiner}[{$i}]" );
				}
			}
		}
	}
}
