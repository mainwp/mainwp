<?php
/**
 * Tests for MainWP_DB_Assistant database layer.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Assistant;
use MainWP\Dashboard\MainWP_System;

/**
 * Lightweight wpdb stub for DB Assistant failure-path tests.
 */
class Test_DB_Assistant_Failure_Wpdb_Fake {
	public $insert_id     = 321;
	public $insert_result = 1;
	public $update_result = 1;
	public $query_result  = 1;
	public $delete_result = 1;
	public $last_error    = '';
	public $get_row_result;

	public function insert( ...$args ) {
		unset( $args );

		return $this->insert_result;
	}

	public function update( ...$args ) {
		unset( $args );

		return $this->update_result;
	}

	public function query( ...$args ) {
		unset( $args );

		return $this->query_result;
	}

	public function delete( ...$args ) {
		unset( $args );

		return $this->delete_result;
	}

	public function prepare( $query, ...$args ) {
		unset( $args );

		return $query;
	}

	public function get_row( ...$args ) {
		unset( $args );

		return $this->get_row_result;
	}
}

/**
 * Testable DB assistant variant for failure-path coverage.
 */
class Testable_DB_Assistant_Failure extends MainWP_DB_Assistant {
	public $conversation_result;

	public function __construct() {}

	public function set_wpdb( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function get_conversation( $conversation_id, $user_id = null ) {
		unset( $conversation_id, $user_id );

		return $this->conversation_result ? $this->conversation_result : (object) array( 'id' => 1 );
	}

	protected function table_name( $suffix, $tablePrefix = null ) {
		unset( $tablePrefix );

		return 'wp_mainwp_' . $suffix;
	}
}

/**
 * Test class for MainWP_DB_Assistant.
 *
 * Tests conversation CRUD, message CRUD, user scoping, pagination, and cleanup.
 */
class Test_DB_Assistant extends MainWP_Assistant_Test_Case {

	/**
	 * A new confirmation ID must replace the bound row without duplicating the turn.
	 */
	public function test_swap_assistant_message_success() {
		$user_id         = $this->set_current_user_as_admin();
		$db              = MainWP_DB_Assistant::instance();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db->add_message( $conversation_id, 'user', 'Question', $user_id );
		$old_id = $db->add_message( $conversation_id, 'assistant', 'Old answer', $user_id, array( 'route' => 'agent' ) );
		$before = $db->get_messages( $conversation_id, $user_id );
		$new_id = $db->swap_assistant_message( $conversation_id, $old_id, 'New card', $user_id, array( 'requires_confirmation' => true ), $before[1]->content, $before[1]->metadata );
		$this->assertIsInt( $new_id );
		$this->assertGreaterThan( $old_id, $new_id );
		$after = $db->get_messages( $conversation_id, $user_id );
		$this->assertCount( 2, $after );
		$this->assertSame( array( 'user', 'assistant' ), array_column( $after, 'role' ) );
		$this->assertEquals( $before[0], $after[0] );
		$this->assertSame( $new_id, (int) $after[1]->id );
		$this->assertSame( 'New card', $after[1]->content );
		$this->assertNotContains( $old_id, array_map( 'intval', array_column( $after, 'id' ) ) );
	}

	/**
	 * Either changed field must refuse a swap and remove its speculative row.
	 */
	public function test_swap_assistant_message_stale_content_or_metadata() {
		$user_id         = $this->set_current_user_as_admin();
		$db              = MainWP_DB_Assistant::instance();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db->add_message( $conversation_id, 'user', 'Question', $user_id );
		$old_id = $db->add_message( $conversation_id, 'assistant', 'Old answer', $user_id, array( 'route' => 'agent' ) );
		$before = $db->get_messages( $conversation_id, $user_id );
		foreach ( array( array( 'old answer', $before[1]->metadata ), array( $before[1]->content, '{"route":"Agent"}' ) ) as $expected ) {
			$result = $db->swap_assistant_message( $conversation_id, $old_id, 'New card', $user_id, null, $expected[0], $expected[1] );
			$this->assertWPErrorWithCode( $result, 'regenerate_target_stale' );
			$this->assertEquals( $before, $db->get_messages( $conversation_id, $user_id ) );
		}
	}

	/**
	 * A later turn must survive an attempted swap of an older answer.
	 */
	public function test_swap_assistant_message_rejects_nonlatest() {
		$user_id         = $this->set_current_user_as_admin();
		$db              = MainWP_DB_Assistant::instance();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db->add_message( $conversation_id, 'user', 'Question', $user_id );
		$old_id = $db->add_message( $conversation_id, 'assistant', 'Old answer', $user_id );
		$db->add_message( $conversation_id, 'user', 'Next question', $user_id );
		$before = $db->get_messages( $conversation_id, $user_id );
		$result = $db->swap_assistant_message( $conversation_id, $old_id, 'New card', $user_id, null, $before[1]->content, $before[1]->metadata );
		$this->assertWPErrorWithCode( $result, 'regenerate_target_stale' );
		$this->assertEquals( $before, $db->get_messages( $conversation_id, $user_id ) );
	}

	// =========================================================================
	// CONVERSATION CREATION TESTS
	// =========================================================================

	/**
	 * Test creating a conversation.
	 */
	public function test_create_conversation_returns_id() {
		$user_id = $this->set_current_user_as_admin();

		$conversation_id = $this->create_test_conversation( $user_id, 'Test Title' );

		$this->assertIsInt( $conversation_id );
		$this->assertGreaterThan( 0, $conversation_id );
	}

	/**
	 * Test creating a conversation with metadata.
	 */
	public function test_create_conversation_with_metadata() {
		$user_id  = $this->set_current_user_as_admin();
		$metadata = array( 'model' => 'gpt-4o', 'temperature' => 0.7 );

		$conversation_id = $this->create_test_conversation( $user_id, 'Test With Meta', $metadata );
		$conversation    = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertIsArray( $conversation->metadata );
		$this->assertEquals( 'gpt-4o', $conversation->metadata['model'] );
		$this->assertEquals( 0.7, $conversation->metadata['temperature'] );
	}

	/**
	 * Test create_conversation clamps long titles to the DB column size.
	 */
	public function test_create_conversation_clamps_long_title() {
		$user_id = $this->set_current_user_as_admin();
		$title   = str_repeat( 'A', 300 );

		$conversation_id = $this->create_test_conversation( $user_id, $title );
		$conversation    = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertSame( 255, strlen( $conversation->title ) );
		$this->assertSame( str_repeat( 'A', 255 ), $conversation->title );
	}

	/**
	 * Test create_conversation fails with invalid user ID.
	 */
	public function test_create_conversation_fails_with_invalid_user() {
		$result = MainWP_DB_Assistant::instance()->create_conversation( 0, 'Test' );

		$this->assertWPErrorWithCode( $result, 'invalid_user' );
	}

	/**
	 * Test assistant table DDL always includes primary keys.
	 */
	public function test_hook_db_install_tables_always_includes_primary_keys() {
		$sql = MainWP_DB_Assistant::instance()->hook_db_install_tables( array(), '9.0.1.4', '' );

		$this->assertCount( 3, $sql );
		$this->assertStringContainsString( 'PRIMARY KEY (id)', $sql[0] );
		$this->assertStringContainsString( 'PRIMARY KEY (id)', $sql[1] );
		$this->assertStringContainsString( 'PRIMARY KEY (spend_day, user_id)', $sql[2] );
	}

	/**
	 * Test assistant components bootstrap DB hooks during normal init.
	 */
	public function test_init_assistant_components_bootstraps_db_hooks() {
		$reflection = new \ReflectionClass( MainWP_DB_Assistant::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );

		remove_all_filters( 'mainwp_db_install_tables' );
		remove_all_actions( 'mainwp_db_after_update' );
		$property->setValue( null, null );

		$system = MainWP_System::instance();
		$this->assertInstanceOf( MainWP_System::class, $system );

		$system->init_assistant_components();

		$assistant_db = MainWP_DB_Assistant::instance();
		$this->assertInstanceOf( MainWP_DB_Assistant::class, $assistant_db );
		$this->assertNotFalse( has_filter( 'mainwp_db_install_tables', array( $assistant_db, 'hook_db_install_tables' ) ) );
		$this->assertNotFalse( has_action( 'mainwp_db_after_update', array( $assistant_db, 'hook_db_after_update' ) ) );
	}

	// =========================================================================
	// CONVERSATION RETRIEVAL TESTS
	// =========================================================================

	/**
	 * Test getting a conversation by ID.
	 */
	public function test_get_conversation_returns_object() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Test Get' );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertIsObject( $conversation );
		$this->assertEquals( $conversation_id, $conversation->id );
		$this->assertEquals( $user_id, $conversation->user_id );
		$this->assertEquals( 'Test Get', $conversation->title );
	}

	/**
	 * Test getting a non-existent conversation returns null.
	 */
	public function test_get_conversation_returns_null_for_nonexistent() {
		$user_id = $this->set_current_user_as_admin();
		$result  = MainWP_DB_Assistant::instance()->get_conversation( 999999, $user_id );

		$this->assertNull( $result );
	}

	/**
	 * Test get_conversation with invalid ID returns null.
	 */
	public function test_get_conversation_returns_null_for_invalid_id() {
		$user_id = $this->set_current_user_as_admin();
		$result  = MainWP_DB_Assistant::instance()->get_conversation( 0, $user_id );

		$this->assertNull( $result );
	}

	/**
	 * Test get_conversation requires user context.
	 */
	public function test_get_conversation_requires_user_context() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Requires User' );
		$this->set_current_user_as_subscriber();
		$result          = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id );

		$this->assertWPErrorWithCode( $result, 'missing_user' );
	}

	/**
	 * Test get_conversation denies cross-user access.
	 */
	public function test_get_conversation_denies_cross_user_access() {
		// User A creates a conversation.
		$user_a          = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_a, 'User A Conversation' );

		// User B tries to access it.
		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$result = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_b );

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test get_conversation allows owner access.
	 */
	public function test_get_conversation_allows_owner_access() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'My Conversation' );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertNotWPError( $conversation );
		$this->assertEquals( $user_id, $conversation->user_id );
	}

	// =========================================================================
	// USER CONVERSATIONS LIST TESTS
	// =========================================================================

	/**
	 * Test getting user conversations with pagination.
	 */
	public function test_get_user_conversations_returns_paginated_result() {
		$user_id = $this->set_current_user_as_admin();

		// Create 5 conversations.
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_test_conversation( $user_id, "Conversation $i" );
		}

		$result = MainWP_DB_Assistant::instance()->get_user_conversations( $user_id, 1, 3 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'conversations', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'total_pages', $result );

		$this->assertCount( 3, $result['conversations'] );
		$this->assertEquals( 5, $result['total'] );
		$this->assertEquals( 1, $result['page'] );
		$this->assertEquals( 3, $result['per_page'] );
		$this->assertEquals( 2, $result['total_pages'] );
	}

	/**
	 * Test get_user_conversations includes message_count.
	 */
	public function test_get_user_conversations_includes_message_count() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Test Count' );

		// Add some messages.
		$this->add_test_message( $conversation_id, 'user', 'Hello' );
		$this->add_test_message( $conversation_id, 'assistant', 'Hi there!' );
		$this->add_test_message( $conversation_id, 'user', 'How are you?' );

		$result        = MainWP_DB_Assistant::instance()->get_user_conversations( $user_id );
		$conversations = $result['conversations'];

		$this->assertCount( 1, $conversations );
		$this->assertEquals( 3, $conversations[0]->message_count );
	}

	/**
	 * Test get_user_conversations returns only user's conversations.
	 */
	public function test_get_user_conversations_scoped_to_user() {
		$user_a = $this->set_current_user_as_admin();
		$this->create_test_conversation( $user_a, 'User A Conv' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );
		$this->create_test_conversation( $user_b, 'User B Conv' );

		// Get User A's conversations.
		$result_a = MainWP_DB_Assistant::instance()->get_user_conversations( $user_a );
		$this->assertEquals( 1, $result_a['total'] );
		$this->assertEquals( 'User A Conv', $result_a['conversations'][0]->title );

		// Get User B's conversations.
		$result_b = MainWP_DB_Assistant::instance()->get_user_conversations( $user_b );
		$this->assertEquals( 1, $result_b['total'] );
		$this->assertEquals( 'User B Conv', $result_b['conversations'][0]->title );
	}

	/**
	 * Test get_user_conversations uses a deterministic secondary sort key.
	 */
	public function test_get_user_conversations_uses_id_tiebreaker_for_equal_timestamps() {
		$user_id = $this->set_current_user_as_admin();

		$first_id  = $this->create_test_conversation( $user_id, 'First Created' );
		$second_id = $this->create_test_conversation( $user_id, 'Second Created' );
		$third_id  = $this->create_test_conversation( $user_id, 'Third Created' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'mainwp_assistant_conversations',
			array( 'updated_at' => 1700000000 ),
			array( 'id' => $first_id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update(
			$wpdb->prefix . 'mainwp_assistant_conversations',
			array( 'updated_at' => 1700000000 ),
			array( 'id' => $second_id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update(
			$wpdb->prefix . 'mainwp_assistant_conversations',
			array( 'updated_at' => 1700000000 ),
			array( 'id' => $third_id ),
			array( '%d' ),
			array( '%d' )
		);

		$result = MainWP_DB_Assistant::instance()->get_user_conversations( $user_id, 1, 3 );

		$this->assertSame( array( $third_id, $second_id, $first_id ), array_map( 'intval', wp_list_pluck( $result['conversations'], 'id' ) ) );
	}

	/**
	 * Test get_conversation_count returns correct count.
	 */
	public function test_get_conversation_count() {
		$user_id = $this->set_current_user_as_admin();

		$this->assertEquals( 0, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );

		$this->create_test_conversation( $user_id, 'Conv 1' );
		$this->create_test_conversation( $user_id, 'Conv 2' );

		$this->assertEquals( 2, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );
	}

	// =========================================================================
	// CONVERSATION UPDATE TESTS
	// =========================================================================

	/**
	 * Test updating a conversation title.
	 */
	public function test_update_conversation_title() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Original Title' );

		$result = MainWP_DB_Assistant::instance()->update_conversation(
			$conversation_id,
			$user_id,
			array( 'title' => 'Updated Title' )
		);

		$this->assertTrue( $result );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );
		$this->assertEquals( 'Updated Title', $conversation->title );
	}

	/**
	 * Test update_conversation clamps long titles.
	 */
	public function test_update_conversation_clamps_long_title() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Short Title' );
		$title           = str_repeat( 'B', 300 );

		$result = MainWP_DB_Assistant::instance()->update_conversation(
			$conversation_id,
			$user_id,
			array( 'title' => $title )
		);

		$this->assertTrue( $result );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );
		$this->assertSame( 255, strlen( $conversation->title ) );
		$this->assertSame( str_repeat( 'B', 255 ), $conversation->title );
	}

	/**
	 * Test updating a conversation updates the updated_at timestamp.
	 */
	public function test_update_conversation_updates_timestamp() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Test' );

		$before = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		// Wait a bit to ensure timestamp changes.
		sleep( 1 );

		MainWP_DB_Assistant::instance()->update_conversation(
			$conversation_id,
			$user_id,
			array( 'title' => 'Updated' )
		);

		$after = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertGreaterThan( $before->updated_at, $after->updated_at );
	}

	/**
	 * Test update_conversation denies cross-user access.
	 */
	public function test_update_conversation_denies_cross_user_access() {
		$user_a          = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_a, 'User A Conv' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$result = MainWP_DB_Assistant::instance()->update_conversation(
			$conversation_id,
			$user_b,
			array( 'title' => 'Hacked' )
		);

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test update_conversation fails for non-existent conversation.
	 */
	public function test_update_conversation_fails_for_nonexistent() {
		$user_id = $this->set_current_user_as_admin();

		$result = MainWP_DB_Assistant::instance()->update_conversation(
			999999,
			$user_id,
			array( 'title' => 'Test' )
		);

		$this->assertWPErrorWithCode( $result, 'not_found' );
	}

	// =========================================================================
	// CONVERSATION DELETE TESTS
	// =========================================================================

	/**
	 * Test deleting a conversation.
	 */
	public function test_delete_conversation() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'To Delete' );

		$result = MainWP_DB_Assistant::instance()->delete_conversation( $conversation_id, $user_id );

		$this->assertTrue( $result );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );
		$this->assertNull( $conversation );
	}

	/**
	 * Test deleting a conversation also deletes its messages.
	 */
	public function test_delete_conversation_cascades_to_messages() {
		global $wpdb;

		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Test Delete' );

		// Add messages.
		$this->add_test_message( $conversation_id, 'user', 'Hello' );
		$this->add_test_message( $conversation_id, 'assistant', 'Hi' );

		// Verify messages exist.
		$count_before = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_assistant_messages WHERE conversation_id = %d",
				$conversation_id
			)
		);
		$this->assertEquals( 2, $count_before );

		// Delete conversation.
		MainWP_DB_Assistant::instance()->delete_conversation( $conversation_id, $user_id );

		// Verify messages are deleted.
		$count_after = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_assistant_messages WHERE conversation_id = %d",
				$conversation_id
			)
		);
		$this->assertEquals( 0, $count_after );
	}

	/**
	 * Test delete_conversation denies cross-user access.
	 */
	public function test_delete_conversation_denies_cross_user_access() {
		$user_a          = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_a, 'User A Conv' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$result = MainWP_DB_Assistant::instance()->delete_conversation( $conversation_id, $user_b );

		$this->assertWPErrorWithCode( $result, 'access_denied' );

		// Verify conversation still exists.
		wp_set_current_user( $user_a );
		$conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_a );
		$this->assertNotNull( $conversation );
	}

	/**
	 * Test delete_user_conversations deletes all conversations for a user.
	 */
	public function test_delete_user_conversations() {
		$user_id = $this->set_current_user_as_admin();

		$this->create_test_conversation( $user_id, 'Conv 1' );
		$this->create_test_conversation( $user_id, 'Conv 2' );
		$this->create_test_conversation( $user_id, 'Conv 3' );

		$this->assertEquals( 3, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );

		$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );

		$this->assertEquals( 3, $deleted );
		$this->assertEquals( 0, MainWP_DB_Assistant::instance()->get_conversation_count( $user_id ) );
	}

	/**
	 * Test delete_all_conversations requires administrator capability.
	 */
	public function test_delete_all_conversations_requires_manage_options() {
		$admin_id = $this->set_current_user_as_admin();
		$this->create_test_conversation( $admin_id, 'Conv 1' );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = MainWP_DB_Assistant::instance()->delete_all_conversations();

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test delete_user_conversations denies deleting another user's history without admin rights.
	 */
	public function test_delete_user_conversations_denies_cross_user_access() {
		$user_a = $this->set_current_user_as_admin();
		$this->create_test_conversation( $user_a, 'Conv 1' );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_a );

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test delete_user_conversations allows an administrator to delete another user's history.
	 */
	public function test_delete_user_conversations_allows_admin_to_delete_other_user() {
		$user_a = $this->set_current_user_as_admin();
		$this->create_test_conversation( $user_a, 'Conv 1' );
		$this->create_test_conversation( $user_a, 'Conv 2' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_a );

		$this->assertEquals( 2, $deleted );
		wp_set_current_user( $user_a );
		$this->assertEquals( 0, MainWP_DB_Assistant::instance()->get_conversation_count( $user_a ) );
	}

	// =========================================================================
	// MESSAGE CRUD TESTS
	// =========================================================================

	/**
	 * Message deletion is scoped to the owning conversation.
	 */
	public function test_delete_message_owner_and_unknown_id() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db              = MainWP_DB_Assistant::instance();
		$message_id      = $db->add_message( $conversation_id, 'user', 'Hello', $user_id );
		$this->assertTrue( $db->delete_message( $conversation_id, $message_id, $user_id ) );
		$this->assertCount( 0, $db->get_messages( $conversation_id, $user_id ) );
		$this->assertWPErrorWithCode( $db->delete_message( $conversation_id, $message_id, $user_id ), 'not_found' );
	}

	/**
	 * Another administrator cannot delete an owner's message.
	 */
	public function test_delete_message_denies_non_owner() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db              = MainWP_DB_Assistant::instance();
		$message_id      = $db->add_message( $conversation_id, 'user', 'Hello', $user_id );
		$other_id        = $this->create_other_admin();
		wp_set_current_user( $other_id );
		$this->assertWPErrorWithCode( $db->delete_message( $conversation_id, $message_id, $other_id ), 'access_denied' );
		wp_set_current_user( $user_id );
		$this->assertCount( 1, $db->get_messages( $conversation_id, $user_id ) );
	}

	/**
	 * Test adding a message to a conversation.
	 */
	public function test_add_message_returns_id() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$message_id = $this->add_test_message( $conversation_id, 'user', 'Hello world!' );

		$this->assertIsInt( $message_id );
		$this->assertGreaterThan( 0, $message_id );
	}

	/**
	 * Test adding messages with different roles.
	 */
	public function test_add_message_with_valid_roles() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$user_msg_id      = $this->add_test_message( $conversation_id, 'user', 'User message' );
		$assistant_msg_id = $this->add_test_message( $conversation_id, 'assistant', 'Assistant message' );
		$system_msg_id    = $this->add_test_message( $conversation_id, 'system', 'System message' );

		$this->assertIsInt( $user_msg_id );
		$this->assertIsInt( $assistant_msg_id );
		$this->assertIsInt( $system_msg_id );
	}

	/**
	 * Test add_message persists per-message metadata (docs answer sources).
	 */
	public function test_add_message_persists_metadata() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$sources = array(
			array(
				'title' => 'Test Doc',
				'url'   => 'https://kb.mainwp.com/test',
			),
		);

		$message_id = MainWP_DB_Assistant::instance()->add_message(
			$conversation_id,
			'assistant',
			'Answer with sources.',
			$user_id,
			array( 'sources' => $sources )
		);

		$this->assertIsInt( $message_id );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 10 );
		$this->assertCount( 1, $messages );

		$decoded = json_decode( $messages[0]->metadata, true );
		$this->assertIsArray( $decoded, 'Metadata should round-trip as JSON.' );
		$this->assertSame( $sources, $decoded['sources'] );

		// Messages saved without metadata keep an empty column value.
		$plain_id = $this->add_test_message( $conversation_id, 'user', 'No metadata' );
		$this->assertIsInt( $plain_id );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 10 );
		$this->assertSame( '', $messages[1]->metadata );
	}

	/**
	 * Test add_message fails with invalid role.
	 */
	public function test_add_message_fails_with_invalid_role() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$result = MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'invalid_role', 'Test', $user_id );

		$this->assertWPErrorWithCode( $result, 'invalid_role' );
	}

	/**
	 * Test add_message requires user context.
	 */
	public function test_add_message_requires_user_context() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$result          = MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'user', 'Hello' );

		$this->assertWPErrorWithCode( $result, 'missing_user' );
	}

	/**
	 * Test add_message with user_id verification denies cross-user access.
	 */
	public function test_add_message_denies_cross_user_access() {
		$user_a          = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_a, 'User A Conv' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$result = MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'user', 'Hacked', $user_b );

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test adding a message updates conversation's updated_at timestamp.
	 */
	public function test_add_message_updates_conversation_timestamp() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$before = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		sleep( 1 );

		$this->add_test_message( $conversation_id, 'user', 'Hello' );

		$after = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );

		$this->assertGreaterThan( $before->updated_at, $after->updated_at );
	}

	/**
	 * Test add_message still succeeds when the parent conversation update fails.
	 */
	public function test_add_message_returns_insert_id_when_timestamp_update_fails() {
		$db   = new Testable_DB_Assistant_Failure();
		$wpdb = new Test_DB_Assistant_Failure_Wpdb_Fake();
		$wpdb->update_result = false;
		$db->set_wpdb( $wpdb );

		$result = $db->add_message( 1, 'user', 'Hello', 1 );

		$this->assertSame( 321, $result );
	}

	// =========================================================================
	// MESSAGE RETRIEVAL TESTS
	// =========================================================================

	/**
	 * Test getting messages from a conversation.
	 */
	public function test_get_messages_returns_array() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$this->add_test_message( $conversation_id, 'user', 'Hello' );
		$this->add_test_message( $conversation_id, 'assistant', 'Hi there!' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );

		$this->assertIsArray( $messages );
		$this->assertCount( 2, $messages );
	}

	/**
	 * Test privileged admin reads use the explicit admin entrypoint.
	 */
	public function test_get_conversation_as_admin_allows_privileged_read() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Admin Read Conversation' );

		$conversation = MainWP_DB_Assistant::instance()->get_conversation_as_admin( $conversation_id );

		$this->assertNotWPError( $conversation );
		$this->assertSame( $conversation_id, (int) $conversation->id );
	}

	/**
	 * Test privileged admin reads use the explicit admin message entrypoint.
	 */
	public function test_get_messages_as_admin_allows_privileged_read() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Admin Read Messages' );

		$this->add_test_message( $conversation_id, 'user', 'Hello from admin-safe read' );

		$messages = MainWP_DB_Assistant::instance()->get_messages_as_admin( $conversation_id );

		$this->assertIsArray( $messages );
		$this->assertCount( 1, $messages );
	}

	/**
	 * Test get_messages respects order parameter.
	 */
	public function test_get_messages_order() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		$this->add_test_message( $conversation_id, 'user', 'First' );
		sleep( 1 );
		$this->add_test_message( $conversation_id, 'user', 'Second' );

		// ASC order (default).
		$messages_asc = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 50, 0, 'ASC' );
		$this->assertEquals( 'First', $messages_asc[0]->content );
		$this->assertEquals( 'Second', $messages_asc[1]->content );

		// DESC order.
		$messages_desc = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 50, 0, 'DESC' );
		$this->assertEquals( 'Second', $messages_desc[0]->content );
		$this->assertEquals( 'First', $messages_desc[1]->content );
	}

	/**
	 * Test get_messages with limit and offset.
	 */
	public function test_get_messages_pagination() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->add_test_message( $conversation_id, 'user', "Message $i" );
		}

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 3, 5 );

		$this->assertCount( 3, $messages );
		$this->assertEquals( 'Message 6', $messages[0]->content );
	}

	/**
	 * Test get_messages denies cross-user access.
	 */
	public function test_get_messages_denies_cross_user_access() {
		$user_a          = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_a, 'User A Conv' );
		$this->add_test_message( $conversation_id, 'user', 'Secret' );

		$user_b = $this->create_other_admin();
		wp_set_current_user( $user_b );

		$result = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_b );

		$this->assertWPErrorWithCode( $result, 'access_denied' );
	}

	/**
	 * Test get_messages requires user context.
	 */
	public function test_get_messages_requires_user_context() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$this->set_current_user_as_subscriber();
		$result          = MainWP_DB_Assistant::instance()->get_messages( $conversation_id );

		$this->assertWPErrorWithCode( $result, 'missing_user' );
	}

	// =========================================================================
	// MESSAGE DELETION TESTS
	// =========================================================================

	/**
	 * Test deleting old messages.
	 */
	public function test_delete_old_messages() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );

		// Add messages.
		$this->add_test_message( $conversation_id, 'user', 'Old message' );
		sleep( 2 );
		$cutoff = time();
		sleep( 1 );
		$this->add_test_message( $conversation_id, 'user', 'New message' );

		$deleted = MainWP_DB_Assistant::instance()->delete_old_messages( $conversation_id, $cutoff, $user_id );

		$this->assertEquals( 1, $deleted );

		$remaining = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id );
		$this->assertCount( 1, $remaining );
		$this->assertEquals( 'New message', $remaining[0]->content );
	}

	/**
	 * Test delete_old_messages preserves query failures as WP_Error.
	 */
	public function test_delete_old_messages_returns_error_on_query_failure() {
		$db   = new Testable_DB_Assistant_Failure();
		$wpdb = new Test_DB_Assistant_Failure_Wpdb_Fake();
		$wpdb->query_result = false;
		$wpdb->last_error   = 'Simulated delete failure';
		$db->set_wpdb( $wpdb );

		$result = $db->delete_old_messages( 1, time(), 1 );

		$this->assertWPErrorWithCode( $result, 'db_error' );
		$this->assertSame( 'Simulated delete failure', $result->get_error_data()['db_error'] );
	}

	/**
	 * Test delete_old_messages requires user context.
	 */
	public function test_delete_old_messages_requires_user_context() {
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$result          = MainWP_DB_Assistant::instance()->delete_old_messages( $conversation_id, time() );

		$this->assertWPErrorWithCode( $result, 'missing_user' );
	}

	// =========================================================================
	// CLEANUP TESTS
	// =========================================================================

	/**
	 * Test cleanup_old_conversations.
	 */
	public function test_cleanup_old_conversations() {
		$user_id = $this->set_current_user_as_admin();

		// Create conversations with old timestamps.
		global $wpdb;
		$old_time = time() - ( 40 * DAY_IN_SECONDS );
		$new_time = time();

		// Insert old conversation directly.
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_assistant_conversations',
			array(
				'user_id'    => $user_id,
				'title'      => 'Test Old Conv',
				'created_at' => $old_time,
				'updated_at' => $old_time,
				'metadata'   => '{}',
			)
		);
		$this->created_conversation_ids[] = $wpdb->insert_id;

		// Create new conversation.
		$this->create_test_conversation( $user_id, 'Test New Conv' );

		$deleted = MainWP_DB_Assistant::instance()->cleanup_old_conversations( 30, $user_id );

		$this->assertEquals( 1, $deleted );

		$remaining = MainWP_DB_Assistant::instance()->get_user_conversations( $user_id );
		$this->assertEquals( 1, $remaining['total'] );
		$this->assertEquals( 'Test New Conv', $remaining['conversations'][0]->title );
	}

	/**
	 * Test cleanup_old_conversations requires user context.
	 */
	public function test_cleanup_old_conversations_requires_user_context() {
		$result = MainWP_DB_Assistant::instance()->cleanup_old_conversations( 30 );

		$this->assertWPErrorWithCode( $result, 'missing_user' );
	}

	/**
	 * Test clearing history scrubs prompt-derived titles from the activity log.
	 *
	 * Conversation-start log rows carry the first prompt characters in their
	 * item text and title meta; deleting the conversations must scrub those
	 * logged copies for the same user while leaving other users' rows alone.
	 */
	public function test_delete_user_conversations_scrubs_logged_conversation_titles() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$logs_table = $wpdb->prefix . 'mainwp_wp_logs';
		$meta_table = $wpdb->prefix . 'mainwp_wp_logs_meta';

		// The logs module is disabled under phpunit, so build the minimal
		// table shape. DDL auto-commits and escapes the test transaction; the
		// finally block drops the tables again.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture DDL and assertions.
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		$wpdb->query( "CREATE TABLE {$logs_table} ( log_id bigint(20) NOT NULL auto_increment, user_id int(11) unsigned NOT NULL DEFAULT 0, item varchar(256) NOT NULL DEFAULT '', action varchar(100) NOT NULL, connector varchar(100) NOT NULL, PRIMARY KEY (log_id) )" );
		$wpdb->query( "CREATE TABLE {$meta_table} ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, meta_log_id bigint(20) unsigned NOT NULL, meta_key varchar(200) NOT NULL, meta_value mediumtext NOT NULL, PRIMARY KEY (meta_id) )" );

		try {
			$this->create_test_conversation( $user_id, 'Secret prompt title' );

			$wpdb->insert(
				$logs_table,
				array(
					'user_id'   => $user_id,
					'item'      => 'Started conversation: Secret prompt title',
					'action'    => 'started',
					'connector' => 'assistant',
				)
			);
			$mine = (int) $wpdb->insert_id;
			$wpdb->insert(
				$meta_table,
				array(
					'meta_log_id' => $mine,
					'meta_key'    => 'title',
					'meta_value'  => 'Secret prompt title',
				)
			);

			$wpdb->insert(
				$logs_table,
				array(
					'user_id'   => $user_id + 999,
					'item'      => 'Started conversation: Other user title',
					'action'    => 'started',
					'connector' => 'assistant',
				)
			);
			$other = (int) $wpdb->insert_id;
			$wpdb->insert(
				$meta_table,
				array(
					'meta_log_id' => $other,
					'meta_key'    => 'title',
					'meta_value'  => 'Other user title',
				)
			);

			$wpdb->insert(
				$logs_table,
				array(
					'user_id'   => $user_id,
					'item'      => 'Executed ability: List Sites',
					'action'    => 'confirmed',
					'connector' => 'assistant',
				)
			);
			$executed = (int) $wpdb->insert_id;

			$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );
			$this->assertFalse( is_wp_error( $deleted ), 'Clearing history must succeed.' );

			$this->assertSame(
				'Started conversation',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $mine ) ),
				'The logged item text must no longer carry the prompt-derived title.'
			);
			$this->assertNull(
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_log_id = %d AND meta_key = 'title'", $mine ) ),
				'The title meta row must be removed on clear.'
			);
			$this->assertSame(
				'Started conversation: Other user title',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $other ) ),
				'Another user\'s logged title must survive.'
			);
			$this->assertSame(
				'Other user title',
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_log_id = %d AND meta_key = 'title'", $other ) ),
				'Another user\'s title meta must survive.'
			);
			$this->assertSame(
				'Executed ability: List Sites',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $executed ) ),
				'Non-start rows must not be touched.'
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Test deleting one conversation scrubs only that conversation's log title.
	 *
	 * Deleting a thread promises its prompt is gone, and the conversation-start
	 * log row keeps the first prompt characters as its title. The scrub is
	 * scoped through the row's conversation_id meta so the threads the user
	 * kept keep their titles.
	 */
	public function test_delete_conversation_scrubs_only_that_conversations_log_title() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$logs_table = $wpdb->prefix . 'mainwp_wp_logs';
		$meta_table = $wpdb->prefix . 'mainwp_wp_logs_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture DDL and assertions.
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		$wpdb->query( "CREATE TABLE {$logs_table} ( log_id bigint(20) NOT NULL auto_increment, user_id int(11) unsigned NOT NULL DEFAULT 0, item varchar(256) NOT NULL DEFAULT '', action varchar(100) NOT NULL, connector varchar(100) NOT NULL, PRIMARY KEY (log_id) )" );
		$wpdb->query( "CREATE TABLE {$meta_table} ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, meta_log_id bigint(20) unsigned NOT NULL, meta_key varchar(200) NOT NULL, meta_value mediumtext NOT NULL, PRIMARY KEY (meta_id) )" );

		try {
			$doomed = $this->create_test_conversation( $user_id, 'Doomed prompt title' );
			$kept   = $this->create_test_conversation( $user_id, 'Kept prompt title' );

			$log_ids = array();
			foreach ( array( $doomed, $kept ) as $conversation_id ) {
				$wpdb->insert(
					$logs_table,
					array(
						'user_id'   => $user_id,
						'item'      => 'Started conversation: Title for ' . $conversation_id,
						'action'    => 'started',
						'connector' => 'assistant',
					)
				);
				$log_ids[ $conversation_id ] = (int) $wpdb->insert_id;

				$wpdb->insert(
					$meta_table,
					array(
						'meta_log_id' => $log_ids[ $conversation_id ],
						'meta_key'    => 'conversation_id',
						'meta_value'  => (string) $conversation_id,
					)
				);
				$wpdb->insert(
					$meta_table,
					array(
						'meta_log_id' => $log_ids[ $conversation_id ],
						'meta_key'    => 'title',
						'meta_value'  => 'Title for ' . $conversation_id,
					)
				);
			}

			$this->assertTrue( MainWP_DB_Assistant::instance()->delete_conversation( $doomed, $user_id ) );

			$this->assertSame(
				'Started conversation',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $log_ids[ $doomed ] ) ),
				'The deleted conversation must lose its logged title.'
			);
			$this->assertNull(
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_log_id = %d AND meta_key = 'title'", $log_ids[ $doomed ] ) ),
				'The deleted conversation must lose its title meta.'
			);

			$this->assertSame(
				'Started conversation: Title for ' . $kept,
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $log_ids[ $kept ] ) ),
				'A conversation the user kept must keep its logged title.'
			);
			$this->assertSame(
				'Title for ' . $kept,
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_log_id = %d AND meta_key = 'title'", $log_ids[ $kept ] ) ),
				'A conversation the user kept must keep its title meta.'
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Test retention scrubs the log titles of the conversations it expires.
	 */
	public function test_cleanup_old_conversations_scrubs_expired_log_titles() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$logs_table = $wpdb->prefix . 'mainwp_wp_logs';
		$meta_table = $wpdb->prefix . 'mainwp_wp_logs_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture DDL and assertions.
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		$wpdb->query( "CREATE TABLE {$logs_table} ( log_id bigint(20) NOT NULL auto_increment, user_id int(11) unsigned NOT NULL DEFAULT 0, item varchar(256) NOT NULL DEFAULT '', action varchar(100) NOT NULL, connector varchar(100) NOT NULL, PRIMARY KEY (log_id) )" );
		$wpdb->query( "CREATE TABLE {$meta_table} ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, meta_log_id bigint(20) unsigned NOT NULL, meta_key varchar(200) NOT NULL, meta_value mediumtext NOT NULL, PRIMARY KEY (meta_id) )" );

		try {
			$old_time = time() - ( 40 * DAY_IN_SECONDS );
			$wpdb->insert(
				$wpdb->prefix . 'mainwp_assistant_conversations',
				array(
					'user_id'    => $user_id,
					'title'      => 'Expiring prompt title',
					'created_at' => $old_time,
					'updated_at' => $old_time,
					'metadata'   => '{}',
				)
			);
			$expiring                         = (int) $wpdb->insert_id;
			$this->created_conversation_ids[] = $expiring;

			$fresh = $this->create_test_conversation( $user_id, 'Fresh prompt title' );

			$log_ids = array();
			foreach ( array( $expiring, $fresh ) as $conversation_id ) {
				$wpdb->insert(
					$logs_table,
					array(
						'user_id'   => $user_id,
						'item'      => 'Started conversation: Title for ' . $conversation_id,
						'action'    => 'started',
						'connector' => 'assistant',
					)
				);
				$log_ids[ $conversation_id ] = (int) $wpdb->insert_id;

				$wpdb->insert(
					$meta_table,
					array(
						'meta_log_id' => $log_ids[ $conversation_id ],
						'meta_key'    => 'conversation_id',
						'meta_value'  => (string) $conversation_id,
					)
				);
			}

			$this->assertSame( 1, MainWP_DB_Assistant::instance()->cleanup_old_conversations( 30, $user_id ) );

			$this->assertSame(
				'Started conversation',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $log_ids[ $expiring ] ) ),
				'An expired conversation must lose its logged title.'
			);
			$this->assertSame(
				'Started conversation: Title for ' . $fresh,
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $log_ids[ $fresh ] ) ),
				'A conversation still inside the retention window keeps its logged title.'
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Test a cleanup run with nothing expired leaves logged titles alone.
	 *
	 * scrub_conversation_log_titles() treats an empty conversation id list as
	 * "scrub every title for this user", so cleanup must skip the call
	 * entirely when nothing aged out instead of passing an empty array.
	 */
	public function test_cleanup_old_conversations_leaves_log_titles_when_nothing_expired() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$logs_table = $wpdb->prefix . 'mainwp_wp_logs';
		$meta_table = $wpdb->prefix . 'mainwp_wp_logs_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture DDL and assertions.
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		$wpdb->query( "CREATE TABLE {$logs_table} ( log_id bigint(20) NOT NULL auto_increment, user_id int(11) unsigned NOT NULL DEFAULT 0, item varchar(256) NOT NULL DEFAULT '', action varchar(100) NOT NULL, connector varchar(100) NOT NULL, PRIMARY KEY (log_id) )" );
		$wpdb->query( "CREATE TABLE {$meta_table} ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, meta_log_id bigint(20) unsigned NOT NULL, meta_key varchar(200) NOT NULL, meta_value mediumtext NOT NULL, PRIMARY KEY (meta_id) )" );

		try {
			$fresh = $this->create_test_conversation( $user_id, 'Fresh prompt title' );

			$wpdb->insert(
				$logs_table,
				array(
					'user_id'   => $user_id,
					'item'      => 'Started conversation: Title for ' . $fresh,
					'action'    => 'started',
					'connector' => 'assistant',
				)
			);
			$log_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$meta_table,
				array(
					'meta_log_id' => $log_id,
					'meta_key'    => 'conversation_id',
					'meta_value'  => (string) $fresh,
				)
			);

			$this->assertSame( 0, MainWP_DB_Assistant::instance()->cleanup_old_conversations( 30, $user_id ) );

			$this->assertSame(
				'Started conversation: Title for ' . $fresh,
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $log_id ) ),
				'A cleanup run with nothing expired must not touch a kept conversation\'s logged title.'
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$meta_table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Test retention sweeps messages whose conversation row is gone.
	 *
	 * Historical orphans (written before the insert became conditional, or
	 * left by a half-failed delete) are unreachable through every reader
	 * while still holding the conversation text, so retention removes them.
	 */
	public function test_cleanup_old_conversations_sweeps_orphaned_messages() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$messages_table = $wpdb->prefix . 'mainwp_assistant_messages';

		// The sweep is rate-limited to once a day; clear the marker so this
		// run is not skipped because an earlier test already swept.
		delete_option( \MainWP\Dashboard\MainWP_DB_Assistant::OPTION_ORPHAN_SWEEP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Orphan fixture and assertion.
		$wpdb->insert(
			$messages_table,
			array(
				'conversation_id' => 987654,
				'role'            => 'user',
				'content'         => 'Orphaned prompt text',
				'created_at'      => time(),
				'metadata'        => '',
			)
		);
		$orphan_id = (int) $wpdb->insert_id;

		$conversation_id = $this->create_test_conversation( $user_id, 'Live Conversation' );
		MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'user', 'Live prompt text', $user_id );

		MainWP_DB_Assistant::instance()->cleanup_old_conversations( 30, $user_id );

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$messages_table} WHERE id = %d", $orphan_id ) ),
			'The orphaned message must be swept.'
		);
		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages_table} WHERE conversation_id = %d", $conversation_id ) ),
			'A live conversation must keep its messages.'
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Test a conversation deleted between the ownership check and the insert leaves no message.
	 *
	 * The check and the write used to be two round trips, so a delete landing
	 * between them left the message behind with nothing to reach it through.
	 * The query filter deletes the conversation right before the insert runs,
	 * which is exactly that window.
	 */
	public function test_add_message_writes_nothing_when_the_conversation_disappears() {
		global $wpdb;
		$user_id         = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id, 'Vanishing Conversation' );

		$conversations_table = $wpdb->prefix . 'mainwp_assistant_conversations';
		$messages_table      = $wpdb->prefix . 'mainwp_assistant_messages';

		$fired = false;
		$race  = function ( $query ) use ( &$fired, $conversation_id, $conversations_table, $wpdb ) {
			if ( ! $fired && 0 === strpos( ltrim( $query ), 'INSERT INTO' ) && false !== strpos( $query, 'mainwp_assistant_messages' ) ) {
				$fired = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Simulates the concurrent delete.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$conversations_table} WHERE id = %d", $conversation_id ) );
			}
			return $query;
		};

		add_filter( 'query', $race );
		try {
			$result = MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'user', 'Racing prompt text', $user_id );
		} finally {
			remove_filter( 'query', $race );
		}

		$this->assertTrue( $fired, 'The simulated delete must land in the insert window.' );
		$this->assertWPErrorWithCode( $result, 'access_denied' );
		$this->assertSame(
			0,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Orphan assertion.
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$messages_table} WHERE conversation_id = %d", $conversation_id ) ),
			'A message must never outlive the conversation row it belongs to.'
		);
	}

	/**
	 * Test clearing history still succeeds when the logs tables do not exist.
	 */
	public function test_delete_user_conversations_survives_missing_log_tables() {
		$user_id = $this->set_current_user_as_admin();
		$this->create_test_conversation( $user_id, 'No logs installed' );

		$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );

		$this->assertFalse( is_wp_error( $deleted ), 'The scrub must be best-effort when the logs module was never enabled.' );
		$this->assertSame( 1, (int) $deleted );
	}

	/**
	 * Test the scrub skips a legacy logs table that lacks the connector column
	 * instead of erroring — seen live on the archive pair of older installs.
	 */
	public function test_delete_user_conversations_skips_logs_table_without_connector_column() {
		global $wpdb;
		$user_id = $this->set_current_user_as_admin();

		$logs_table = $wpdb->prefix . 'mainwp_wp_logs';

		// A pre-connector schema: no connector column, so the scrub's shape
		// probe must reject it and the UPDATE must never run.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixture DDL and assertions.
		$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		$wpdb->query( "CREATE TABLE {$logs_table} ( log_id bigint(20) NOT NULL auto_increment, user_id int(11) unsigned NOT NULL DEFAULT 0, item varchar(256) NOT NULL DEFAULT '', action varchar(100) NOT NULL, PRIMARY KEY (log_id) )" );

		try {
			$this->create_test_conversation( $user_id, 'Legacy logs schema' );
			$wpdb->insert(
				$logs_table,
				array(
					'user_id' => $user_id,
					'item'    => 'Started conversation: Secret prompt title',
					'action'  => 'started',
				)
			);
			$row_id = (int) $wpdb->insert_id;

			$before   = count( $wpdb->get_results( "SHOW COLUMNS FROM {$logs_table}" ) );
			$suppress = $wpdb->suppress_errors;

			$deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );

			$this->assertFalse( is_wp_error( $deleted ), 'A legacy logs schema must not break history clear.' );
			$this->assertSame(
				$suppress,
				$wpdb->suppress_errors,
				'suppress_errors must be restored to its prior value after the scrub.'
			);
			$this->assertSame(
				'Started conversation: Secret prompt title',
				$wpdb->get_var( $wpdb->prepare( "SELECT item FROM {$logs_table} WHERE log_id = %d", $row_id ) ),
				'The scrub must leave a connector-less table untouched rather than erroring.'
			);
			$this->assertSame( $before, count( $wpdb->get_results( "SHOW COLUMNS FROM {$logs_table}" ) ), 'The table shape must be unchanged.' );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
