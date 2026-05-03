<?php
/**
 * MainWP REST credential strip tests (MWP-1541 + MWP-1542).
 *
 * Two layers exercised here:
 *
 *  - The static helper MainWP\Dashboard\Rest_Api_V1::strip_sensitive_site_fields()
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
 *
 * @group abilities
 * @group security
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
	 * Locks the schema contract that the simple_view response paths depend on.
	 *
	 * The site-response sites in MainWP_Rest_Sites_Controller that call
	 * filter_response_data_by_allowed_fields($website, 'simple_view') directly
	 * (e.g. at :1085, :1159, :1215, :1295 and ~13 others) do not have the
	 * unconditional strip backstop that prepare_site_item_for_response_context()
	 * provides. They rely entirely on http_pass / http_user / uniqueId being
	 * 'edit'-context only. If a future maintainer adds 'view' or 'simple_view'
	 * to one of those schema entries, those endpoints leak credentials with
	 * no runtime defense. This test catches the drift before merge.
	 *
	 * If you intentionally widen one of these contexts, audit every site-response
	 * site in MainWP_Rest_Sites_Controller for credential leak risk first, then
	 * either extend the unconditional strip to the simple_view paths or update
	 * this assertion with a short note explaining the new contract.
	 */
	public function test_v2_schema_credential_fields_locked_to_edit_context_only(): void {
		$fields = $this->get_v2_sites_schema_subset();
		foreach ( array( 'http_pass', 'http_user', 'uniqueId' ) as $field ) {
			$this->assertNotNull( $fields[ $field ], "{$field} must still be declared in the schema" );
			$this->assertSame(
				array( 'edit' ),
				$fields[ $field ]['context'],
				"{$field} schema context drift would un-protect simple_view endpoints. " .
				'See test docblock for the audit checklist before changing this.'
			);
		}
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

	// ---------------------------------------------------------------------
	// Defense-in-depth: strip_never_in_response_fields (private helper)
	// ---------------------------------------------------------------------

	/**
	 * The schema filter alone is bypassable via _fields and ?context=edit.
	 * The controller's strip_never_in_response_fields helper runs on every
	 * response regardless of context, so a read-only caller cannot recover
	 * credentials via either bypass. Verified directly against the helper.
	 */
	public function test_v2_response_strip_removes_credentials_from_array(): void {
		$controller = new \MainWP_Rest_Sites_Controller();

		$prepared = array(
			'id'        => 1,
			'url'       => 'https://child.example.com',
			'privkey'   => 'PRIV',
			'pubkey'    => 'PUB',
			'http_user' => 'basic-user',
			'http_pass' => 'basic-pass',
			'adminname' => 'admin',
			'securekey' => 'secure',
			'uniqueId'  => 'uuid',
		);

		$ref = new \ReflectionMethod( $controller, 'strip_never_in_response_fields' );
		$ref->setAccessible( true );
		$stripped = $ref->invokeArgs( $controller, array( $prepared ) );

		$this->assertArrayHasKey( 'id', $stripped );
		$this->assertArrayHasKey( 'url', $stripped );
		foreach ( array( 'privkey', 'pubkey', 'http_user', 'http_pass', 'adminname', 'securekey', 'uniqueId' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $stripped, "{$field} must be stripped unconditionally" );
		}
	}

	public function test_v2_response_strip_removes_credentials_from_object(): void {
		$controller = new \MainWP_Rest_Sites_Controller();

		$prepared             = new \stdClass();
		$prepared->id         = 1;
		$prepared->url        = 'https://child.example.com';
		$prepared->privkey    = 'PRIV';
		$prepared->http_user  = 'basic-user';
		$prepared->http_pass  = 'basic-pass';
		$prepared->adminname  = 'admin';
		$prepared->uniqueId   = 'uuid';

		$ref = new \ReflectionMethod( $controller, 'strip_never_in_response_fields' );
		$ref->setAccessible( true );
		$stripped = $ref->invokeArgs( $controller, array( $prepared ) );

		$this->assertSame( 1, $stripped->id );
		$this->assertSame( 'https://child.example.com', $stripped->url );
		foreach ( array( 'privkey', 'http_user', 'http_pass', 'adminname', 'uniqueId' ) as $field ) {
			$this->assertFalse( property_exists( $stripped, $field ), "{$field} must be stripped unconditionally" );
		}
	}
}
