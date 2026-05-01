<?php
/**
 * MainWP REST credential strip tests (MWP-1541 + MWP-1542).
 *
 * Two layers exercised here:
 *
 *  - The static helper Mainwp_Rest_Api_V1::strip_sensitive_site_fields()
 *    that v1 callbacks now call before returning site data; covers single
 *    object, array, list, and edge cases.
 *
 *  - The v2 schema declarations for http_pass / http_user / uniqueId.
 *    The schema-driven response filter at class-mainwp-rest-controller.php
 *    strips fields whose 'context' list does not include the request's
 *    context. After MWP-1541 these three fields are 'edit' only, so a
 *    default GET (context=view) must not surface them.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_REST_Credential_Strip
 */
class Test_REST_Credential_Strip extends \WP_UnitTestCase {

	/**
	 * Build a fake site object containing every credential field that
	 * strip_sensitive_site_fields() is expected to remove, plus a few
	 * non-sensitive fields that must survive the strip.
	 */
	private function make_site_object(): \stdClass {
		$site                = new \stdClass();
		$site->id            = 42;
		$site->url           = 'https://child.example.com';
		$site->name          = 'Child Example';
		$site->privkey       = 'PRIV-PEM-DATA';
		$site->pubkey        = 'PUB-PEM-DATA';
		$site->http_user     = 'basic-user';
		$site->http_pass     = 'basic-pass';
		$site->adminname     = 'admin';
		$site->securekey     = 'secure-uuid';
		$site->uniqueId      = 'unique-id-12345';
		$site->suspended     = 0;
		return $site;
	}

	private function assert_no_sensitive_fields( $obj_or_array, string $msg = '' ): void {
		$sensitive = array( 'privkey', 'pubkey', 'http_user', 'http_pass', 'adminname', 'securekey', 'uniqueId' );
		foreach ( $sensitive as $field ) {
			if ( is_object( $obj_or_array ) ) {
				$this->assertFalse( property_exists( $obj_or_array, $field ), "Field '{$field}' must be stripped from object. {$msg}" );
			} elseif ( is_array( $obj_or_array ) ) {
				$this->assertArrayNotHasKey( $field, $obj_or_array, "Field '{$field}' must be stripped from array. {$msg}" );
			}
		}
	}

	// ---------------------------------------------------------------------
	// strip_sensitive_site_fields() unit tests
	// ---------------------------------------------------------------------

	public function test_strip_removes_all_sensitive_object_properties(): void {
		$site    = $this->make_site_object();
		$result  = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $site );
		$this->assert_no_sensitive_fields( $result );
		$this->assertSame( 42, $result->id, 'Non-sensitive field must survive' );
		$this->assertSame( 'https://child.example.com', $result->url );
		$this->assertSame( 0, $result->suspended );
	}

	public function test_strip_removes_all_sensitive_array_keys(): void {
		$site = (array) $this->make_site_object();
		$result = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $site );
		$this->assert_no_sensitive_fields( $result );
		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'https://child.example.com', $result['url'] );
	}

	public function test_strip_handles_list_of_objects(): void {
		$list = array( $this->make_site_object(), $this->make_site_object() );
		$result = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $list );
		$this->assertCount( 2, $result );
		foreach ( $result as $idx => $item ) {
			$this->assert_no_sensitive_fields( $item, "list index {$idx}" );
			$this->assertSame( 42, $item->id );
		}
	}

	public function test_strip_handles_list_of_arrays(): void {
		$list = array( (array) $this->make_site_object(), (array) $this->make_site_object() );
		$result = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $list );
		$this->assertCount( 2, $result );
		foreach ( $result as $idx => $item ) {
			$this->assert_no_sensitive_fields( $item, "list index {$idx}" );
			$this->assertSame( 42, $item['id'] );
		}
	}

	public function test_strip_is_safe_on_empty_input(): void {
		$this->assertSame( null, \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( null ) );
		$this->assertSame( array(), \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( array() ) );
		$this->assertSame( false, \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( false ) );
	}

	public function test_strip_skips_objects_that_already_lack_the_fields(): void {
		$obj      = new \stdClass();
		$obj->id  = 1;
		$obj->url = 'https://x.test';
		$result   = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $obj );
		$this->assertSame( 1, $result->id );
		$this->assertSame( 'https://x.test', $result->url );
	}

	public function test_strip_does_not_touch_associative_array_with_non_int_keys(): void {
		// An assoc-array (non-int keys) representing a single site should be
		// treated as a single item, not a list.
		$site = (array) $this->make_site_object();
		// Mix in a string-keyed nested element that should not be recursively
		// processed as a list element.
		$site['extra'] = array( 'note' => 'hello' );
		$result = \MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields( $site );
		$this->assertSame( array( 'note' => 'hello' ), $result['extra'] );
		$this->assert_no_sensitive_fields( $result );
	}

	// ---------------------------------------------------------------------
	// v2 schema declarations (MWP-1541)
	// ---------------------------------------------------------------------

	/**
	 * Reach into the v2 sites controller and pull the schema for the three
	 * fields whose context was narrowed by MWP-1541.
	 *
	 * @return array Map of field name to its schema definition.
	 */
	private function get_v2_sites_schema_subset(): array {
		// The controller is constructed lazily by the plugin; instantiating
		// directly with reflection is sufficient for schema inspection.
		$controller = new \MainWP_Rest_Sites_Controller();
		$schema     = $controller->get_item_schema();
		$properties = $schema['properties'] ?? array();
		return array(
			'http_pass' => $properties['http_pass'] ?? null,
			'http_user' => $properties['http_user'] ?? null,
			'uniqueId'  => $properties['uniqueId']  ?? null,
		);
	}

	public function test_v2_schema_http_pass_is_edit_context_only(): void {
		$fields = $this->get_v2_sites_schema_subset();
		$this->assertNotNull( $fields['http_pass'], 'http_pass must still be declared in the schema' );
		$this->assertSame( array( 'edit' ), $fields['http_pass']['context'], 'http_pass must be edit-context only' );
	}

	public function test_v2_schema_http_user_is_edit_context_only(): void {
		$fields = $this->get_v2_sites_schema_subset();
		$this->assertNotNull( $fields['http_user'] );
		$this->assertSame( array( 'edit' ), $fields['http_user']['context'] );
	}

	public function test_v2_schema_uniqueId_is_edit_context_only(): void {
		$fields = $this->get_v2_sites_schema_subset();
		$this->assertNotNull( $fields['uniqueId'] );
		$this->assertSame( array( 'edit' ), $fields['uniqueId']['context'] );
	}

	/**
	 * The schema-driven response filter inspects the schema and strips
	 * fields whose context list does not include the requested context.
	 * Verify that for a default 'view' request, none of the three
	 * narrowed fields are returned.
	 */
	public function test_filter_strips_credentials_from_view_context_response(): void {
		$controller = new \MainWP_Rest_Sites_Controller();

		// Build a faux item carrying every credential field.
		$item = array(
			'id'        => 1,
			'url'       => 'https://child.example.com',
			'name'      => 'Child',
			'http_user' => 'basic-user',
			'http_pass' => 'basic-pass',
			'uniqueId'  => 'secure-uuid',
		);

		$ref = new \ReflectionMethod( $controller, 'filter_response_data_by_allowed_fields' );
		$ref->setAccessible( true );
		$filtered = $ref->invokeArgs( $controller, array( $item, 'view' ) );

		$this->assertArrayNotHasKey( 'http_pass', $filtered, 'http_pass must not appear in view context' );
		$this->assertArrayNotHasKey( 'http_user', $filtered, 'http_user must not appear in view context' );
		$this->assertArrayNotHasKey( 'uniqueId', $filtered, 'uniqueId must not appear in view context' );
		$this->assertArrayHasKey( 'id', $filtered, 'Non-sensitive fields must survive the filter' );
	}

	public function test_filter_returns_credentials_for_edit_context_response(): void {
		$controller = new \MainWP_Rest_Sites_Controller();

		$item = array(
			'id'        => 1,
			'url'       => 'https://child.example.com',
			'name'      => 'Child',
			'http_user' => 'basic-user',
			'http_pass' => 'basic-pass',
			'uniqueId'  => 'secure-uuid',
		);

		$ref = new \ReflectionMethod( $controller, 'filter_response_data_by_allowed_fields' );
		$ref->setAccessible( true );
		$filtered = $ref->invokeArgs( $controller, array( $item, 'edit' ) );

		$this->assertArrayHasKey( 'http_pass', $filtered, 'http_pass must be readable in edit context' );
		$this->assertArrayHasKey( 'http_user', $filtered );
		$this->assertArrayHasKey( 'uniqueId', $filtered );
	}
}
