<?php
/**
 * Tests for the confirmation state-owner transitions.
 *
 * The confirm/cancel state machine's transitions (arm, peek, claim, consume,
 * close) live in MainWP_Assistant_Confirmation_State. These tests pin each
 * transition at the seam itself; the end-to-end invariants stay pinned by the
 * handler tests in test-assistant-chat.php (CONFIRMATION-FLOW.md §3).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Assistant;
use MainWP\Dashboard\MainWP_Assistant_Confirmation_State;
use MainWP\Dashboard\MainWP_Credential_Storage;
use MainWP\Dashboard\MainWP_DB_Assistant;

/**
 * Test class for MainWP_Assistant_Confirmation_State.
 */
class Test_Assistant_Confirmation_State extends MainWP_Assistant_Test_Case {

	/**
	 * The state owner under test.
	 *
	 * @return MainWP_Assistant_Confirmation_State
	 */
	private function owner() {
		return MainWP_Assistant_Confirmation_State::instance();
	}

	/**
	 * Build the stored pending-confirmation context shape the arm writes.
	 *
	 * @param array  $params  Reviewed params.
	 * @param string $ability Ability id.
	 *
	 * @return array Pending context.
	 */
	private function make_pending_context( array $params = array( 'name' => 'Owner Tag' ), string $ability = 'mainwp/add-tag-v1' ): array {
		return array(
			'ability'      => $ability,
			'label'        => 'Add MainWP Tag',
			'params'       => $params,
			'destructive'  => false,
			'tool_call_id' => 'call_owner',
			'tool_calls'   => array(
				array(
					'id'       => 'call_owner',
					'type'     => 'function',
					'function' => array(
						'name'      => 'wpab__mainwp__add-tag-v1',
						'arguments' => wp_json_encode( $params ),
					),
				),
			),
			'tool_results' => array(),
		);
	}

	/**
	 * Seed an unresolved confirmation-request row.
	 *
	 * Mirrors the metadata the arm transition writes so claim/peek tests run
	 * against the production row shape.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $user_id         Owning user.
	 * @param array $params          Reviewed params.
	 *
	 * @return int Request row id.
	 */
	private function seed_unresolved_row( int $conversation_id, int $user_id, array $params = array( 'name' => 'Owner Tag' ) ): int {
		$row_id = MainWP_DB_Assistant::instance()->add_message(
			$conversation_id,
			'assistant',
			'I need your confirmation to proceed with: Add MainWP Tag',
			$user_id,
			array(
				'route'                 => 'agent',
				'requires_confirmation' => true,
				'pending_ability'       => 'mainwp/add-tag-v1',
				'pending_ability_label' => 'Add MainWP Tag',
				'pending_confirmation'  => $this->make_pending_context( $params ),
			)
		);

		$this->assertIsNumeric( $row_id, 'Seeding the confirmation request row must succeed.' );

		return (int) $row_id;
	}

	/**
	 * Re-arming needs a new ID so the bound card cannot approve its replacement.
	 */
	public function test_arm_row_replaces_bound_answer() {
		$user_id = $this->set_current_user_as_admin();
		$conversation_id = $this->create_test_conversation( $user_id );
		$db = MainWP_DB_Assistant::instance();
		$db->add_message( $conversation_id, 'user', 'Create a tag', $user_id );
		$answer_id = $db->add_message( $conversation_id, 'assistant', 'Old answer', $user_id );
		$target = $db->get_messages( $conversation_id, $user_id )[1];
		$result = $this->owner()->arm_row( $conversation_id, $user_id, 'Confirm the tag', $this->make_pending_context(), array(), array(), $target );
		$this->assertGreaterThan( $answer_id, $result );
		$new_id = $result;
		$pending = $this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/add-tag-v1' );
		$this->assertSame( $new_id, $pending['message_id'] );
		$this->assertCount( 2, $db->get_messages( $conversation_id, $user_id ) );
		$result = $this->owner()->arm_row( $conversation_id, $user_id, 'Stale card', $this->make_pending_context(), array(), array(), $target );
		$this->assertWPErrorWithCode( $result, 'regenerate_target_stale' );
		$this->assertTrue( $this->owner()->claim_row( $new_id, $conversation_id, $user_id ) );
	}

	/**
	 * Test the transient arm/peek roundtrip enforces owner and ability.
	 */
	public function test_arm_transient_and_peek_roundtrip() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$context = $this->make_pending_context();
		$history = array(
			(object) array(
				'role'    => 'user',
				'content' => 'Create a tag.',
			),
		);
		$resolved = array(
			'provider_id'   => 'openai',
			'provider_name' => 'OpenAI',
			'model_id'      => 'gpt-test',
		);

		$token = $this->owner()->arm_transient( $context, $history, $resolved, $user_id );
		$this->assertIsString( $token, 'Arming the transient must return the request token.' );

		$payload = $this->owner()->peek_transient( $token, $user_id, 'mainwp/add-tag-v1' );
		$this->assertIsArray( $payload );
		$this->assertSame( $user_id, (int) $payload['user_id'] );
		$this->assertSame( $context['params'], $payload['context']['params'] );
		$this->assertSame( 'gpt-test', $payload['resolved']['model_id'] );

		// A wrong-user probe and a wrong-ability probe both get the one
		// generic refusal, and neither burns the owner's pending state.
		$probe = $this->owner()->peek_transient( $token, $user_id + 1, 'mainwp/add-tag-v1' );
		$this->assertWPError( $probe );
		$this->assertSame( 'confirm_expired', $probe->get_error_code() );

		$probe = $this->owner()->peek_transient( $token, $user_id, 'mainwp/delete-site-v1' );
		$this->assertWPError( $probe );

		$this->assertIsArray(
			$this->owner()->peek_transient( $token, $user_id, 'mainwp/add-tag-v1' ),
			'Failed probes must not consume the pending confirmation.'
		);
	}

	/**
	 * Test consuming the transient makes every later peek refuse.
	 */
	public function test_consume_transient_makes_peek_refuse() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$token = $this->owner()->arm_transient( $this->make_pending_context(), array(), array(), $user_id );
		$this->assertIsString( $token );

		$this->owner()->consume_transient( $token );

		$peek = $this->owner()->peek_transient( $token, $user_id, 'mainwp/add-tag-v1' );
		$this->assertWPError( $peek );
		$this->assertSame( 'confirm_expired', $peek->get_error_code() );
	}

	/**
	 * Test consume drops the payload once, on the options-table backend.
	 *
	 * Consume is the payload drop, not the claim: whichever call removed the
	 * transient row gets true and every later one gets false. Skipped when an
	 * external object cache is active, where delete_transient() no longer
	 * reports rows-affected. The claim itself is asserted by
	 * test_claim_transient_reports_single_winner(), which holds on every
	 * backend.
	 */
	public function test_consume_transient_drops_payload_once() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'The consume delete only reports rows-affected on the options-table transient backend.' );
		}

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$token = $this->owner()->arm_transient( $this->make_pending_context(), array(), array(), $user_id );
		$this->assertIsString( $token );

		$this->assertTrue(
			$this->owner()->consume_transient( $token ),
			'The consume that removed the transient must report it.'
		);
		$this->assertFalse(
			$this->owner()->consume_transient( $token ),
			'A consume that found nothing to remove must report false.'
		);

		$this->assertFalse(
			$this->owner()->consume_transient( '' ),
			'An empty token has nothing to drop.'
		);
	}

	/**
	 * Test the stateless claim admits exactly one winner.
	 *
	 * The claim is an options-row INSERT whose unique index arbitrates, so
	 * unlike the transient delete it does not depend on the object-cache
	 * backend: this test deliberately does not skip under one. Two Proceeds
	 * racing the same token reach here as two claims, and the second must
	 * lose so the ability runs once.
	 */
	public function test_claim_transient_reports_single_winner() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$token = $this->owner()->arm_transient( $this->make_pending_context(), array(), array(), $user_id );
		$this->assertIsString( $token );

		$this->assertTrue(
			$this->owner()->claim_transient( $token ),
			'The first claim on a token must win.'
		);
		$this->assertFalse(
			$this->owner()->claim_transient( $token ),
			'A second claim on the same token must lose, so the confirm refuses.'
		);

		// The payload goes with the claim, so a replay cannot even peek.
		$this->assertWPError( $this->owner()->peek_transient( $token, $user_id, 'mainwp/add-tag-v1' ) );

		$this->assertFalse( $this->owner()->claim_transient( '' ), 'An empty token can never win a claim.' );
		$this->assertFalse( $this->owner()->claim_transient( 'not-a-uuid' ), 'A malformed token can never win a claim.' );
	}

	/**
	 * Test a claim on a token whose payload is already gone still arbitrates.
	 *
	 * The losing side of the race peeked before the winner dropped the
	 * transient, so it arrives with a valid payload in hand and only the
	 * claim can stop it. The marker row outlives the payload for exactly
	 * that reason.
	 */
	public function test_claim_transient_loses_after_the_payload_is_gone() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$token = $this->owner()->arm_transient( $this->make_pending_context(), array(), array(), $user_id );
		$this->assertIsString( $token );

		$this->assertTrue( $this->owner()->claim_transient( $token ) );

		// The transient is gone now; the claim must still refuse.
		$this->assertFalse( get_transient( MainWP_Assistant_Confirmation_State::TRANSIENT_PREFIX . $token ) );
		$this->assertFalse( $this->owner()->claim_transient( $token ) );
	}

	/**
	 * Test the row claim wins exactly once through the owner seam.
	 */
	public function test_claim_row_claims_exactly_once() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Claim Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );
		$request_id = $this->seed_unresolved_row( $conversation_id, $user_id );

		$this->assertTrue( $this->owner()->claim_row( $request_id, $conversation_id, $user_id ) );
		$this->assertFalse(
			$this->owner()->claim_row( $request_id, $conversation_id, $user_id ),
			'A second claim on the same request must lose.'
		);
	}

	/**
	 * Test the row peek returns the pending context and honors include_settled.
	 */
	public function test_peek_row_returns_pending_context() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Peek Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );
		$request_id = $this->seed_unresolved_row( $conversation_id, $user_id );

		$pending = $this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/add-tag-v1' );
		$this->assertIsArray( $pending );
		$this->assertSame( $request_id, $pending['message_id'] );
		$this->assertFalse( $pending['settled'] );
		$this->assertSame( array( 'name' => 'Owner Tag' ), $pending['params'] );

		$this->assertNull(
			$this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/delete-site-v1' ),
			'A peek for a different ability must return nothing.'
		);

		$this->assertTrue( $this->owner()->claim_row( $request_id, $conversation_id, $user_id ) );

		$this->assertNull(
			$this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/add-tag-v1' ),
			'A settled request is not pending.'
		);

		$settled = $this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/add-tag-v1', true );
		$this->assertIsArray( $settled, 'include_settled must surface the claimed-but-unclosed request.' );
		$this->assertTrue( $settled['settled'] );
	}

	/**
	 * Test the arm transition persists the confirmation-request row shape.
	 */
	public function test_arm_row_persists_confirmation_metadata() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Arm Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );

		$row_id = $this->owner()->arm_row(
			$conversation_id,
			$user_id,
			'I need your confirmation to proceed with: Add MainWP Tag',
			$this->make_pending_context(),
			array( 'usage' => array( 'total_tokens' => 42 ) ),
			array( 'cost_usd' => 0.001 )
		);

		$this->assertIsNumeric( $row_id );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 1, 0, 'DESC' );
		$metadata = json_decode( $messages[0]->metadata, true );

		$this->assertIsArray( $metadata );
		$this->assertSame( 'agent', $metadata['route'] );
		$this->assertTrue( $metadata['requires_confirmation'] );
		$this->assertSame( 'mainwp/add-tag-v1', $metadata['pending_ability'] );
		$this->assertSame( 'Add MainWP Tag', $metadata['pending_ability_label'] );
		$this->assertSame( array( 'name' => 'Owner Tag' ), $metadata['pending_confirmation']['params'] );
		$this->assertSame( 42, $metadata['usage']['total_tokens'] );
		$this->assertSame( 0.001, $metadata['cost_usd'] );

		// The armed row is claimable — the shape arm writes is the shape the
		// CAS claim reads.
		$this->assertTrue( $this->owner()->claim_row( (int) $row_id, $conversation_id, $user_id ) );
	}

	/**
	 * Test the winning claim scrubs credential-bearing context from the stored row.
	 *
	 * The reviewed params (and the tool-call copy that embeds them) can carry
	 * raw credentials such as http_pass and adminpassword. They only need to
	 * survive until settlement, so the claim that settles the request must
	 * remove them from the persisted metadata.
	 */
	public function test_claim_row_scrubs_credential_bearing_context() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Scrub Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Update the site.' );

		$params = array(
			'site_id_or_domain' => 123,
			'http_pass'         => 'seekrit-http-pass',
			'adminpassword'     => 'seekrit-admin-pass',
		);
		$row_id = $this->seed_unresolved_row( $conversation_id, $user_id, $params );

		$this->assertTrue( $this->owner()->claim_row( $row_id, $conversation_id, $user_id ) );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 1, 0, 'DESC' );
		$metadata = json_decode( $messages[0]->metadata, true );

		$this->assertFalse( (bool) $metadata['requires_confirmation'] );
		$this->assertTrue( (bool) $metadata['confirmation_settled'] );
		$this->assertArrayNotHasKey( 'params', $metadata['pending_confirmation'], 'The reviewed params must not survive settlement.' );
		$this->assertArrayNotHasKey( 'tool_calls', $metadata['pending_confirmation'], 'The tool-call copy embedding the params must not survive settlement.' );
		$this->assertArrayNotHasKey( 'tool_results', $metadata['pending_confirmation'], 'Stored tool results must not survive settlement.' );
		$this->assertSame( 'mainwp/add-tag-v1', $metadata['pending_confirmation']['ability'], 'The ability id must survive for the cancel race path.' );
		$this->assertStringNotContainsString( 'seekrit-http-pass', $messages[0]->metadata, 'No credential may remain anywhere in the settled metadata.' );
		$this->assertStringNotContainsString( 'seekrit-admin-pass', $messages[0]->metadata, 'No credential may remain anywhere in the settled metadata.' );
	}

	/**
	 * Test the client-bound confirmation payload redacts sensitive params.
	 *
	 * The card renders a scalar-params table, so params travel to the browser
	 * — but only as a redacted display copy. The raw values live server-side
	 * in the stored context, which is what actually executes on confirm.
	 */
	public function test_redact_confirmation_for_client_redacts_sensitive_params() {
		$assistant = MainWP_Assistant::instance();

		$confirmation = array(
			'ability' => 'mainwp/update-site-v1',
			'label'   => 'Update MainWP Site',
			'params'  => array(
				'site_id_or_domain' => 123,
				'name'              => 'My Site',
				'http_pass'         => 'seekrit-http-pass',
				'adminpassword'     => 'seekrit-admin-pass',
				'uniqueid'          => 'seekrit-unique-id',
			),
		);

		$redacted = $assistant->redact_confirmation_for_client( $confirmation );

		$this->assertSame( '[REDACTED]', $redacted['params']['http_pass'] );
		$this->assertSame( '[REDACTED]', $redacted['params']['adminpassword'] );
		$this->assertSame( '[REDACTED]', $redacted['params']['uniqueid'] );
		$this->assertSame( 123, $redacted['params']['site_id_or_domain'], 'Non-sensitive params must stay renderable.' );
		$this->assertSame( 'My Site', $redacted['params']['name'] );
		$this->assertSame( 'mainwp/update-site-v1', $redacted['ability'] );

		// A payload without params passes through unchanged.
		$this->assertSame(
			array( 'ability' => 'mainwp/list-sites-v1' ),
			$assistant->redact_confirmation_for_client( array( 'ability' => 'mainwp/list-sites-v1' ) )
		);
	}

	/**
	 * Tool results reach the model and the browser through the same redaction.
	 *
	 * Abilities copy child-site error text into `errors[*].message` verbatim,
	 * so a signed URL in a fetch error would otherwise ride out in the tool
	 * result. Only error-text fields are shape-scrubbed: content fields keep
	 * whatever the site returned.
	 */
	public function test_redact_sensitive_result_scrubs_error_text_only() {
		$assistant = MainWP_Assistant::instance();
		$signed    = 'https://child.example.test/?mainwpsignature=c2lnbmVk&uniqueid=abc';

		$redacted = $assistant->redact_sensitive_result(
			array(
				'deleted' => array(),
				'errors'  => array(
					array(
						'slug'    => 'hello-dolly',
						'code'    => 'mainwp_http_error',
						'message' => 'HTTP 403 from ' . $signed,
					),
				),
				'items'   => array(
					array( 'title' => 'Reset your password: token=abc123 arrives by email' ),
				),
				'providerMessage' => 'Authorization: Bearer abcdefghijklmnop',
			)
		);

		$this->assertSame( '[redacted]', $redacted['providerMessage'] );

		$this->assertStringNotContainsString( 'c2lnbmVk', $redacted['errors'][0]['message'] );
		$this->assertStringContainsString( 'https://child.example.test/?[redacted]', $redacted['errors'][0]['message'] );
		$this->assertSame( 'hello-dolly', $redacted['errors'][0]['slug'] );
		$this->assertSame( 'Reset your password: token=abc123 arrives by email', $redacted['items'][0]['title'] );

		$error = $assistant->redact_sensitive_result( new \WP_Error( 'http_error', 'GET ' . $signed . ' failed' ) );

		$this->assertSame( 'http_error', $error['error'] );
		$this->assertStringNotContainsString( 'c2lnbmVk', $error['message'] );
	}

	/**
	 * Test close writes the closing row and respects the consent boundary.
	 */
	public function test_close_writes_closing_row_and_respects_consent() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Close Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );

		$chip = array(
			'ability' => 'mainwp/add-tag-v1',
			'label'   => 'Add MainWP Tag',
		);
		$this->owner()->close( $conversation_id, $user_id, array( 'message' => 'Tag created.' ), array( $chip ) );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 1, 0, 'DESC' );
		$this->assertSame( 'assistant', $messages[0]->role );
		$this->assertSame( 'Tag created.', $messages[0]->content );

		$metadata = json_decode( $messages[0]->metadata, true );
		$this->assertSame( 'agent', $metadata['route'] );
		$this->assertNotEmpty( $metadata['executed_abilities'] );

		// Consent revoked mid-turn: the persistence point itself must refuse
		// to write (CONFIRMATION-FLOW.md invariant 9).
		$this->set_assistant_consent( false );
		$this->owner()->close( $conversation_id, $user_id, array( 'message' => 'Must not persist.' ), array() );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 1, 0, 'DESC' );
		$this->assertSame( 'Tag created.', $messages[0]->content, 'A close after consent revocation must not write.' );
	}

	/**
	 * Test the cancel close persists the typed decline and the closing row.
	 */
	public function test_close_cancelled_writes_user_and_closing_rows() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Cancel Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );
		$this->seed_unresolved_row( $conversation_id, $user_id );

		$added = $this->owner()->close_cancelled( $conversation_id, $user_id, 'no thanks' );
		$this->assertFalse( is_wp_error( $added ), 'The cancel close must persist its closing row.' );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 500, 0, 'ASC' );
		$this->assertCount( 4, $messages );

		$this->assertSame( 'user', $messages[2]->role );
		$this->assertSame( 'no thanks', $messages[2]->content );

		$closing = end( $messages );
		$this->assertSame( 'assistant', $closing->role );
		$this->assertSame( 'Cancelled. The action was not executed.', $closing->content );

		$metadata = json_decode( $closing->metadata, true );
		$this->assertSame( 'agent', $metadata['route'] );
		$this->assertTrue( $metadata['cancelled'] );
	}

	/**
	 * Test a button cancel (no typed message) writes only the closing row.
	 */
	public function test_close_cancelled_without_message_writes_only_closing_row() {
		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$conversation_id = $this->create_test_conversation( $user_id, 'Owner Button Cancel Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Create a tag.' );
		$this->seed_unresolved_row( $conversation_id, $user_id );

		$added = $this->owner()->close_cancelled( $conversation_id, $user_id, '' );
		$this->assertFalse( is_wp_error( $added ) );

		$messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 500, 0, 'ASC' );
		$this->assertCount( 3, $messages );
		$this->assertSame( 'Cancelled. The action was not executed.', end( $messages )->content );
	}

	/**
	 * Test build_context attaches the model-facing tool copies and caps payloads.
	 */
	public function test_build_context_attaches_tool_copies() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$tool_call = array(
			'id'       => 'call_ctx',
			'type'     => 'function',
			'function' => array(
				'name'      => 'wpab__mainwp__add-tag-v1',
				'arguments' => wp_json_encode( array( 'name' => 'Context Tag' ) ),
			),
		);

		$prior_results = array(
			array(
				'tool_call_id' => 'call_prior',
				'ability'      => 'mainwp/list-sites-v1',
				'result'       => array( 'sites' => array() ),
			),
			array( 'no_tool_call_id' => true ),
		);

		$context = $this->owner()->build_context(
			array(
				'ability' => 'mainwp/add-tag-v1',
				'label'   => 'Add MainWP Tag',
				'params'  => array( 'name' => 'Context Tag' ),
				'preview' => array( 'api_key' => 'secret-value' ),
			),
			$tool_call,
			$prior_results,
			MainWP_Assistant::instance()
		);

		$this->assertSame( 'call_ctx', $context['tool_call_id'] );
		$this->assertSame( array( $tool_call ), $context['tool_calls'] );

		// Only results carrying a tool_call_id travel; malformed entries drop.
		$this->assertCount( 1, $context['tool_results'] );
		$this->assertSame( 'call_prior', $context['tool_results'][0]['tool_call_id'] );

		// The preview lands in the database, so it is redacted here.
		$this->assertSame( '[REDACTED]', $context['preview']['api_key'] );
	}

	/**
	 * Test build_context pairs every stored prior result with its originating call.
	 *
	 * Google keys function responses by name and rejects a response without a
	 * matching call in the model message, so the stored context must carry the
	 * turn's other calls, and a result whose call is unknown cannot be
	 * replayed validly.
	 */
	public function test_build_context_pairs_prior_results_with_their_calls() {
		$this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );

		$read_call = array(
			'id'       => 'call_read',
			'type'     => 'function',
			'function' => array(
				'name'      => 'wpab__mainwp__list-sites-v1',
				'arguments' => wp_json_encode( array( 'status' => 'connected' ) ),
			),
		);
		$mut_call  = array(
			'id'       => 'call_mut',
			'type'     => 'function',
			'function' => array(
				'name'      => 'wpab__mainwp__add-tag-v1',
				'arguments' => wp_json_encode( array( 'name' => 'Paired Tag' ) ),
			),
		);

		$prior_results = array(
			array(
				'tool_call_id' => 'call_read',
				'ability'      => 'mainwp/list-sites-v1',
				'result'       => array( 'sites' => array() ),
			),
			array(
				'tool_call_id' => 'call_ghost',
				'ability'      => 'mainwp/get-tags-v1',
				'result'       => array( 'tags' => array() ),
			),
		);

		$context = $this->owner()->build_context(
			array(
				'ability' => 'mainwp/add-tag-v1',
				'label'   => 'Add MainWP Tag',
				'params'  => array( 'name' => 'Paired Tag' ),
			),
			$mut_call,
			$prior_results,
			MainWP_Assistant::instance(),
			array( $read_call, $mut_call )
		);

		$ids = array_column( $context['tool_calls'], 'id' );
		$this->assertSame( array( 'call_read', 'call_mut' ), $ids, 'Prior calls travel before the confirmed call.' );
		$this->assertSame( 'wpab__mainwp__list-sites-v1', $context['tool_calls'][0]['function']['name'] );

		$this->assertCount( 1, $context['tool_results'], 'A result whose call is unknown cannot be replayed validly.' );
		$this->assertSame( 'call_read', $context['tool_results'][0]['tool_call_id'] );
	}

	/**
	 * Test the reviewed credentials never land in the conversation row as plaintext.
	 *
	 * mainwp/add-site-v1 takes adminpassword, http_pass and uniqueid, and the
	 * reviewed params are what executes on confirm, so they cannot simply be
	 * redacted. They are enveloped instead, and the row that carries them
	 * (plus the model-facing tool_call copy that repeats them) must hold no
	 * readable copy.
	 */
	public function test_build_context_keeps_credentials_out_of_the_stored_row() {
		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->mock_encryption_filter();

		$params = array(
			'name'          => 'Client Site',
			'url'           => 'https://client.example.com',
			'adminpassword' => 'hunter2-admin-secret',
			'http_pass'     => 'basic-auth-secret',
			'uniqueid'      => 'unique-id-secret',
		);

		$tool_call = array(
			'id'       => 'call_site',
			'type'     => 'function',
			'function' => array(
				'name'      => 'wpab__mainwp__add-site-v1',
				'arguments' => wp_json_encode( $params ),
			),
		);

		$context = $this->owner()->build_context(
			array(
				'ability' => 'mainwp/add-site-v1',
				'label'   => 'Add Site',
				'params'  => $params,
			),
			$tool_call,
			array(),
			MainWP_Assistant::instance()
		);

		$this->assertTrue( MainWP_Credential_Storage::is_credential_envelope( $context['params']['adminpassword'] ) );
		$this->assertTrue( MainWP_Credential_Storage::is_credential_envelope( $context['params']['http_pass'] ) );
		$this->assertSame( 'Client Site', $context['params']['name'], 'Non-credential params are untouched.' );

		// The replayed tool call gets the display redaction: it is model
		// context, never the thing that executes.
		$this->assertStringContainsString( '[REDACTED]', $context['tool_calls'][0]['function']['arguments'] );

		$conversation_id = $this->create_test_conversation( $user_id, 'Add Site Conversation' );
		$this->add_test_message( $conversation_id, 'user', 'Add client.example.com.' );

		$request_id = $this->owner()->arm_row(
			$conversation_id,
			$user_id,
			'I need your confirmation to proceed with: Add Site',
			$context
		);
		$this->assertIsNumeric( $request_id );

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT metadata FROM ' . $wpdb->prefix . 'mainwp_assistant_messages WHERE id = %d',
				(int) $request_id
			)
		);

		$this->assertStringNotContainsString( 'hunter2-admin-secret', $stored, 'The admin password must not be at rest in the row.' );
		$this->assertStringNotContainsString( 'basic-auth-secret', $stored, 'The HTTP auth password must not be at rest in the row.' );
		$this->assertStringNotContainsString( 'unique-id-secret', $stored, 'The unique id must not be at rest in the row.' );

		// The confirm path still executes the values the user reviewed.
		$peeked = $this->owner()->peek_row( $conversation_id, $user_id, 'mainwp/add-site-v1' );
		$this->assertIsArray( $peeked );
		$this->assertSame( 'hunter2-admin-secret', $peeked['params']['adminpassword'] );
		$this->assertSame( 'basic-auth-secret', $peeked['params']['http_pass'] );
		$this->assertSame( 'Client Site', $peeked['params']['name'] );
	}

	/**
	 * Test the opt-out transient stores credentials enveloped too.
	 *
	 * The stateless payload is an option row of its own, so the same rule
	 * applies: enveloped going in, plaintext coming back out of the peek that
	 * feeds execution.
	 */
	public function test_arm_transient_keeps_credentials_out_of_the_option_row() {
		global $wpdb;

		$user_id = $this->set_current_user_as_admin();
		$this->set_assistant_enabled( true );
		$this->set_assistant_consent( true );
		$this->mock_encryption_filter();

		$params  = array(
			'name'          => 'Opt Out Site',
			'adminpassword' => 'stateless-admin-secret',
		);
		$context = $this->owner()->build_context(
			array(
				'ability' => 'mainwp/add-site-v1',
				'label'   => 'Add Site',
				'params'  => $params,
			),
			array(
				'id'       => 'call_stateless',
				'type'     => 'function',
				'function' => array(
					'name'      => 'wpab__mainwp__add-site-v1',
					'arguments' => wp_json_encode( $params ),
				),
			),
			array(),
			MainWP_Assistant::instance()
		);

		$token = $this->owner()->arm_transient( $context, array(), array(), $user_id );
		$this->assertIsString( $token );

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s',
				'_transient_' . MainWP_Assistant_Confirmation_State::TRANSIENT_PREFIX . $token
			)
		);
		$this->assertStringNotContainsString( 'stateless-admin-secret', (string) $stored );

		$payload = $this->owner()->peek_transient( $token, $user_id, 'mainwp/add-site-v1' );
		$this->assertIsArray( $payload );
		$this->assertSame( 'stateless-admin-secret', $payload['context']['params']['adminpassword'] );
	}
}
