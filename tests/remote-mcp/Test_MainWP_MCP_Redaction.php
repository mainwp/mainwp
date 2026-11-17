<?php
/**
 * Remote MCP redaction tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Approval_Page;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Redaction
 */
class Test_MainWP_MCP_Redaction extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
		Approval_Gate_Fixtures::tear_down as gate_tear_down;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	const SITE_NAME = 'Redaction Site';

	/**
	 * Saved globals.
	 *
	 * @var array
	 */
	private $saved = array();

	public function tear_down() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE name = %s", self::SITE_NAME ) );
		foreach ( $ids as $site_id ) {
			\MainWP\Dashboard\MainWP_Encrypt_Data_Lib::remove_key_file( (int) $site_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d", (int) $site_id ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE name = %s", self::SITE_NAME ) );
		if ( $this->saved ) {
			$_SERVER = $this->saved['server'];
			$_COOKIE = $this->saved['cookie'];
		}
		$this->gate_tear_down();
	}

	/**
	 * The approval page body for a row, as its logged-in user.
	 *
	 * @param int $id Approval.
	 * @return string
	 */
	private function page_body( int $id ): string {
		// Keep the originals from the first call so tear_down() never restores a changed state.
		if ( array() === $this->saved ) {
			$this->saved = array(
				'server' => $_SERVER,
				'cookie' => $_COOKIE,
			);
		}
		$_SERVER['HTTPS']            = 'on';
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		wp_set_current_user( 0 );
		$page = new Remote_MCP_Approval_Page( $this->services );
		return $page->handle( new Remote_MCP_Request( 'route_approve', 'GET', 'approval=' . $id, '', $_SERVER ) )->body();
	}

	/**
	 * Props with every kind of credential field.
	 *
	 * @return array
	 */
	private function credential_props(): array {
		return array(
			'site_ids'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'http_pass'      => array( 'type' => 'string' ),
			'admin_password' => array( 'type' => 'string' ),
			'api_key'        => array( 'type' => 'string' ),
			'uniqueid'       => array( 'type' => 'string' ),
			'blob'           => array(
				'type'      => 'string',
				'writeOnly' => true,
			),
			'client_note'    => array( 'type' => 'string' ),
			'label'          => array( 'type' => 'string' ),
			'dry_run'        => array( 'type' => 'boolean' ),
		);
	}

	public function test_credentials_redacted_in_summary_tool_result_and_page() {
		$ctx     = $this->ctx();
		$secrets = array(
			'http_pass'      => 'SECRET-HTTP',
			'admin_password' => 'SECRET-ADMIN',
			'api_key'        => 'SECRET-KEY',
			'uniqueid'       => 'SECRET-UNIQUE',
			'blob'           => 'SECRET-BLOB',
			'client_note'    => 'SECRET-NOTE',
		);
		$args    = $secrets + array(
			'site_ids' => array( 3 ),
			'label'    => 'visible-label',
		);
		$echo    = function ( $input ) {
			return array(
				'saved'  => true,
				'echo'   => $input,
				'nested' => array( 'token' => 'SECRET-RESULT' ),
			);
		};
		foreach ( array( 'confirm', 'dashboard' ) as $tier ) {
			$name = 'mainwp-test/' . $tier . '-credentials-v1';
			$this->ability( $name, $this->credential_props(), array( 'result' => $echo ) );
			$this->entry(
				$name,
				$tier,
				array(
					'source'    => 'audit',
					'sensitive' => array( 'client_note' ),
					'resolver'  => 'sites',
				)
			);
		}

		$confirm = $this->call( $ctx, 'mainwp-test/confirm-credentials-v1', $args );
		$shown   = (string) wp_json_encode( $confirm['result'] );
		$this->assertStringContainsString( 'visible-label', $shown );
		$this->assertStringContainsString( 'supplied', $shown );
		$token = $this->structured( $confirm['result'] )['confirmation_token'];
		$run   = $this->call(
			$ctx,
			'mainwp-test/confirm-credentials-v1',
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertSame( 'run', $run['decision']['decision'] );
		$shown .= (string) wp_json_encode( $run['result'] );

		$dash   = $this->call( $ctx, 'mainwp-test/dashboard-credentials-v1', $args );
		$id     = (int) $dash['decision']['approval_id'];
		$shown .= (string) wp_json_encode( $dash['result'] );
		$shown .= (string) wp_json_encode( $this->gate->page_view( $id, $this->user_id ) );
		$shown .= $this->page_body( $id );
		$shown .= (string) wp_json_encode( $this->approvals( $ctx->grant_id ) );
		$shown .= (string) wp_json_encode( $this->audit );

		foreach ( array_merge( array_values( $secrets ), array( 'SECRET-RESULT' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $shown, $secret );
		}
		$this->assertStringContainsString( 'visible-label', $this->page_body( $id ) );
	}

	public function test_nested_object_write_only_is_plan_less_and_redacted() {
		$this->nested_credential( false );
	}

	public function test_array_item_write_only_is_plan_less_and_redacted() {
		$this->nested_credential( true );
	}

	public function test_write_only_under_schema_alternatives_and_dynamic_values_is_found() {
		$secret = array(
			'type'      => 'string',
			'writeOnly' => true,
		);
		$object = array(
			'type'       => 'object',
			'properties' => array( 'blob' => $secret ),
		);
		$forms  = array(
			'oneOf'                => array( 'oneOf' => array( array( 'type' => 'null' ), $object ) ),
			'anyOf'                => array( 'anyOf' => array( $object ) ),
			'allOf'                => array( 'allOf' => array( $object ) ),
			'additionalProperties' => array(
				'type'                 => 'object',
				'additionalProperties' => $secret,
			),
			'patternProperties'    => array(
				'type'              => 'object',
				'patternProperties' => array( '^k' => $secret ),
			),
			'tuple items'          => array(
				'type'  => 'array',
				'items' => array( array( 'type' => 'string' ), $secret ),
			),
			'prefixItems'          => array(
				'type'        => 'array',
				'prefixItems' => array( $secret ),
			),
			'object subschema'     => json_decode( (string) wp_json_encode( array( 'oneOf' => array( $object ) ) ) ),
		);
		foreach ( $forms as $label => $schema ) {
			$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields( array( 'settings' => $schema ), array() );
			$this->assertTrue( in_array( 'settings', $fields, true ) || in_array( 'blob', $fields, true ), $label );
		}
		$this->nested_credential( false, $forms['oneOf'] );
	}

	/**
	 * Nested schema sensitivity must survive storage and execution boundaries.
	 *
	 * @param bool       $array_item Whether the object is inside an array.
	 * @param array|null $override   A schema to use instead of the plain object or array.
	 */
	private function nested_credential( bool $array_item, ?array $override = null ): void {
		$ctx    = $this->ctx();
		$name   = 'mainwp-test/nested-credentials-v1';
		$object = array(
			'type'       => 'object',
			'properties' => array(
				'blob'  => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
				'label' => array( 'type' => 'string' ),
			),
		);
		$schema = $array_item ? array(
			'type'  => 'array',
			'items' => $object,
		) : $object;
		$schema = $override ?? $schema;
		$value  = array(
			'blob'  => 'NESTED-CREDENTIAL',
			'label' => 'visible',
		);
		$args   = array( 'settings' => $array_item ? array( $value ) : $value );
		$this->ability(
			$name,
			array(
				'settings' => $schema,
				'dry_run'  => array( 'type' => 'boolean' ),
			),
			array(
				'result' => function ( $input ) {
					return array(
						'echo'    => $input,
						'message' => 'Echo NESTED-CREDENTIAL',
					);
				},
			)
		);
		$this->entry( $name, 'dashboard' );
		$first = $this->call( $ctx, $name, $args );
		$id    = (int) $first['decision']['approval_id'];
		$row   = $this->store->get_approval( $id );
		$this->assertNull( $row['call_plan'], 'nested writeOnly inputs must never be stored in a plan' );
		$this->assertStringNotContainsString( 'NESTED-CREDENTIAL', $row['summary'] );
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertNull( $this->store->get_approval( $id )['call_plan'] );
		$run = $this->call( $ctx, $name, $args );
		$this->assertFalse( $run['result']['isError'] );
		$shown = wp_json_encode( array( $first['result'], $run['result'], $this->gate->page_view( $id, $this->user_id ), $this->approvals( $ctx->grant_id ) ) );
		$this->assertStringNotContainsString( 'NESTED-CREDENTIAL', $shown );
		$this->assertStringContainsString( 'visible', $shown );
		$this->assertCount( 2, $this->ran( $name ), 'preview and execution occur only on the approved retry' );
	}

	public function test_embedded_url_userinfo_removed_from_strings_and_errors() {
		foreach ( array( 'Failed https://user:password@host/a then http://other:pass@second/b', new \WP_Error( 'fetch', 'Failed https://user:password@host/a then http://other:pass@second/b' ) ) as $value ) {
			$result = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact( $value );
			$text   = is_wp_error( $result ) ? $result->get_error_message() : $result;
			$this->assertSame( 'Failed https://host/a then http://second/b', $text );
			$result = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact( $value, array(), array( 's' ) );
			$this->assertStringNotContainsString( '@', is_wp_error( $result ) ? $result->get_error_message() : $result, 'short secrets must not break URL detection' );
		}
	}

	public function test_write_only_in_root_schema_alternatives_is_found() {
		$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields(
			array( 'blob' => array( 'type' => 'string' ) ),
			array(),
			array(
				array(
					'properties' => array(
						'blob' => array(
							'type'      => 'string',
							'writeOnly' => true,
						),
					),
				),
			)
		);
		$this->assertContains( 'blob', $fields );
	}

	public function test_schema_too_deep_to_walk_marks_the_argument_as_a_credential() {
		$schema = array(
			'type'      => 'string',
			'writeOnly' => true,
		);
		for ( $i = 0; $i < 40; $i++ ) {
			$schema = array( 'anyOf' => array( $schema ) );
		}
		$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields( array( 'settings' => $schema ), array() );
		$this->assertContains( 'settings', $fields, 'a schema the walk cannot finish is treated as sensitive' );
	}

	public function test_root_alternatives_too_deep_to_walk_mark_every_argument() {
		// The walk gives up at the root, before it reaches the alternative's named properties.
		$alternative = array(
			'properties' => array(
				'blob' => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
			),
		);
		for ( $i = 0; $i < 40; $i++ ) {
			$alternative = array( 'anyOf' => array( $alternative ) );
		}
		$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields(
			array( 'blob' => array( 'type' => 'string' ) ),
			array(),
			array( $alternative )
		);
		$this->assertContains( 'blob', $fields, 'a root walk that cannot finish covers every argument' );
	}

	public function test_root_alternatives_too_many_to_enumerate_are_marked_unwalkable() {
		$alternatives = array_fill( 0, 2500, array( 'type' => 'object' ) );
		$alternatives[] = array(
			'properties' => array(
				'blob' => array(
					'type'      => 'string',
					'writeOnly' => true,
				),
			),
		);
		// Fill the root walk too, so the fallback is what has to decide.
		for ( $i = 0; $i < 40; $i++ ) {
			$alternatives = array( array( 'anyOf' => $alternatives ) );
		}
		$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields( array( 'label' => array( 'type' => 'string' ) ), array(), $alternatives );
		$this->assertContains( \MainWP\Dashboard\Remote_MCP\MCP_Redactor::UNWALKABLE, $fields, 'a schema too large to enumerate is marked, so the call is refused' );
	}

	public function test_oversized_argument_does_not_mark_unrelated_arguments() {
		$huge = array( 'type' => 'string' );
		for ( $i = 0; $i < 12; $i++ ) {
			$huge = array( 'anyOf' => array( $huge, $huge ) );
		}
		$fields = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::credential_fields(
			array(
				'unused' => $huge,
				'target' => array( 'type' => 'string' ),
			),
			array()
		);
		$this->assertContains( 'unused', $fields, 'the oversized argument fails closed' );
		$this->assertNotContains( 'target', $fields, 'an unrelated argument keeps its own analysis' );
	}

	public function test_result_summary_is_cut_on_a_character_boundary() {
		$cut = new \ReflectionMethod( \MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate::class, 'summary_text' );
		$cut->setAccessible( true );
		$text = str_repeat( 'a', 1999 ) . 'é' . str_repeat( 'b', 10 );
		$out  = $cut->invoke( null, $text );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ), 'never splits a multibyte character' );
		$this->assertSame( 'abc', $cut->invoke( null, 'abc' ) );
	}

	public function test_argument_value_is_cut_on_a_character_boundary() {
		$lines = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::argument_lines( 'mainwp-test/x', array( 'note' => str_repeat( 'a', 299 ) . 'é' . str_repeat( 'b', 10 ) ), array() );
		$this->assertTrue( mb_check_encoding( $lines[0]['value'], 'UTF-8' ), 'never splits a multibyte character' );
		$this->assertStringEndsWith( '...', $lines[0]['value'] );
	}

	public function test_boolean_status_named_like_a_credential_is_not_redacted() {
		$result = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact(
			array(
				'recovery_passphrase_set' => true,
				'host_key_pinned'         => false,
				'recovery_passphrase'     => 'hunter2-value',
				'api_key'                 => 12345,
			)
		);
		$this->assertTrue( $result['recovery_passphrase_set'] );
		$this->assertFalse( $result['host_key_pinned'] );
		$this->assertSame( \MainWP\Dashboard\Remote_MCP\MCP_Redactor::REDACTED, $result['recovery_passphrase'] );
		$this->assertSame( \MainWP\Dashboard\Remote_MCP\MCP_Redactor::REDACTED, $result['api_key'] );
		$marked = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact( array( 'enrolled' => true ), array( 'enrolled' ) );
		$this->assertSame( \MainWP\Dashboard\Remote_MCP\MCP_Redactor::REDACTED, $marked['enrolled'], 'a field the schema marks stays redacted' );
	}

	public function test_longer_secret_redacted_before_a_shorter_one_it_contains() {
		$secrets = array(
			'http_user' => 'admin',
			'nested'    => (object) array( 'http_pass' => 'admin2024!' ),
		);
		$result  = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact( 'Login admin with admin2024! failed', array(), $secrets );
		$this->assertStringNotContainsString( '2024!', $result, 'no suffix of the longer secret survives' );
		$this->assertSame( 'Login [redacted] with [redacted] failed', $result );
	}

	public function test_userinfo_with_at_or_slash_in_password_is_fully_removed() {
		$result = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::strip_userinfo( 'Failed https://admin:p@ss/w0rd@host.example/a' );
		foreach ( array( 'admin', 'p@ss', 'w0rd' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $result );
		}
		$this->assertStringContainsString( 'https://host.example/a', $result );
	}

	public function test_short_known_secrets_removed_from_strings_and_errors() {
		foreach ( array( 'x', 'ab', 'abc' ) as $secret ) {
			foreach ( array( 'Echo ' . $secret, new \WP_Error( 'echo', 'Echo ' . $secret ) ) as $value ) {
				$result = \MainWP\Dashboard\Remote_MCP\MCP_Redactor::redact( $value, array(), array( '', $secret ) );
				$this->assertSame( 'Echo [redacted]', is_wp_error( $result ) ? $result->get_error_message() : $result );
			}
		}
	}

	public function test_page_preview_redacts_schema_and_contract_sensitive_fields() {
		list( $ctx, $name, $id ) = $this->page_sensitive_call();
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$shown = $this->store->get_approval( $id )['summary'] . $this->page_body( $id );
		$this->assertStringNotContainsString( 'PAGE-NOTE', $shown );
		$this->assertStringNotContainsString( 'PAGE-BLOB', $shown );
		$this->assertStringContainsString( 'visible-result', $shown );
	}

	public function test_page_execution_redacts_stored_result_and_client_retry() {
		list( $ctx, $name, $id ) = $this->page_sensitive_call();
		$this->assertSame( 'approved', $this->approve( $id )['outcome'] );
		$this->assertSame( 'ran', $this->gate->page_decide( $id, $this->user_id, 'run' )['outcome'] );
		$shown = $this->store->get_approval( $id )['result_summary'] . wp_json_encode( $this->call( $ctx, $name, array() )['result'] );
		$this->assertStringNotContainsString( 'PAGE-NOTE', $shown );
		$this->assertStringNotContainsString( 'PAGE-BLOB', $shown );
		$this->assertStringContainsString( 'visible-result', $shown );
	}

	/**
	 * Results may introduce sensitive fields that were absent from the call.
	 *
	 * @return array Context, ability and approval ID.
	 */
	private function page_sensitive_call(): array {
		$ctx  = $this->ctx();
		$name = 'mainwp-test/page-sensitive-v1';
		$this->ability(
			$name,
			array(
				'client_note' => array( 'type' => 'string' ),
				'settings'    => array(
					'type'       => 'object',
					'properties' => array(
						'blob' => array(
							'type'      => 'string',
							'writeOnly' => true,
						),
					),
				),
				'dry_run'     => array( 'type' => 'boolean' ),
			),
			array(
				'result' => function () {
					return array(
						'client_note' => 'PAGE-NOTE',
						'settings'    => array( 'blob' => 'PAGE-BLOB' ),
						'label'       => 'visible-result',
					);
				},
			)
		);
		$this->entry( $name, 'dashboard', array( 'sensitive' => array( 'client_note' ) ) );
		$id = (int) $this->call( $ctx, $name, array() )['decision']['approval_id'];
		$this->assertNotNull( $this->store->get_approval( $id )['call_plan'], 'no credential was supplied, so the page can run this plan' );
		return array( $ctx, $name, $id );
	}

	public function test_url_with_userinfo_refused() {
		$ctx = $this->ctx();
		$this->ability(
			'mainwp-test/add-site-v1',
			array(
				'url'  => array( 'type' => 'string' ),
				'name' => array( 'type' => 'string' ),
			)
		);
		$this->entry( 'mainwp-test/add-site-v1', 'dashboard' );
		foreach ( array( 'https://user:pass@site.example', 'https://user@site.example/', 'HTTP://u:p@site.example:8443/x' ) as $url ) {
			$decision = $this->gate->check(
				$ctx,
				'mainwp-test/add-site-v1',
				array(
					'url'  => $url,
					'name' => 'n',
				)
			);
			$this->assertSame( 'refused', $decision['decision'], $url );
			$this->assertSame( 'url_userinfo', $decision['reason'] );
		}
		$this->assertStringContainsString( 'http_user', $this->gate->deliver( $ctx, $decision )['content'][0]['text'] );
		$this->assertSame( array(), $this->approvals( $ctx->grant_id ) );
		$this->assertSame( array(), $this->ran( 'mainwp-test/add-site-v1' ) );
		$this->assertSame(
			'approval_required',
			$this->gate->check(
				$ctx,
				'mainwp-test/add-site-v1',
				array(
					'url'  => 'https://site.example',
					'name' => 'n',
				)
			)['decision']
		);
	}

	public function test_stored_site_url_userinfo_stripped_from_summaries() {
		$ctx     = $this->ctx();
		$site_id = (int) \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$this->user_id,
			self::SITE_NAME,
			'https://olduser:OLDPASS@stored.example/',
			'admin',
			base64_encode( 'fake-pub-' . wp_generate_uuid4() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test key material.
			base64_encode( 'fake-priv-' . wp_generate_uuid4() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test key material.
			array(
				'http_user' => '',
				'http_pass' => '',
			)
		);
		$this->assertGreaterThan( 0, $site_id );
		$this->ability(
			'mainwp-test/delete-stored-v1',
			array(
				'site_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			)
		);
		$this->entry( 'mainwp-test/delete-stored-v1', 'dashboard', array( 'resolver' => 'sites' ) );
		$out  = $this->call( $ctx, 'mainwp-test/delete-stored-v1', array( 'site_ids' => array( $site_id ) ) );
		$id   = (int) $out['decision']['approval_id'];
		$text = (string) wp_json_encode( $out['result'] ) . (string) wp_json_encode( $this->store->get_approval( $id ) ) . $this->page_body( $id );
		$this->assertStringContainsString( 'stored.example', $text );
		$this->assertStringContainsString( self::SITE_NAME, $text );
		$this->assertStringNotContainsString( 'OLDPASS', $text );
		$this->assertStringNotContainsString( 'olduser', $text );
	}
}
