<?php
/**
 * Tests for knowledge AJAX responses and access checks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Knowledge;
use MainWP\Dashboard\MainWP_Knowledge_UI;
use MainWP\Dashboard\MainWP_Client_Overview;
use MainWP\Dashboard\MainWP_Client_Overview_Knowledge;
use MainWP\Dashboard\MainWP_Client_List_Table;

class Test_Abilities_Knowledge_Ajax extends MainWP_Abilities_Test_Case {

	private $added_filters = array();
	private $db;
	private $client_id;
	private $site_id;
	private $suppressed_errors = null;
	private $previous_cookies = array();
	private $client_table;

	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		$this->previous_cookies = $_COOKIE;
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->db        = MainWP_DB_Knowledge::instance();
		$this->client_id = $this->create_test_client();
		$this->site_id   = $this->create_test_site( array( 'client_id' => $this->client_id ) );
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		if ( null !== $this->suppressed_errors ) {
			$wpdb->suppress_errors( $this->suppressed_errors );
			$this->suppressed_errors = null;
		}
		remove_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) );
		if ( $this->client_table ) {
			remove_action( 'mainwp_manageclients_tabletop', array( $this->client_table, 'generate_tabletop' ) );
			$this->client_table = null;
		}
		$_COOKIE = $this->previous_cookies;
		parent::tearDown();
	}

	private function track_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * Point SELECTs on one table at a table that does not exist, so they fail the way a lock timeout or lost connection does.
	 *
	 * @param string $table             Table name without the WordPress prefix, e.g. mainwp_knowledge.
	 * @param bool   $client_scope_only Only fail the SELECTs that read client-scope rows.
	 */
	private function fail_selects_on( string $table, bool $client_scope_only = false ): void {
		global $wpdb;
		if ( null === $this->suppressed_errors ) {
			$this->suppressed_errors = $wpdb->suppress_errors( true );
		}
		$name = $wpdb->prefix . $table;
		// Boundaries keep mainwp_wp from matching mainwp_wp_clients; the query may quote the name with backticks or not.
		$pattern = '/(?<![A-Za-z0-9_])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_])/';
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $pattern, $name, $client_scope_only ) {
				if ( 0 !== stripos( ltrim( $sql ), 'SELECT' ) || ( $client_scope_only && false === strpos( $sql, "scope_type = 'client'" ) ) ) {
					return $sql;
				}
				return preg_replace( $pattern, $name . '_missing', $sql );
			}
		);
	}

	private function call( string $action, array $post ): array {
		$_POST['security'] = $_REQUEST['security'] = wp_create_nonce( $action );
		foreach ( $post as $key => $value ) {
			$_POST[ $key ] = is_string( $value ) ? wp_slash( $value ) : $value;
		}
		// A supplied security value exercises a nonce created for the wrong action.
		$_REQUEST['security'] = $_POST['security'];

		$this->track_filter( 'wp_doing_ajax', '__return_true' );
		$this->track_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		ob_start();
		try {
			$method = 'ajax_' . substr( $action, strlen( 'mainwp_knowledge_' ) );
			MainWP_Knowledge_UI::instance()->$method();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json ends in wp_die; the body is what matters.
			// Expected: every handler exit goes through wp_die.
		} finally {
			$body = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			foreach ( array_merge( array( 'security' ), array_keys( $post ) ) as $key ) {
				unset( $_POST[ $key ] );
			}
			unset( $_REQUEST['security'] );
		}

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'Expected a JSON response, got: ' . $body );
		return $decoded;
	}

	private function input( array $overrides = array() ): array {
		$input = array_merge(
			array(
				'scope_type' => 'client',
				'scope_id'   => $this->client_id,
				'type'       => 'context',
				'title'      => "Client's knowledge",
				'body'       => "Keep client's C:\\notes verbatim.\nSecond line.",
			),
			$overrides
		);
		if ( 'agency' === $input['scope_type'] && ! array_key_exists( 'scope_id', $overrides ) ) {
			unset( $input['scope_id'] );
		}
		return $input;
	}

	private function create_record( array $overrides = array() ): object {
		if ( 'skill' === ( $overrides['type'] ?? '' ) ) {
			$overrides += array( 'description' => 'Use this skill when maintaining the site.' );
		}
		$record = $this->db->save( $this->input( $overrides ), MainWP_DB_Knowledge::ORIGIN_UI );
		$this->assertNotWPError( $record );
		return $record;
	}

	private function row_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_knowledge" );
	}

	private function assert_error( array $response, string $code, int $status ): void {
		$this->assertFalse( $response['success'] );
		$this->assertSame( $code, $response['data']['code'] );
		$this->assertSame( $status, $response['data']['status'] );
	}

	private function restrict_to_other_site(): void {
		$site_id = $this->create_test_site();
		$this->track_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
	}

	private function withhold_right( string $right ): void {
		$this->track_filter(
			'mainwp_currentusercan',
			static function ( $allowed, $cap_type, $cap ) use ( $right ) {
				return 'dashboard' === $cap_type && $right === $cap ? false : $allowed;
			},
			10,
			3
		);
	}

	public function test_list_client_summaries(): void {
		$context  = $this->create_record();
		$memory   = $this->create_record( array( 'type' => 'memory' ) );
		$response = $this->call( 'mainwp_knowledge_list', $this->input() );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['can_write'] );
		$this->assertCount( 2, $response['data']['records'] );
		$this->assertEqualsCanonicalizing( array( $context->id, $memory->id ), array_column( $response['data']['records'], 'id' ) );
		$this->assertEqualsCanonicalizing( array( 'context', 'memory' ), array_column( $response['data']['records'], 'type' ) );
		foreach ( $response['data']['records'] as $record ) {
			foreach ( array( 'id', 'type', 'type_label', 'title', 'body_preview', 'revision', 'updated_by_name', 'updated_ago' ) as $key ) {
				$this->assertArrayHasKey( $key, $record );
			}
			$this->assertSame( "Keep client's C:\\notes verbatim.\nSecond line.", $record['body_preview'] );
			$this->assertArrayNotHasKey( 'body', $record );
		}
	}

	public function test_partial_client_manager_sees_client_records_read_only(): void {
		$record = $this->create_record();
		$this->create_test_site( array( 'client_id' => $this->client_id ) );
		$this->restrict_to_own_site();
		$profile = $this->call( 'mainwp_knowledge_list', $this->input() );
		$this->assertTrue( $profile['success'] );
		$this->assertFalse( $profile['data']['can_write'] );
		$this->assertSame( array( $record->id ), array_column( $profile['data']['records'], 'id' ) );
		$site = $this->call( 'mainwp_knowledge_list', $this->site_input() );
		$this->assertTrue( $site['success'] );
		$this->assertTrue( $site['data']['can_write'] );
		$this->assertSame( array( $record->id ), array_column( $site['data']['inherited']['records'], 'id' ) );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assertTrue( $get['success'] );
		foreach ( array( $this->input(), $this->input( array( 'id' => $record->id, 'revision' => 1 ) ) ) as $input ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_save', $input ), 'mainwp_access_denied', 403 );
		}
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) ), 'mainwp_access_denied', 403 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_partial_client_manager_inherited_profile_link_offers_view(): void {
		$hidden = $this->create_test_site( array( 'client_id' => $this->client_id ) );
		$this->restrict_to_own_site();
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'site', $this->site_id );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'class="mainwp-knowledge-inherited-link">View on the client profile</a>', $html );
		$this->assertStringNotContainsString( 'class="mainwp-knowledge-inherited-link">Edit on the client profile</a>', $html );

		$site_id = $this->site_id;
		$this->track_filter( 'mainwp_currentuserallowedaccesssites', static function () use ( $site_id, $hidden ) { return array( $site_id, $hidden ); }, 11 );
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'site', $this->site_id );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'class="mainwp-knowledge-inherited-link">Edit on the client profile</a>', $html );
		$this->assertTrue( $this->call( 'mainwp_knowledge_list', $this->input() )['data']['can_write'] );
	}

	public function test_list_limits_the_body_preview_to_one_hundred_characters(): void {
		$body     = str_repeat( 'Ž', MainWP_DB_Knowledge::BODY_PREVIEW_LENGTH + 1 );
		$record   = $this->create_record( array( 'body' => $body ) );
		$response = $this->call( 'mainwp_knowledge_list', $this->input() );
		$matches  = array_values( array_filter( $response['data']['records'], static fn( $item ) => $record->id === $item['id'] ) );
		$summary  = $matches[0];

		$this->assertSame( str_repeat( 'Ž', MainWP_DB_Knowledge::BODY_PREVIEW_LENGTH ) . '…', $summary['body_preview'] );
		$this->assertArrayNotHasKey( 'body', $summary );
	}

	public function test_get_record_body(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $record->id, $response['data']['record']['id'] );
		$this->assertSame( $record->body, $response['data']['record']['body'] );
	}

	public function test_description_ajax_save_round_trip(): void {
		$description = "Use <staging> & client's C:\\notes when upgrading WooCommerce.";
		$input = $this->input( array( 'type' => 'skill', 'description' => $description ) );
		$response = $this->call( 'mainwp_knowledge_save', $input );
		$this->assertTrue( $response['success'] );
		$record = $response['data']['record'];
		$this->assertSame( $description, $record['description'] );
		$this->assertSame( 1, $record['verified'] );
		$this->assertSame( $description, $this->db->get_record( $record['id'] )->description );
		$view = $this->call( 'mainwp_knowledge_get', array( 'id' => $record['id'] ) );
		$this->assertSame( $description, $view['data']['record']['description'] );
		$this->assertSame( $input['body'], $view['data']['record']['body'] );
		$list = $this->call( 'mainwp_knowledge_list', $input );
		$this->assertSame( $description, $list['data']['records'][0]['description'] );
		$this->assertSame( $description, $list['data']['records'][0]['body_preview'] );
		$this->assertArrayNotHasKey( 'body', $list['data']['records'][0] );
		$updated = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record['id'], 'revision' => 1, 'type' => 'skill', 'description' => 'Use the revised procedure.' ) ) );
		$this->assertTrue( $updated['success'] );
		$this->assertSame( 'Use the revised procedure.', $updated['data']['record']['description'] );
		$this->assertSame( 2, $updated['data']['record']['revision'] );
		$this->assertSame( 'Use the revised procedure.', $this->db->get_record( $record['id'] )->description );
	}

	public function test_description_ajax_rejects_invalid_values_without_writing(): void {
		foreach ( array( array( 'type' => 'skill' ), array( 'description' => 'Hidden text' ), array( 'type' => 'skill', 'description' => str_repeat( 'é', 1025 ) ) ) as $fields ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_save', $this->input( $fields ) ), 'mainwp_knowledge_invalid', 400 );
			$this->assertSame( 0, $this->row_count() );
		}
	}

	public function test_description_modal_exposes_the_field_and_help(): void {
		$html = $this->render_site_knowledge();
		$this->assertStringContainsString( 'for="mainwp-knowledge-description">Description</label>', $html );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*id="mainwp-knowledge-description"[^>]*maxlength="1024"/', $html );
		$this->assertStringContainsString( 'When to use this skill, for example: updating WooCommerce on this site.', $html );
		$this->assertStringContainsString( 'AI agents read this to decide whether to open the skill. Say when it applies.', $html );
	}

	public function test_browser_session_save_refuses_api_key_without_cookie_and_accepts_cookie(): void {
		$auth = \MainWP_REST_Authentication::get_instance();
		$user = new \ReflectionProperty( \MainWP_REST_Authentication::class, 'user' );
		$user->setAccessible( true );
		$previous_user = $user->getValue( $auth );
		$record = $this->db->save( $this->input(), MainWP_DB_Knowledge::ORIGIN_ABILITY );
		$this->assertNotWPError( $record );
		$user->setValue( $auth, (object) array( 'user_id' => get_current_user_id() ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		try {
			$this->assertTrue( \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission() );
			foreach ( array( $this->input(), $this->input( array( 'id' => $record->id, 'revision' => 1 ) ) ) as $input ) {
				$response = $this->call( 'mainwp_knowledge_save', $input );
				$this->assert_error( $response, 'mainwp_knowledge_browser_session_required', 403 );
				$this->assertSame( 'Your sign-in could not be confirmed, so the record was not saved. Sign in again and retry.', $response['data']['message'] );
				$this->assertSame( 1, $this->row_count() );
				$this->assertEquals( $record, $this->db->get_record( $record->id ) );
			}
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in' );
			$this->assertSame( get_current_user_id(), wp_validate_auth_cookie( '', 'logged_in' ) );
			$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'revision' => 1 ) ) );
			$this->assertTrue( $response['success'] );
			$this->assertSame( 1, $response['data']['record']['verified'] );
			$this->assertSame( 1, $this->db->get_record( $record->id )->verified );
		} finally {
			$user->setValue( $auth, $previous_user );
		}
	}

	public function test_browser_session_save_refuses_a_cookie_for_another_user(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $other, time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->assertSame( $other, wp_validate_auth_cookie( '', 'logged_in' ) );
		$response = $this->call( 'mainwp_knowledge_save', $this->input() );
		$this->assert_error( $response, 'mainwp_knowledge_browser_session_required', 403 );
		$this->assertSame( 0, $this->row_count() );
	}

	public function test_save_creates_ui_record(): void {
		$before   = $this->row_count();
		$input    = $this->input();
		$response = $this->call( 'mainwp_knowledge_save', $input );

		$this->assertTrue( $response['success'] );
		$record = $response['data']['record'];
		$this->assertSame( 1, $record['revision'] );
		$this->assertSame( 'ui', $record['source'] );
		$this->assertSame( 1, $record['verified'] );
		$stored = $this->db->get_by_id( $record['id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( $input['title'], $stored->title );
		$this->assertSame( $input['body'], $stored->body );
		$this->assertSame( $before + 1, $this->row_count() );
	}

	public function test_ajax_save_verifies_record_created_by_ability(): void {
		$created = $this->execute_ability( 'mainwp/create-knowledge-record-v1', $this->input() + array( 'confirm' => true ) );
		$this->assertNotWPError( $created );
		$this->assertFalse( $created['verified'] );
		$this->assertSame( 'ability', $created['source'] );
		$stored = $this->db->get_by_id( $created['id'] );
		$this->assertNotWPError( $stored );
		$this->assertNotNull( $stored );
		$this->assertSame( 0, $stored->verified );
		$this->assertSame( 'ability', $stored->source );

		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $created['id'], 'revision' => $created['revision'], 'title' => 'Reviewed through UI' ) ) );
		$this->assertTrue( $response['success'] );
		$reviewed = $response['data']['record'];
		$this->assertSame( $created['id'], $reviewed['id'] );
		$this->assertSame( 2, $reviewed['revision'] );
		$this->assertSame( 1, $reviewed['verified'] );
		$this->assertSame( 'ui', $reviewed['source'] );
		$stored = $this->db->get_by_id( $created['id'] );
		$this->assertNotWPError( $stored );
		$this->assertNotNull( $stored );
		$this->assertSame( 1, $stored->verified );
		$this->assertSame( 'ui', $stored->source );
		$this->assertSame( 2, $stored->revision );
		$this->assertSame( 'Reviewed through UI', $stored->title );
	}

	public function test_save_updates_matching_revision(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'revision' => 1, 'title' => 'Edited' ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['record']['revision'] );
		$this->assertSame( 'Edited', $response['data']['record']['title'] );
		$this->assertSame( 2, $this->db->get_by_id( $record->id )->revision );
		$this->assertSame( 'Edited', $this->db->get_by_id( $record->id )->title );
	}

	public function test_save_rejects_stale_revision(): void {
		$record = $this->create_record();
		$stored = $this->db->save( $this->input( array( 'id' => $record->id, 'title' => 'Other writer' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $stored );
		$this->assertSame( 2, $stored->revision );
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'revision' => 1, 'title' => 'Stale writer' ) ) );

		$this->assert_error( $response, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( $stored->revision, $response['data']['revision'] );
		$this->assertEquals( $stored, $this->db->get_by_id( $record->id ) );
	}

	public function test_save_rejects_missing_revision(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'title' => 'No revision' ) ) );

		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_save_rejects_invalid_type(): void {
		$before   = $this->row_count();
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'type' => 'note' ) ) );

		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( $before, $this->row_count() );
	}

	public function test_delete_removes_record(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => $record->revision ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $record->id, $response['data']['id'] );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
	}

	public function test_restricted_delete_with_wrong_revision_matches_missing_id(): void {
		$records = array( $this->create_record(), $this->create_record( $this->site_input() ) );
		$this->restrict_to_other_site();
		foreach ( $records as $record ) {
			$existing = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => $record->revision + 1 ) );
			$missing = $this->call( 'mainwp_knowledge_delete', array( 'id' => 999999, 'revision' => $record->revision + 1 ) );
			$this->assert_error( $existing, 'mainwp_knowledge_not_found', 404 );
			$this->assert_error( $missing, 'mainwp_knowledge_not_found', 404 );
			$this->assertSame( $missing['data']['code'], $existing['data']['code'] );
			$this->assertSame( $missing['data']['status'], $existing['data']['status'] );
			$this->assertArrayNotHasKey( 'revision', $existing['data'] );
			$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		}
	}

	public function test_delete_rejects_stale_revision(): void {
		$record = $this->create_record();
		$stored = $this->db->save( $this->input( array( 'id' => $record->id, 'title' => 'Other writer' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $stored );
		$response = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) );

		$this->assert_error( $response, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( $stored->revision, $response['data']['revision'] );
		$this->assertEquals( $stored, $this->db->get_by_id( $record->id ) );
	}

	public function test_delete_rejects_missing_revision(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id ) );

		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_list_bad_nonce_and_save_write_prevention(): void {
		$this->create_record();
		$input    = $this->input( array( 'security' => wp_create_nonce( 'wrong_action' ) ) );
		$response = $this->call( 'mainwp_knowledge_list', $input );

		$this->assert_error( $response, 'mainwp_knowledge_insecure', 403 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
		$before = $this->row_count();
		$this->assert_error( $this->call( 'mainwp_knowledge_save', $input ), 'mainwp_knowledge_insecure', 403 );
		$this->assertSame( $before, $this->row_count() );
	}

	public function test_list_restricted_client(): void {
		$this->create_record();
		$this->restrict_to_other_site();
		$response = $this->call( 'mainwp_knowledge_list', $this->input() );

		$this->assert_error( $response, 'mainwp_client_not_found', 404 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
		$this->assertArrayNotHasKey( 'can_write', $response['data'] );
	}

	public function test_get_restricted_record_matches_missing_id(): void {
		$record = $this->create_record();
		$this->restrict_to_other_site();
		$hidden  = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$missing = $this->call( 'mainwp_knowledge_get', array( 'id' => PHP_INT_MAX ) );

		$this->assert_error( $hidden, 'mainwp_knowledge_not_found', 404 );
		$this->assert_error( $missing, $hidden['data']['code'], $hidden['data']['status'] );
		$this->assertArrayNotHasKey( 'record', $hidden['data'] );
		$this->assertArrayNotHasKey( 'body', $hidden['data'] );
	}

	public function test_save_restricted_client_and_delete_preserves_record(): void {
		$record = $this->create_record();
		$before = $this->row_count();
		$this->restrict_to_other_site();

		$this->assert_error( $this->call( 'mainwp_knowledge_save', $this->input() ), 'mainwp_client_not_found', 404 );
		$this->assertSame( $before, $this->row_count() );
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => $record->revision ) ), 'mainwp_knowledge_not_found', 404 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_list_read_only_site(): void {
		$input  = array( 'scope_type' => 'site', 'scope_id' => $this->create_test_site() );
		$record = $this->create_record( $input );
		$this->withhold_right( 'edit_sites' );
		$response = $this->call( 'mainwp_knowledge_list', $input );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['can_write'] );
		$this->assertSame( array( $record->id ), array_column( $response['data']['records'], 'id' ) );
	}

	public function test_save_read_only_site_and_delete_preserves_record(): void {
		$input  = $this->input( array( 'scope_type' => 'site', 'scope_id' => $this->create_test_site() ) );
		$record = $this->create_record( $input );
		$before = $this->row_count();
		$this->withhold_right( 'edit_sites' );

		$this->assert_error( $this->call( 'mainwp_knowledge_save', $input ), 'mainwp_access_denied', 403 );
		$this->assertSame( $before, $this->row_count() );
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => $record->revision ) ), 'mainwp_access_denied', 403 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_list_client_without_manage_clients(): void {
		$this->create_record();
		$this->withhold_right( 'manage_clients' );
		$response = $this->call( 'mainwp_knowledge_list', $this->input() );

		$this->assert_error( $response, 'mainwp_access_denied', 403 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
	}

	public function test_list_subscriber_and_get_save_delete_denied(): void {
		$record = $this->create_record();
		$before = $this->row_count();
		$this->set_current_user_as_subscriber();

		$list = $this->call( 'mainwp_knowledge_list', $this->input() );
		$this->assert_error( $list, 'mainwp_permission_denied', 403 );
		$this->assertArrayNotHasKey( 'records', $list['data'] );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assert_error( $get, 'mainwp_permission_denied', 403 );
		$this->assertArrayNotHasKey( 'record', $get['data'] );
		$this->assert_error( $this->call( 'mainwp_knowledge_get', array( 'id' => PHP_INT_MAX ) ), $get['data']['code'], $get['data']['status'] );
		$this->assert_error( $this->call( 'mainwp_knowledge_save', $this->input() ), 'mainwp_permission_denied', 403 );
		$this->assertSame( $before, $this->row_count() );
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id ) ), 'mainwp_permission_denied', 403 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => PHP_INT_MAX ) ), 'mainwp_permission_denied', 403 );
	}

	public function test_save_preserves_plain_text_title(): void {
		$title    = 'Keep PHP < 8 & <staging>';
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'title' => $title ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $title, $response['data']['record']['title'] );
		$this->assertSame( $title, $this->db->get_by_id( $response['data']['record']['id'] )->title );
	}

	public function test_malformed_ids_do_not_touch_record(): void {
		$record = $this->create_record();
		$before = $this->db->get_by_id( $record->id );
		foreach ( array( 'get', 'delete', 'save' ) as $action ) {
			foreach ( array( '-1', '1.5', array( 'x' ) ) as $id ) {
				$response = $this->call( 'mainwp_knowledge_' . $action, $this->input( array( 'id' => $id, 'revision' => $record->revision, 'title' => 'Changed' ) ) );
				$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
				$this->assertEquals( $before, $this->db->get_by_id( $record->id ) );
			}
		}
	}

	public function test_save_rejects_negative_revision(): void {
		$record   = $this->create_record();
		$response = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'revision' => '-1' ) ) );

		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_list_rejects_negative_scope_id(): void {
		$response = $this->call( 'mainwp_knowledge_list', $this->input( array( 'scope_id' => '-1' ) ) );
		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
	}

	public function test_subscriber_update_matches_missing_id(): void {
		$record = $this->create_record();
		$this->set_current_user_as_subscriber();
		$existing = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $record->id, 'revision' => $record->revision, 'title' => 'Changed' ) ) );
		$missing  = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => PHP_INT_MAX, 'revision' => $record->revision, 'title' => 'Changed' ) ) );

		$this->assert_error( $existing, 'mainwp_permission_denied', 403 );
		$this->assert_error( $missing, $existing['data']['code'], $existing['data']['status'] );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_restricted_update_and_delete_match_missing_id(): void {
		$record = $this->create_record();
		$this->restrict_to_other_site();
		foreach ( array( 'save', 'delete' ) as $action ) {
			$existing = $this->call( 'mainwp_knowledge_' . $action, $this->input( array( 'id' => $record->id, 'revision' => $record->revision, 'title' => 'Changed' ) ) );
			$missing  = $this->call( 'mainwp_knowledge_' . $action, $this->input( array( 'id' => PHP_INT_MAX, 'revision' => $record->revision, 'title' => 'Changed' ) ) );

			$this->assert_error( $existing, 'mainwp_knowledge_not_found', 404 );
			$this->assert_error( $missing, $existing['data']['code'], $existing['data']['status'] );
			$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		}
	}

	public function test_get_site_requires_dashboard_access(): void {
		$record = $this->create_record( array( 'scope_type' => 'site', 'scope_id' => $this->create_test_site() ) );
		$this->withhold_right( 'access_individual_dashboard' );
		$response = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );

		$this->assert_error( $response, 'mainwp_access_denied', 403 );
		$this->assertArrayNotHasKey( 'record', $response['data'] );
	}

	public function test_get_site_without_edit_right_succeeds(): void {
		$record = $this->create_record( array( 'scope_type' => 'site', 'scope_id' => $this->create_test_site() ) );
		$this->withhold_right( 'edit_sites' );
		$response = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $record->id, $response['data']['record']['id'] );
		$this->assertSame( $record->body, $response['data']['record']['body'] );
	}

	private function site_input( array $overrides = array() ): array {
		return $this->input( array_merge( array( 'scope_type' => 'site', 'scope_id' => $this->site_id, 'title' => 'Site knowledge' ), $overrides ) );
	}

	private function restrict_to_own_site(): void {
		$site_id = $this->site_id;
		$this->track_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
	}

	public function test_list_site_returns_inherited_client_records(): void {
		$own    = $this->create_record( $this->site_input() );
		$client = $this->create_record();
		$skill  = $this->create_record( array( 'type' => 'skill' ) );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['can_write'] );
		$this->assertSame( array( $own->id ), array_column( $response['data']['records'], 'id' ) );
		$inherited = $response['data']['inherited'];
		$this->assertSame( $this->client_id, $inherited['client_id'] );
		$this->assertStringStartsWith( 'Test Client ', $inherited['client_name'] );
		$this->assertStringContainsString( 'page=ClientAddNew&client_id=' . $this->client_id, $inherited['client_url'] );
		$this->assertEqualsCanonicalizing( array( $client->id, $skill->id ), array_column( $inherited['records'], 'id' ) );
		$this->assertSame( array( 'client' ), array_values( array_unique( array_column( $inherited['records'], 'scope_type' ) ) ) );
		foreach ( $inherited['records'] as $record ) {
			$this->assertArrayNotHasKey( 'body', $record );
		}
		// The write flag describes the site only; the inherited block carries none.
		$this->assertArrayNotHasKey( 'can_write', $inherited );
	}

	public function test_list_site_with_client_without_records_returns_empty_inherited(): void {
		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $this->client_id, $response['data']['inherited']['client_id'] );
		$this->assertSame( array(), $response['data']['inherited']['records'] );
	}

	public function test_list_site_without_client_has_no_inherited(): void {
		$this->create_record();
		$input  = $this->site_input( array( 'scope_id' => $this->create_test_site() ) );
		$record = $this->create_record( $input );

		$response = $this->call( 'mainwp_knowledge_list', $input );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( $record->id ), array_column( $response['data']['records'], 'id' ) );
		$this->assertArrayHasKey( 'inherited', $response['data'] );
		$this->assertNull( $response['data']['inherited'] );
	}

	public function test_list_site_with_deleted_client_has_no_inherited(): void {
		$site_id = $this->create_test_site( array( 'client_id' => PHP_INT_MAX >> 33 ) );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input( array( 'scope_id' => $site_id ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertNull( $response['data']['inherited'] );
	}

	public function test_list_site_without_manage_clients_hides_inherited(): void {
		$own = $this->create_record( $this->site_input() );
		$this->create_record();
		$this->withhold_right( 'manage_clients' );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['can_write'] );
		$this->assertSame( array( $own->id ), array_column( $response['data']['records'], 'id' ) );
		$this->assertNull( $response['data']['inherited'] );
	}

	public function test_list_client_scope_has_no_inherited(): void {
		$this->create_record();

		$response = $this->call( 'mainwp_knowledge_list', $this->input() );

		$this->assertTrue( $response['success'] );
		$this->assertNull( $response['data']['inherited'] );
	}

	public function test_list_site_read_only_user_sees_inherited_without_write(): void {
		$own    = $this->create_record( $this->site_input() );
		$client = $this->create_record();
		$this->withhold_right( 'edit_sites' );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['can_write'] );
		$this->assertSame( array( $own->id ), array_column( $response['data']['records'], 'id' ) );
		$this->assertSame( array( $client->id ), array_column( $response['data']['inherited']['records'], 'id' ) );
	}

	public function test_list_site_restricted_user_in_scope(): void {
		$own    = $this->create_record( $this->site_input() );
		$client = $this->create_record();
		$this->restrict_to_own_site();

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( $own->id ), array_column( $response['data']['records'], 'id' ) );
		$this->assertSame( array( $client->id ), array_column( $response['data']['inherited']['records'], 'id' ) );
	}

	public function test_list_site_restricted_user_out_of_scope(): void {
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->restrict_to_other_site();

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assert_error( $response, 'mainwp_site_not_found', 404 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
		$this->assertArrayNotHasKey( 'inherited', $response['data'] );
		$missing = $this->call( 'mainwp_knowledge_list', $this->site_input( array( 'scope_id' => PHP_INT_MAX >> 33 ) ) );
		$this->assert_error( $missing, $response['data']['code'], $response['data']['status'] );
	}

	public function test_list_site_without_dashboard_access(): void {
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->withhold_right( 'access_individual_dashboard' );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assert_error( $response, 'mainwp_access_denied', 403 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
		$this->assertArrayNotHasKey( 'inherited', $response['data'] );
	}

	public function test_save_site_record_as_admin_and_restricted_user(): void {
		$created = $this->call( 'mainwp_knowledge_save', $this->site_input() );
		$this->assertTrue( $created['success'] );
		$this->assertSame( 'site', $created['data']['record']['scope_type'] );
		$this->assertSame( $this->site_id, $created['data']['record']['scope_id'] );

		$this->restrict_to_own_site();
		$updated = $this->call( 'mainwp_knowledge_save', $this->site_input( array( 'id' => $created['data']['record']['id'], 'revision' => 1, 'title' => 'Edited' ) ) );
		$this->assertTrue( $updated['success'] );
		$this->assertSame( 2, $updated['data']['record']['revision'] );
	}

	public function test_save_and_delete_site_record_out_of_scope(): void {
		$record = $this->create_record( $this->site_input() );
		$before = $this->row_count();
		$this->restrict_to_other_site();

		$this->assert_error( $this->call( 'mainwp_knowledge_save', $this->site_input() ), 'mainwp_site_not_found', 404 );
		$this->assertSame( $before, $this->row_count() );
		$this->assert_error( $this->call( 'mainwp_knowledge_save', $this->site_input( array( 'id' => $record->id, 'revision' => $record->revision, 'title' => 'Changed' ) ) ), 'mainwp_knowledge_not_found', 404 );
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => $record->revision ) ), 'mainwp_knowledge_not_found', 404 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_save_and_delete_site_record_stale_revision(): void {
		$record = $this->create_record( $this->site_input() );
		$stored = $this->db->save( $this->site_input( array( 'id' => $record->id, 'title' => 'Other writer' ) ), MainWP_DB_Knowledge::ORIGIN_UI, 1 );
		$this->assertNotWPError( $stored );

		$save = $this->call( 'mainwp_knowledge_save', $this->site_input( array( 'id' => $record->id, 'revision' => 1, 'title' => 'Stale writer' ) ) );
		$this->assert_error( $save, 'mainwp_knowledge_conflict', 409 );
		$this->assertSame( $stored->revision, $save['data']['revision'] );
		$delete = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) );
		$this->assert_error( $delete, 'mainwp_knowledge_conflict', 409 );
		$this->assertEquals( $stored, $this->db->get_by_id( $record->id ) );
	}

	public function test_site_scope_cannot_edit_inherited_record(): void {
		$client = $this->create_record();

		// The panel posts its own scope with every request, so an inherited id arrives with the site scope.
		$response = $this->call( 'mainwp_knowledge_save', $this->site_input( array( 'id' => $client->id, 'revision' => $client->revision, 'title' => 'Changed from the site' ) ) );

		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertEquals( $client, $this->db->get_by_id( $client->id ) );
	}

	public function test_site_editor_without_manage_clients_cannot_write_inherited_record(): void {
		$client = $this->create_record();
		$this->withhold_right( 'manage_clients' );

		$site_scope   = $this->call( 'mainwp_knowledge_save', $this->site_input( array( 'id' => $client->id, 'revision' => $client->revision, 'title' => 'Changed' ) ) );
		$client_scope = $this->call( 'mainwp_knowledge_save', $this->input( array( 'id' => $client->id, 'revision' => $client->revision, 'title' => 'Changed' ) ) );
		$delete       = $this->call( 'mainwp_knowledge_delete', array( 'id' => $client->id, 'revision' => $client->revision ) );
		$get          = $this->call( 'mainwp_knowledge_get', array( 'id' => $client->id ) );

		foreach ( array( $site_scope, $client_scope, $delete, $get ) as $response ) {
			$this->assert_error( $response, 'mainwp_access_denied', 403 );
			$this->assertArrayNotHasKey( 'record', $response['data'] );
		}
		$this->assertEquals( $client, $this->db->get_by_id( $client->id ) );
	}

	public function test_list_nonce_cannot_get_or_delete_record(): void {
		$record = $this->create_record();
		$input  = array( 'id' => $record->id, 'security' => wp_create_nonce( 'mainwp_knowledge_list' ) );
		foreach ( array( 'get', 'delete' ) as $action ) {
			$response = $this->call( 'mainwp_knowledge_' . $action, $input );
			$this->assert_error( $response, 'mainwp_knowledge_insecure', 403 );
			$this->assertArrayNotHasKey( 'record', $response['data'] );
			$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		}
	}

	private function render_site_overview(): string {
		$site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $this->site_id );
		// set_current_screen() fires current_screen, where the Dashboard sends headers; the render only reads the screen id.
		$previous                  = $GLOBALS['current_screen'] ?? null;
		$GLOBALS['current_screen'] = \WP_Screen::get( 'mainwp_page_managesites' );
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Manage_Sites_View::render_dashboard( $site );
		} finally {
			$html                      = ob_get_clean();
			$GLOBALS['current_screen'] = $previous;
		}
		return $html;
	}

	private function render_site_knowledge(): string {
		$site = \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $this->site_id );
		$previous                  = $GLOBALS['current_screen'] ?? null;
		$GLOBALS['current_screen'] = \WP_Screen::get( 'mainwp_page_managesites' );
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Manage_Sites_View::render_knowledge( $site );
			if ( has_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) ) ) {
				MainWP_Knowledge_UI::render_modal();
			}
		} finally {
			$html                      = ob_get_clean();
			$GLOBALS['current_screen'] = $previous;
		}
		return $html;
	}

	public function test_site_overview_does_not_mount_the_knowledge_panel(): void {
		$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $this->render_site_overview() );
	}

	public function test_site_knowledge_route_uses_individual_site_mode(): void {
		$previous_get        = $_GET;
		$_GET['page']        = 'managesites';
		$_GET['knowledgeid'] = (string) $this->site_id;

		try {
			$this->assertTrue( \MainWP\Dashboard\MainWP_System::is_mainwp_site_page() );
		} finally {
			$_GET = $previous_get;
		}
	}

	public function test_site_knowledge_page_mounts_the_panel_without_the_edit_right(): void {
		$this->withhold_right( 'edit_sites' );
		$html = $this->render_site_knowledge();

		$this->assertStringContainsString(
			'class="ui padded segment dimmable mainwp-knowledge-panel" data-scope-type="site" data-scope-id="' . $this->site_id . '" data-can-write="0"',
			$html
		);
		$this->assertStringNotContainsString( '<h3 class="ui header">Knowledge</h3>', $html );
		$this->assertStringContainsString( '<div class="ui small header">', $html );
		$this->assertStringContainsString( '<span class="mainwp-knowledge-inherited-title"></span>', $html );
		$this->assertStringContainsString( '<div class="sub header">', $html );
	}

	public function test_site_knowledge_modal_carries_the_view_hooks(): void {
		$this->withhold_right( 'edit_sites' );

		// The panel script reads these by name; a missing one leaves the View button or the read-only modal without text.
		$html = $this->render_site_knowledge();
		$this->assertStringContainsString( 'data-view="View knowledge record"', $html );
		$this->assertStringContainsString( 'data-close="Close"', $html );
		$this->assertStringContainsString( '<input type="text" id="mainwp-knowledge-type-view" readonly>', $html );
		$this->assertSame( 'View', \MainWP\Dashboard\MainWP_Knowledge_UI::js_strings()['view'] );
	}

	public function test_site_knowledge_without_dashboard_access_has_no_panel(): void {
		$this->withhold_right( 'access_individual_dashboard' );

		$this->assertStringNotContainsString( 'mainwp-knowledge-panel', $this->render_site_knowledge() );
	}

	public function test_client_edit_mounts_knowledge_as_the_last_accordion(): void {
		$client = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $this->client_id );
		ob_start();
		\MainWP\Dashboard\MainWP_Client::render_add_client_content( $client );
		MainWP_Knowledge_UI::render_modal();
		$html = ob_get_clean();

		$notes     = strpos( $html, 'id="mainwp-notes-meta-accordion"' );
		$knowledge = strpos( $html, 'id="mainwp-knowledge-accordion"' );
		$panel     = strpos( $html, 'mainwp-knowledge-panel mainwp-knowledge-accordion-content" data-scope-type="client" data-scope-id="' . $this->client_id . '"' );

		$this->assertNotFalse( $notes );
		$this->assertNotFalse( $knowledge );
		$this->assertNotFalse( $panel );
		$this->assertGreaterThan( $notes, $knowledge );
		$this->assertGreaterThan( $knowledge, $panel );
		$this->assertSame( 1, substr_count( $html, 'mainwp-knowledge-panel ' ) );
		$this->assertStringContainsString( 'class="ui dimmer mainwp-knowledge-loader"', $html );
		$this->assertStringNotContainsString( '<th class="mainwp-knowledge-type-cell">', $html );
		$this->assertStringNotContainsString( '<th class="mainwp-knowledge-updated-cell">', $html );

		preg_match_all( '/<div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="([^"]+)">/', $html, $accordions );
		$this->assertSame( 'mainwp-knowledge-accordion', end( $accordions[1] ) );
	}

	public function test_panel_uses_singular_types_and_separate_add_strings(): void {
		$this->assertSame( array( 'context' => 'Context', 'skill' => 'Skill', 'memory' => 'Memory' ), MainWP_Knowledge_UI::type_labels() );
		$messages = array();
		$this->track_filter( 'gettext', static function ( $translated, $text, $domain ) use ( &$messages ) {
			if ( 'mainwp' === $domain ) {
				$messages[] = $text;
			}
			return $translated;
		}, 10, 3 );
		$html = $this->render_site_knowledge();
		$this->assertStringContainsString( '<option value="skill">Skill</option>', $html );
		$this->assertMatchesRegularExpression( '/data-type="skill">.*?<div class="ui small header">\s*Skills\s*<div/s', $html );
		foreach ( array( 'Add Context', 'Add Skill', 'Add Memory' ) as $label ) {
			$this->assertContains( $label, $messages );
			$this->assertStringContainsString( $label, $html );
		}
		$this->assertNotContains( 'Add %s', $messages );
		$this->assertStringNotContainsString( 'Add Skills', $html );
		$skill = $this->create_record( array( 'type' => 'skill' ) );
		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );
		$items = array_column( $response['data']['inherited']['records'], null, 'id' );
		$this->assertSame( 'Skill', $items[ $skill->id ]['type_label'] );
	}

	public function test_panel_copy_uses_ai_agent_and_the_review_hint(): void {
		$strings = MainWP_Knowledge_UI::js_strings();
		$this->assertSame( 'Saved by an AI agent and not yet reviewed by a person. AI agents treat it as information only until you review and save it.', $strings['unverifiedHint'] );
		$this->assertSame( 'Delete this knowledge record? Connected AI agents lose it immediately.', $strings['confirmDelete'] );
		$site = $this->render_site_knowledge();
		$this->assertStringContainsString( '<div class="ui info message">', $site );
		foreach ( array(
			'Facts an AI agent should know before it acts on this site: hosting, stack, constraints, known quirks.',
			'Records saved here reach any AI agent connected to this Dashboard through MCP. There is no export in this release.',
			'Client records apply to every site assigned to the client, and AI agents receive them together with the records of this site. They are read-only here.',
			'Procedures and rules to follow when working here: how to deploy, what to check first, what never to touch.',
		) as $text ) {
			$this->assertStringContainsString( $text, $site );
		}
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'client', $this->client_id, true );
		$client = ob_get_clean();
		$this->assertStringContainsString( '<div class="ui info message">', $client );
		$this->assertStringContainsString( 'Records saved here reach any AI agent connected to this Dashboard through MCP. There is no export in this release.', $client );
		$this->assertStringContainsString( 'Client records apply to every site assigned to this client. Site records belong to the site and stay with it if the site is moved to another client.', $client );
		$this->assertStringContainsString( 'Facts an AI agent should know before it acts for this client: hosting, agreements, preferences, constraints.', $client );
		$this->assertStringContainsString( 'Add context, skills, and memories that connected AI agents can use when working for this client.', $client );
		$this->assertStringNotContainsString( 'AI client', $site . $client );
	}

	public function test_only_the_combined_inherited_table_has_a_type_column(): void {
		$html = $this->render_site_knowledge();
		$this->assertSame( 1, substr_count( $html, '<th class="mainwp-knowledge-type-cell">' ) );
		$this->assertSame( 4, substr_count( $html, '<th class="mainwp-knowledge-editor-cell collapsing">' ) );
		$this->assertSame( 4, substr_count( $html, '<th class="mainwp-knowledge-actions-cell no-sort collapsing">' ) );
	}

	public function test_list_site_answers_db_error_when_the_client_lookup_fails(): void {
		global $wpdb;
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->suppressed_errors = $wpdb->suppress_errors( true );
		$name = $wpdb->prefix . 'mainwp_wp_clients';
		$lookup = 'SELECT wc.*, cc.*  FROM ' . $name . ' wc ';
		$failed_queries = 0;
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $name, $lookup, &$failed_queries ) {
				if ( 0 === strpos( ltrim( $sql ), $lookup ) ) {
					++$failed_queries;
					return str_replace( 'FROM ' . $name . ' wc ', 'FROM ' . $name . '_missing wc ', $sql );
				}
				return $sql;
			}
		);

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertSame( 1, $failed_queries );
		$this->assert_error( $response, 'mainwp_knowledge_db_error', 500 );
		$this->assertArrayNotHasKey( 'inherited', $response['data'] );
	}

	public function test_list_site_answers_db_error_when_the_site_lookup_for_the_client_block_fails(): void {
		global $wpdb;
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->suppressed_errors = $wpdb->suppress_errors( true );
		$name           = $wpdb->prefix . 'mainwp_wp';
		$failed_queries = 0;
		// Only the site row lookup fails: scope_exists() reads the same table first, so a table-wide fault never reaches inherited_client().
		$this->track_filter(
			'query',
			static function ( $sql ) use ( $name, &$failed_queries ) {
				if ( 0 !== strpos( ltrim( $sql ), 'SELECT wp.*,wp_sync.*' ) ) {
					return $sql;
				}
				++$failed_queries;
				return preg_replace( '/FROM ' . preg_quote( $name, '/' ) . ' wp\b/', 'FROM ' . $name . '_missing wp', $sql );
			}
		);

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assertSame( 1, $failed_queries );
		$this->assert_error( $response, 'mainwp_knowledge_db_error', 500 );
		$this->assertArrayNotHasKey( 'inherited', $response['data'] );
	}

	public function test_list_site_answers_db_error_when_the_knowledge_select_fails(): void {
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->fail_selects_on( 'mainwp_knowledge' );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		$this->assert_error( $response, 'mainwp_knowledge_db_error', 500 );
		$this->assertArrayNotHasKey( 'records', $response['data'] );
	}

	public function test_list_site_fails_whole_when_only_the_inherited_client_read_fails(): void {
		$this->create_record( $this->site_input() );
		$this->create_record();
		$this->fail_selects_on( 'mainwp_knowledge', true );

		$response = $this->call( 'mainwp_knowledge_list', $this->site_input() );

		// An empty or missing client block would read as "this client has no knowledge".
		$this->assert_error( $response, 'mainwp_knowledge_db_error', 500 );
		$this->assertArrayNotHasKey( 'inherited', $response['data'] );
	}

	public function test_agency_ajax_write_read_list_and_count(): void {
		$input = $this->input( array( 'scope_type' => 'agency' ) );
		$response = $this->call( 'mainwp_knowledge_save', $input );
		$this->assertTrue( $response['success'] );
		$id = $response['data']['record']['id'];
		$this->assertSame( 0, $this->db->get_by_id( $id )->scope_id );
		$this->assertTrue( $this->call( 'mainwp_knowledge_get', array( 'id' => $id ) )['success'] );
		$list = $this->call( 'mainwp_knowledge_list', array( 'scope_type' => 'agency' ) );
		$this->assertTrue( $list['success'] );
		$this->assertTrue( $list['data']['can_write'] );
		$this->assertCount( 1, $list['data']['records'] );
		$this->assertSame( 1, $this->db->count_records( array( 'scope_type' => 'agency' ) ) );
		$this->assertTrue( $this->call( 'mainwp_knowledge_delete', array( 'id' => $id, 'revision' => 1 ) )['success'] );
	}

	public function test_required_ajax_set_clear_and_reread(): void {
		foreach ( array( 'context', 'skill' ) as $type ) {
			$input = $this->input( array( 'scope_type' => 'agency', 'type' => $type, 'required' => '1' ) );
			if ( 'skill' === $type ) {
				$input['description'] = 'Use this skill for maintenance.';
			}
			$response = $this->call( 'mainwp_knowledge_save', $input );
			$this->assertTrue( $response['success'] );
			$id = $response['data']['record']['id'];
			$this->assertSame( 1, $response['data']['record']['required'] );
			$this->assertSame( 1, $this->db->get_by_id( $id )->required );
			$this->assertSame( 1, $this->db->get_by_id( $id )->verified );
			$input['required'] = '0';
			$response = $this->call( 'mainwp_knowledge_save', $input + array( 'id' => $id, 'revision' => 1 ) );
			$this->assertTrue( $response['success'] );
			$this->assertSame( 0, $response['data']['record']['required'] );
			$this->assertSame( 0, $this->db->get_by_id( $id )->required );
			$this->assertSame( 2, $this->db->get_by_id( $id )->revision );
		}
	}

	public function test_required_ajax_client_set_clear_and_reread(): void {
		foreach ( array( 'context', 'skill' ) as $type ) {
			$input = $this->input( array( 'type' => $type, 'required' => '1' ) );
			if ( 'skill' === $type ) {
				$input['description'] = 'Use this skill for maintenance.';
			}
			$response = $this->call( 'mainwp_knowledge_save', $input );
			$this->assertTrue( $response['success'] );
			$id = $response['data']['record']['id'];
			$this->assertSame( 1, $response['data']['record']['required'] );
			$this->assertSame( 1, $this->db->get_by_id( $id )->required );
			$this->assertSame( 1, $this->call( 'mainwp_knowledge_get', array( 'id' => $id ) )['data']['record']['required'] );
			$inherited = array_column( $this->call( 'mainwp_knowledge_list', $this->site_input() )['data']['inherited']['records'], 'required', 'id' );
			$this->assertSame( 1, $inherited[ $id ] );
			$input['required'] = '0';
			$response = $this->call( 'mainwp_knowledge_save', $input + array( 'id' => $id, 'revision' => 1 ) );
			$this->assertTrue( $response['success'] );
			$this->assertSame( 0, $response['data']['record']['required'] );
			$this->assertSame( 0, $this->db->get_by_id( $id )->required );
			$this->assertSame( 2, $this->db->get_by_id( $id )->revision );
		}
	}

	public function test_required_ajax_restricted_user_cannot_write_edit_or_delete(): void {
		$record = $this->create_record( array( 'scope_type' => 'agency', 'required' => true ) );
		$this->restrict_to_other_site();
		$input = $this->input( array( 'scope_type' => 'agency', 'required' => '1' ) );
		foreach ( array( $input, $input + array( 'id' => $record->id, 'revision' => 1 ) ) as $fields ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_save', $fields ), 'mainwp_restricted_account', 403 );
		}
		$this->assert_error( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) ), 'mainwp_restricted_account', 403 );
		$list = $this->call( 'mainwp_knowledge_list', array( 'scope_type' => 'agency' ) );
		$this->assertTrue( $list['success'] );
		$this->assertFalse( $list['data']['can_write'] );
		$this->assertCount( 1, $list['data']['records'] );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assertTrue( $get['success'] );
		$this->assertFalse( $get['data']['can_write'] );
		$this->assertSame( 1, $get['data']['record']['required'] );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_required_ajax_delete_requires_cookie_and_trusted_origin(): void {
		$record = $this->create_record( array( 'scope_type' => 'agency', 'required' => true ) );
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$response = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) );
		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( 'This is a required record. Only a person using the Dashboard can change or delete it.', $response['data']['message'] );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$this->assertTrue( $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) )['success'] );
		$this->assertNull( $this->db->get_by_id( $record->id ) );
	}

	public function test_required_ajax_delete_refuses_a_cookie_for_another_user(): void {
		$record = $this->create_record( array( 'scope_type' => 'agency', 'required' => true ) );
		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $other, time() + HOUR_IN_SECONDS, 'logged_in' );
		$this->assertSame( $other, wp_validate_auth_cookie( '', 'logged_in' ) );
		$response = $this->call( 'mainwp_knowledge_delete', array( 'id' => $record->id, 'revision' => 1 ) );
		$this->assert_error( $response, 'mainwp_knowledge_invalid', 400 );
		$this->assertSame( 'This is a required record. Only a person using the Dashboard can change or delete it.', $response['data']['message'] );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_required_ajax_rejects_ineligible_records_and_agency_scope_id(): void {
		foreach ( array( $this->input( array( 'type' => 'memory', 'required' => '1' ) ), $this->site_input( array( 'required' => '1' ) ), $this->input( array( 'scope_type' => 'agency', 'type' => 'memory', 'required' => '1' ) ), $this->input( array( 'scope_type' => 'agency', 'scope_id' => 0 ) ) ) as $input ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_save', $input ), 'mainwp_knowledge_invalid', 400 );
		}
		$this->assertSame( 0, $this->row_count() );
	}

	public function test_get_reports_scope_and_write_access_for_all_levels(): void {
		$levels = array(
			'Agency' => $this->input( array( 'scope_type' => 'agency' ) ),
			'Client: ' . \MainWP\Dashboard\MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $this->client_id )->name => $this->input(),
			'Site: ' . \MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $this->site_id )->name => $this->site_input(),
		);
		foreach ( $levels as $label => $input ) {
			$record = $this->create_record( $input );
			$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
			$this->assertTrue( $get['success'] );
			$this->assertTrue( $get['data']['can_write'] );
			$this->assertSame( $record->scope_type, $get['data']['record']['scope_type'] );
			$this->assertSame( $record->scope_id, $get['data']['record']['scope_id'] );
			$this->assertArrayHasKey( 'level_label', $get['data']['record'] );
			$this->assertSame( $label, $get['data']['record']['level_label'] );
		}
	}

	public function test_get_reports_read_only_access_for_restricted_scopes_and_rights(): void {
		$agency = $this->create_record( array( 'scope_type' => 'agency' ) );
		$client = $this->create_record();
		$site = $this->create_record( $this->site_input() );
		$this->create_test_site( array( 'client_id' => $this->client_id ) );
		$this->restrict_to_own_site();
		$this->withhold_right( 'edit_sites' );
		foreach ( array( $agency, $client, $site ) as $record ) {
			$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
			$this->assertTrue( $get['success'] );
			$this->assertFalse( $get['data']['can_write'] );
			$this->assertSame( $record->scope_type, $get['data']['record']['scope_type'] );
			$this->assertSame( $record->scope_id, $get['data']['record']['scope_id'] );
		}
	}

	public function test_get_agency_without_manage_clients_is_read_only(): void {
		$record = $this->create_record( array( 'scope_type' => 'agency', 'required' => true ) );
		$this->withhold_right( 'manage_clients' );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assertTrue( $get['success'] );
		$this->assertFalse( $get['data']['can_write'] );
		$this->assertSame( 1, $get['data']['record']['required'] );
		$input = $this->input( array( 'scope_type' => 'agency', 'required' => '0', 'id' => $record->id, 'revision' => 1 ) );
		$this->assert_error( $this->call( 'mainwp_knowledge_save', $input ), 'mainwp_access_denied', 403 );
		$this->assertEquals( $record, $this->db->get_by_id( $record->id ) );
	}

	public function test_multiple_panels_share_one_footer_modal(): void {
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'site', $this->site_id );
		MainWP_Knowledge_UI::render_panel( 'client', $this->client_id );
		$html = ob_get_clean();
		$this->assertSame( 2, substr_count( $html, 'mainwp-knowledge-panel"' ) );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-modal"', $html );
		$this->assertNotFalse( has_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) ) );
		ob_start();
		MainWP_Knowledge_UI::render_modal();
		$modal = ob_get_clean();
		$this->assertSame( 1, substr_count( $modal, 'id="mainwp-knowledge-modal"' ) );
		$this->assertStringContainsString( 'id="mainwp-knowledge-type-view" readonly', $modal );
		$this->assertStringContainsString( 'id="mainwp-knowledge-remove"', $modal );
		$this->assertStringContainsString( 'id="mainwp-knowledge-modal-title"', $modal );
		$this->assertStringContainsString( 'class="ui mini label" id="mainwp-knowledge-modal-level" style="display:none"', $modal );
		$strings = MainWP_Knowledge_UI::js_strings();
		$this->assertStringContainsString( 'class="ui mini yellow label" id="mainwp-knowledge-modal-unverified" style="display:none" data-tooltip="' . esc_attr( $strings['unverifiedHint'] ) . '" data-inverted="">Unverified</span>', $modal );
	}

	private function review_record( array $overrides = array(), bool $verified = false ): object {
		$input = $this->input( $overrides );
		if ( 'skill' === $input['type'] ) {
			$input['description'] = 'Use <staging> & check the deployment.';
		}
		$record = $this->db->save( $input, $verified ? MainWP_DB_Knowledge::ORIGIN_UI : MainWP_DB_Knowledge::ORIGIN_ABILITY );
		$this->assertNotWPError( $record );
		return $record;
	}

	public function test_review_filters_status_type_and_level(): void {
		$agency = $this->review_record( array( 'scope_type' => 'agency', 'type' => 'skill' ) );
		$client = $this->review_record( array( 'type' => 'memory' ) );
		$site = $this->review_record( $this->site_input(), true );
		$cases = array(
			array( array(), array( $agency->id, $client->id ) ),
			array( array( 'status' => 'verified' ), array( $site->id ) ),
			array( array( 'status' => 'all' ), array( $agency->id, $client->id, $site->id ) ),
			array( array( 'type' => 'skill' ), array( $agency->id ) ),
			array( array( 'level' => 'client' ), array( $client->id ) ),
			array( array( 'status' => 'all', 'type' => 'context', 'level' => 'site' ), array( $site->id ) ),
			array( array( 'type' => 'memory', 'level' => 'agency' ), array() ),
		);
		foreach ( $cases as $case ) {
			$response = $this->call( 'mainwp_knowledge_review', $case[0] );
			$this->assertEqualsCanonicalizing( $case[1], array_column( $response['data'], 'id' ) );
			$this->assertSame( count( $case[1] ), $response['recordsFiltered'] );
			$this->assertSame( count( $case[1] ), $response['recordsTotal'] );
		}
	}

	public function test_review_pagination_counts_visible_matching_rows_before_limit(): void {
		$ids = array();
		for ( $i = 0; $i < 5; ++$i ) {
			$ids[] = $this->review_record()->id;
		}
		$this->review_record( array(), true );
		$page = $this->call( 'mainwp_knowledge_review', array( 'draw' => '7', 'start' => '2', 'length' => '2' ) );
		$this->assertSame( 7, $page['draw'] );
		$this->assertSame( 5, $page['recordsTotal'] );
		$this->assertSame( 5, $page['recordsFiltered'] );
		$this->assertSame( array_reverse( array_slice( $ids, 1, 2 ) ), array_column( $page['data'], 'id' ) );
		$empty = $this->call( 'mainwp_knowledge_review', array( 'start' => '8', 'length' => '2' ) );
		$this->assertSame( 5, $empty['recordsFiltered'] );
		$this->assertSame( array(), $empty['data'] );
	}

	public function test_review_restricted_visibility_matches_knowledge_list_in_sql(): void {
		$agency = $this->review_record( array( 'scope_type' => 'agency' ) );
		$client = $this->review_record();
		$site = $this->review_record( $this->site_input() );
		$other_client = $this->create_test_client();
		$other_site = $this->create_test_site( array( 'client_id' => $other_client ) );
		$this->review_record( array( 'scope_id' => $other_client ) );
		$this->review_record( array( 'scope_type' => 'site', 'scope_id' => $other_site ) );
		$this->restrict_to_own_site();
		$page = $this->call( 'mainwp_knowledge_review', array( 'length' => '1' ) );
		$this->assertSame( 3, $page['recordsTotal'] );
		$this->assertSame( 3, $page['recordsFiltered'] );
		$this->assertCount( 1, $page['data'] );
		$all = $this->call( 'mainwp_knowledge_review', array( 'length' => '10' ) );
		$this->assertEqualsCanonicalizing( array( $agency->id, $client->id, $site->id ), array_column( $all['data'], 'id' ) );
		$ability = \MainWP\Dashboard\MainWP_Abilities_Knowledge::execute_list_knowledge( array() );
		$this->assertSame( array_column( $ability['items'], 'id' ), array_column( $all['data'], 'id' ) );
		$this->withhold_right( 'manage_clients' );
		$this->track_filter( 'mainwp_check_site_access', '__return_false' );
		$agency_only = $this->call( 'mainwp_knowledge_review', array() );
		$this->assertSame( 1, $agency_only['recordsTotal'] );
		$this->assertSame( array( $agency->id ), array_column( $agency_only['data'], 'id' ) );
	}

	public function test_review_refuses_bad_nonce_and_missing_base_permission(): void {
		$this->review_record();
		$this->assert_error( $this->call( 'mainwp_knowledge_review', array( 'security' => wp_create_nonce( 'mainwp_knowledge_list' ) ) ), 'mainwp_knowledge_insecure', 403 );
		$this->set_current_user_as_subscriber();
		$this->assert_error( $this->call( 'mainwp_knowledge_review', array() ), 'mainwp_permission_denied', 403 );
		wp_set_current_user( 0 );
		$this->assert_error( $this->call( 'mainwp_knowledge_review', array() ), 'mainwp_permission_denied', 401 );
	}

	public function test_review_rejects_malformed_filters_and_pagination(): void {
		foreach ( array( array( 'status' => 'draft' ), array( 'type' => 'code' ), array( 'level' => 'global' ), array( 'status' => array( 'all' ) ), array( 'start' => '-1' ), array( 'length' => '0' ), array( 'length' => '101' ), array( 'draw' => 'oops' ) ) as $post ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_review', $post ), 'mainwp_knowledge_invalid', 400 );
		}
	}

	public function test_review_rows_escape_copy_and_show_agent_provenance(): void {
		$record = $this->review_record( array( 'scope_type' => 'agency', 'type' => 'skill', 'title' => '<script>Rule</script>' ) );
		$page = $this->call( 'mainwp_knowledge_review', array() );
		$row = $page['data'][0];
		$this->assertSame( $record->id, $row['id'] );
		$this->assertStringContainsString( '&lt;script&gt;Rule&lt;/script&gt;', $row['title'] );
		$this->assertStringContainsString( 'Use &lt;staging&gt; &amp; check the deployment.', $row['title'] );
		$this->assertStringContainsString( 'Unverified', $row['status'] );
		$this->assertSame( 'AI agent via ' . esc_html( wp_get_current_user()->display_name ), $row['editor'] );
		$this->assertStringContainsString( 'mainwp-knowledge-open', $row['actions'] );
		$this->assertStringContainsString( 'Open', $row['actions'] );
		$this->assertArrayNotHasKey( 'body', $row );
		$required = $this->create_record( array( 'scope_type' => 'agency', 'type' => 'skill', 'title' => '<b>Required rule</b>', 'required' => true ) );
		$page = $this->call( 'mainwp_knowledge_review', array( 'status' => 'verified' ) );
		$this->assertSame( $required->id, $page['data'][0]['id'] );
		$this->assertSame( '&lt;b&gt;Required rule&lt;/b&gt; <span class="ui mini label">Required</span><div class="ui small text">' . esc_html( $required->description ) . '</div>', $page['data'][0]['title'] );
	}

	public function test_review_database_failure_is_an_error_not_an_empty_queue(): void {
		$this->review_record();
		$this->fail_selects_on( 'mainwp_knowledge' );
		$this->assert_error( $this->call( 'mainwp_knowledge_review', array() ), 'mainwp_knowledge_db_error', 500 );
	}

	public function test_agent_editor_labels_use_final_copy_and_unknown_user(): void {
		$record = $this->review_record( array( 'scope_type' => 'agency' ) );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assertSame( 'AI agent via ' . wp_get_current_user()->display_name, $get['data']['record']['updated_by_name'] );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'mainwp_knowledge', array( 'updated_by' => 0 ), array( 'id' => $record->id ) );
		$get = $this->call( 'mainwp_knowledge_get', array( 'id' => $record->id ) );
		$this->assertSame( 'Unknown user', $get['data']['record']['updated_by_name'] );
		$page = $this->call( 'mainwp_knowledge_review', array() );
		$this->assertSame( 'Unknown user', $page['data'][0]['editor'] );
	}

	public function test_review_handler_is_registered_with_its_nonce(): void {
		MainWP_Knowledge_UI::init();
		$this->assertContains( 'mainwp_knowledge_review', MainWP_Knowledge_UI::ACTIONS );
		$this->assertNotFalse( has_action( 'wp_ajax_mainwp_knowledge_review', array( MainWP_Knowledge_UI::instance(), 'ajax_review' ) ) );
	}

	private function client_overview_widget(): string {
		$previous_get = $_GET;
		$_GET['client_id'] = $this->client_id;
		ob_start();
		try {
			MainWP_Client_Overview_Knowledge::render();
		} finally {
			$html = ob_get_clean();
			$_GET = $previous_get;
		}
		return $html;
	}

	private function client_overview_registration(): array {
		$previous_get = $_GET;
		$previous_boxes = $GLOBALS['mainwp_widget_boxes'] ?? null;
		$_GET['client_id'] = $this->client_id;
		$_GET['page'] = 'ClientOverview';
		$GLOBALS['mainwp_widget_boxes'] = array();
		ob_start();
		try {
			$screen = \WP_Screen::get( 'mainwp_page_ClientOverview' );
			MainWP_Client_Overview::add_meta_boxes( $screen );
			MainWP_Client_Overview::render_screen_options();
			$boxes = $GLOBALS['mainwp_widget_boxes'][ $screen->id ];
		} finally {
			$html = ob_get_clean();
			$_GET = $previous_get;
			$GLOBALS['mainwp_widget_boxes'] = $previous_boxes;
		}
		return array( $boxes, $html );
	}

	public function test_client_overview_widget_is_enabled_and_editable_for_unrestricted_user(): void {
		list( $boxes, $options ) = $this->client_overview_registration();
		$this->assertArrayHasKey( 'knowledge', $boxes );
		$this->assertSame( array( -1, -1, 6, 40 ), $boxes['knowledge']['layout'] );
		$this->assertStringContainsString( 'id="mainwp_show_widget_knowledge"', $options );
		$html = $this->client_overview_widget();
		$this->assertStringContainsString( 'data-scope-type="client" data-scope-id="' . $this->client_id . '" data-can-write="1"', $html );
		$this->assertStringContainsString( 'mainwp-knowledge-add', $html );
		$this->assertSame( 1, preg_match_all( '/<h2\b[^>]*>\s*Knowledge\s*<div/', $html ) );
		$this->assertStringContainsString( 'Context, skills, and memories that AI agents receive for this client and its sites.', $html );
		$this->assertStringNotContainsString( 'Add context, skills, and memories that connected AI agents can use when working for this client.', $html );
		$this->assertStringNotContainsString( '<div class="ui info message">', $html );
		$this->assertStringContainsString( '<div class="dimmable mainwp-knowledge-panel" data-scope-type="client"', $html );
		$this->assertStringNotContainsString( 'ui basic segment dimmable mainwp-knowledge-panel', $html );
		$this->assertStringNotContainsString( 'ui padded segment dimmable mainwp-knowledge-panel', $html );
		$this->assertStringContainsString( '<div class="dimmable mainwp-knowledge-site-list"', $html );
		$this->assertStringNotContainsString( 'ui basic segment dimmable mainwp-knowledge-site-list', $html );
		$this->assertStringContainsString( '<thead><tr><th>Title</th><th class="collapsing">Type</th><th class="collapsing">Last edited by</th><th class="collapsing"></th></tr></thead>', $html );
		$this->assertStringNotContainsString( 'id="mainwp-knowledge-modal"', $html );
		$this->assertNotFalse( has_action( 'admin_footer', array( MainWP_Knowledge_UI::class, 'render_modal' ) ) );
	}

	public function test_client_overview_widget_partial_coverage_is_read_only_and_hides_unreadable_sites(): void {
		$hidden = $this->create_test_site( array( 'client_id' => $this->client_id ) );
		$this->create_record( $this->site_input( array( 'title' => 'Loaded only on expansion' ) ) );
		$this->restrict_to_own_site();
		$html = $this->client_overview_widget();
		$this->assertStringContainsString( 'data-scope-type="client" data-scope-id="' . $this->client_id . '" data-can-write="0"', $html );
		$this->assertStringNotContainsString( 'mainwp-knowledge-add', $html );
		$this->assertStringContainsString( 'You can view these records. Editing client records needs client management rights and access to every site of this client.', $html );
		$this->assertStringContainsString( 'data-site-id="' . $this->site_id . '"', $html );
		$this->assertStringNotContainsString( 'data-site-id="' . $hidden . '"', $html );
		$this->assertStringContainsString( 'Context: 1', $html );
		$this->assertStringContainsString( 'Skills: 0', $html );
		$this->assertStringContainsString( 'Memory: 0', $html );
		$this->assertStringContainsString( 'View on the site', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=managesites&knowledgeid=' . $this->site_id ) ), $html );
		$this->assertStringContainsString( '<td class="right aligned collapsing"><a class="ui mini basic button" href="' . esc_url( admin_url( 'admin.php?page=managesites&knowledgeid=' . $this->site_id ) ) . '">View on the site</a></td>', $html );
		$this->assertStringNotContainsString( 'Loaded only on expansion', $html );
		list( $boxes ) = $this->client_overview_registration();
		$this->assertArrayHasKey( 'knowledge', $boxes );
	}

	public function test_client_overview_widget_hidden_without_manage_clients(): void {
		$this->withhold_right( 'manage_clients' );
		$this->assertSame( '', $this->client_overview_widget() );
		list( $boxes, $options ) = $this->client_overview_registration();
		$this->assertArrayNotHasKey( 'knowledge', $boxes );
		$this->assertStringNotContainsString( 'mainwp_show_widget_knowledge', $options );
	}

	public function test_client_overview_widget_hidden_for_access_client_dashboard_only_user(): void {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $id )->add_cap( 'manage_options' );
		wp_set_current_user( $id );
		$this->track_filter( 'mainwp_currentusercan', static function ( $allowed, $type, $right ) {
			return 'dashboard' === $type ? 'access_client_dashboard' === $right : $allowed;
		}, 10, 3 );
		$this->assertTrue( \mainwp_current_user_can( 'dashboard', 'access_client_dashboard' ) );
		$this->assertSame( '', $this->client_overview_widget() );
		list( $boxes ) = $this->client_overview_registration();
		$this->assertArrayNotHasKey( 'knowledge', $boxes );
	}

	public function test_client_overview_widget_hidden_without_client_overlap(): void {
		$this->restrict_to_other_site();
		$this->assertSame( '', $this->client_overview_widget() );
		list( $boxes ) = $this->client_overview_registration();
		$this->assertArrayNotHasKey( 'knowledge', $boxes );
	}

	public function test_client_overview_widget_empty_sites_and_escaped_names(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'name' => '<script>Site & name</script>' ), array( 'id' => $this->site_id ) );
		$html = $this->client_overview_widget();
		$this->assertStringContainsString( '&lt;script&gt;Site &amp; name&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'No records', $html );
		$this->track_filter( 'mainwp_check_site_access', '__return_false' );
		$this->assertStringContainsString( 'No sites to show.', $this->client_overview_widget() );
	}

	public function test_client_overview_widget_site_roster_failure_is_not_empty(): void {
		global $wpdb;
		$this->suppressed_errors = $wpdb->suppress_errors( true );
		$name = $wpdb->prefix . 'mainwp_wp';
		$pattern = '/^\s*SELECT id, name FROM `?' . preg_quote( $name, '/' ) . '`? WHERE client_id\b/i';
		$this->track_filter( 'query', static function ( $sql ) use ( $pattern, $name ) {
			return preg_match( $pattern, $sql ) ? str_replace( $name, $name . '_missing', $sql ) : $sql;
		} );
		$html = $this->client_overview_widget();
		$strings = MainWP_Knowledge_UI::js_strings();
		$this->assertStringContainsString( '<div class="ui red message">' . esc_html( $strings['failed'] ) . '</div>', $html );
		$this->assertStringNotContainsString( 'No sites to show.', $html );
	}

	public function test_client_overview_expansion_is_read_only_and_rechecks_site_access(): void {
		$record = $this->create_record( $this->site_input() );
		$this->restrict_to_own_site();
		$input = $this->site_input( array( 'read_only' => '1' ) );
		$response = $this->call( 'mainwp_knowledge_list', $input );
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['can_write'] );
		$this->assertNull( $response['data']['inherited'] );
		$this->assertSame( array( $record->id ), array_column( $response['data']['records'], 'id' ) );
		$this->restrict_to_other_site();
		$this->assert_error( $this->call( 'mainwp_knowledge_list', $input ), 'mainwp_site_not_found', 404 );
		$this->assert_error( $this->call( 'mainwp_knowledge_list', $input + array( 'security' => wp_create_nonce( 'wrong' ) ) ), 'mainwp_knowledge_insecure', 403 );
	}

	public function test_client_overview_expansion_rejects_invalid_read_only_mode(): void {
		foreach ( array( '2', '-1', array( '1' ) ) as $mode ) {
			$this->assert_error( $this->call( 'mainwp_knowledge_list', $this->site_input( array( 'read_only' => $mode ) ) ), 'mainwp_knowledge_invalid', 400 );
		}
	}

	public function test_client_overview_panel_can_omit_its_heading(): void {
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'client', $this->client_id, true, false );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<h2', $html );
		$this->assertStringNotContainsString( 'Add context, skills, and memories that connected AI agents can use when working for this client.', $html );
		$this->assertStringNotContainsString( '<div class="ui info message">', $html );
		$this->assertStringNotContainsString( 'Records saved here reach any AI agent connected to this Dashboard through MCP. There is no export in this release.', $html );
		$this->assertStringNotContainsString( 'Client records apply to every site assigned to this client. Site records belong to the site and stay with it if the site is moved to another client.', $html );
		$this->assertSame( 3, substr_count( $html, '<th class="mainwp-knowledge-editor-cell collapsing">' ) );
		$this->assertSame( 3, substr_count( $html, '<th class="mainwp-knowledge-actions-cell no-sort collapsing">' ) );
		$this->assertStringContainsString( '<div class="dimmable mainwp-knowledge-panel" data-scope-type="client"', $html );
		$this->assertStringNotContainsString( 'ui basic segment dimmable mainwp-knowledge-panel', $html );
		$this->assertStringNotContainsString( 'ui padded segment dimmable mainwp-knowledge-panel', $html );
		ob_start();
		MainWP_Knowledge_UI::render_panel( 'client', $this->client_id );
		$full = ob_get_clean();
		$this->assertStringContainsString( '<div class="ui padded segment dimmable mainwp-knowledge-panel" data-scope-type="client"', $full );
	}

	public function test_scope_counts_group_types_and_unverified_once_per_visible_set(): void {
		$other_client = $this->create_test_client();
		$other_site = $this->create_test_site( array( 'client_id' => $other_client ) );
		foreach ( array( array( 'client', $this->client_id ), array( 'site', $this->site_id ) ) as $scope ) {
			foreach ( array( 'context', 'skill', 'memory', 'memory' ) as $type ) {
				$this->create_record( array( 'scope_type' => $scope[0], 'scope_id' => $scope[1], 'type' => $type ) );
			}
			$this->review_record( array( 'scope_type' => $scope[0], 'scope_id' => $scope[1], 'type' => 'skill' ) );
		}
		$queries = array();
		$this->track_filter( 'query', static function ( $sql ) use ( &$queries ) {
			if ( false !== strpos( $sql, 'mainwp_knowledge' ) && false !== stripos( $sql, 'GROUP BY' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		} );
		$expected = array( 'context' => 1, 'skill' => 2, 'memory' => 2, 'total' => 5, 'unverified' => 1 );
		$clients = $this->db->get_scope_counts( 'client', array( $this->client_id, $other_client ) );
		$sites = $this->db->get_scope_counts( 'site', array( $this->site_id, $other_site ) );
		$this->assertSame( $expected, $clients[ $this->client_id ] );
		$this->assertSame( $expected, $sites[ $this->site_id ] );
		$this->assertSame( array( 'context' => 0, 'skill' => 0, 'memory' => 0, 'total' => 0, 'unverified' => 0 ), $clients[ $other_client ] );
		$this->assertSame( $clients[ $other_client ], $sites[ $other_site ] );
		$this->assertCount( 2, $queries );
	}

	public function test_scope_counts_exclude_unreadable_clients_and_sites(): void {
		$other_client = $this->create_test_client();
		$hidden = $this->create_test_site( array( 'client_id' => $this->client_id ) );
		$other_site = $this->create_test_site( array( 'client_id' => $other_client ) );
		$this->create_record();
		$this->create_record( array( 'scope_id' => $other_client ) );
		$this->create_record( $this->site_input() );
		$this->create_record( $this->site_input( array( 'scope_id' => $hidden ) ) );
		$this->restrict_to_own_site();
		$clients = $this->db->get_scope_counts( 'client', array( $this->client_id, $other_client ) );
		$sites = $this->db->get_scope_counts( 'site', array( $this->site_id, $hidden, $other_site ) );
		$this->assertSame( array( $this->client_id ), array_keys( $clients ) );
		$this->assertSame( array( $this->site_id ), array_keys( $sites ) );
		$this->assertSame( 1, $clients[ $this->client_id ]['context'] );
		$this->assertSame( 1, $sites[ $this->site_id ]['total'] );
		$this->withhold_right( 'manage_clients' );
		$this->assertSame( array(), $this->db->get_scope_counts( 'client', array( $this->client_id ) ) );
		$this->track_filter( 'mainwp_check_site_access', '__return_false' );
		$this->assertSame( array(), $this->db->get_scope_counts( 'site', array( $this->site_id ) ) );
	}

	public function test_scope_counts_database_failure_is_not_zero(): void {
		$this->fail_selects_on( 'mainwp_knowledge' );
		$result = $this->db->get_scope_counts( 'site', array( $this->site_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_knowledge_db_error', $result->get_error_code() );
	}

	public function test_client_knowledge_column_prefetches_once_and_hides_unreadable_client_counts(): void {
		$this->create_record();
		$this->review_record();
		$other_client = $this->create_test_client();
		$this->create_test_site( array( 'client_id' => $other_client ) );
		$this->create_record( array( 'scope_id' => $other_client ) );
		$this->restrict_to_own_site();
		$table = new MainWP_Client_List_Table();
		$this->client_table = $table;
		$this->assertArrayHasKey( 'knowledge', $table->get_default_columns() );
		$this->track_filter( 'mainwp_clients_sitestable_getcolumns', static function () {
			return array( 'knowledge' => 'Knowledge' );
		} );
		$queries = 0;
		$this->track_filter( 'query', static function ( $sql ) use ( &$queries ) {
			if ( false !== strpos( $sql, 'mainwp_knowledge' ) && 0 === stripos( ltrim( $sql ), 'SELECT' ) ) {
				++$queries;
			}
			return $sql;
		} );
		$table->prepare_items();
		$this->assertCount( 2, array_intersect( array( $this->client_id, $other_client ), array_column( $table->items, 'client_id' ) ) );
		$this->assertSame( 1, $queries );
		$items = array_column( $table->items, null, 'client_id' );
		ob_start();
		$table->print_column_headers( false );
		$headers = ob_get_clean();
		$this->assertStringContainsString( "id='knowledge' class='manage-knowledge-column collapsing no-sort'", $headers );
		ob_start();
		$table->single_row( $items[ $this->client_id ] );
		$visible = ob_get_clean();
		$this->assertStringContainsString( 'class="ui mini grey button"', $visible );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=ManageClients&client_id=' . $this->client_id ) ), $visible );
		$this->assertStringContainsString( '</i>2</a>', $visible );
		$this->assertStringContainsString( '1 unverified', $visible );
		$this->assertStringContainsString( 'Saved by AI agents and not yet reviewed by a person.', $visible );
		ob_start();
		$table->single_row( $items[ $other_client ] );
		$hidden = ob_get_clean();
		$this->assertStringNotContainsString( 'Client knowledge records', $hidden );
		$this->assertStringNotContainsString( 'unverified', $hidden );
		$this->assertSame( 1, $queries );
		remove_action( 'mainwp_manageclients_tabletop', array( $table, 'generate_tabletop' ) );
	}

	public function test_client_knowledge_column_count_failure_renders_failed_message(): void {
		$this->create_record();
		$table = new MainWP_Client_List_Table();
		$this->client_table = $table;
		$this->track_filter( 'mainwp_clients_sitestable_getcolumns', static function () {
			return array( 'knowledge' => 'Knowledge' );
		} );
		$this->fail_selects_on( 'mainwp_knowledge' );
		$table->prepare_items();
		$items = array_column( $table->items, null, 'client_id' );
		$this->assertArrayHasKey( $this->client_id, $items );
		ob_start();
		try {
			$table->single_row( $items[ $this->client_id ] );
		} catch ( \Throwable $error ) {
			$this->fail( 'Knowledge count failures must not break single_row(): ' . $error->getMessage() );
		} finally {
			$html = ob_get_clean();
		}
		$strings = MainWP_Knowledge_UI::js_strings();
		$this->assertMatchesRegularExpression( '/<td class="knowledge column-knowledge">\s*<span>' . preg_quote( esc_html( $strings['failed'] ), '/' ) . '<\/span>\s*<\/td>/', $html );
		$this->assertStringNotContainsString( 'Client knowledge records', $html );
		remove_action( 'mainwp_manageclients_tabletop', array( $table, 'generate_tabletop' ) );
	}
}
