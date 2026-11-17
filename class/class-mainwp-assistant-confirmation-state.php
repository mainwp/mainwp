<?php
/**
 * MainWP Assistant Confirmation State.
 *
 * The single owner of the assistant's confirmation state machine. When the
 * model proposes a tool call that policy marks as requiring confirmation, the
 * pending state is armed here, peeked non-destructively while the handlers
 * re-validate, claimed exactly once by whichever resolver wins, and closed
 * with a resolving row so stored history never ends on an unanswered ask.
 *
 * Two storage backends carry the state (see tests/assistant/CONFIRMATION-FLOW.md §1):
 *
 * - History-on (conversation_id > 0): the confirmation-request row's
 *   `pending_confirmation` metadata, claimed atomically through the
 *   compare-and-swap in MainWP_DB_Assistant::settle_confirmation_request().
 * - History opt-out (conversation_id 0): a short-TTL transient keyed by the
 *   request token the card echoes back, claimed by the options-row INSERT in
 *   claim_transient().
 *
 * The transitions here are the seam; the storage primitives (add_message,
 * get_messages, the CAS) stay on MainWP_DB_Assistant.
 *
 * @package MainWP/Dashboard
 * @since   6.0.0
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Confirmation_State
 *
 * Owns the arm / peek / claim / consume / close transitions of the
 * confirmation state machine.
 *
 * @since 6.0.0
 */
class MainWP_Assistant_Confirmation_State { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Transient key prefix for stateless (history opt-out) pending confirmations.
     *
     * @since 6.0.0
     * @var string
     */
    const TRANSIENT_PREFIX = 'mainwp_assistant_confirm_';

    /**
     * Option-name prefix for the stateless claim markers.
     *
     * One row per resolved stateless confirmation, keyed by the request
     * token. The options table's unique index on option_name is what makes
     * the claim atomic; see claim_transient().
     *
     * @since 6.0.0
     * @var string
     */
    const CLAIM_OPTION_PREFIX = 'mainwp_assistant_confirm_claimed_';

    /**
     * TTL for stateless pending-confirmation transients.
     *
     * Long enough for a human decision, short enough to bound how long
     * un-asked-for state survives for a user who opted out of history.
     *
     * @since 6.0.0
     * @var int
     */
    const TRANSIENT_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Singleton instance.
     *
     * @since 6.0.0
     * @var MainWP_Assistant_Confirmation_State|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since 6.0.0
     *
     * @return MainWP_Assistant_Confirmation_State
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Build the server-held resume context stored with a confirmation request.
     *
     * Mirrors the confirmation_data the wire response carries, with the
     * result-shaped payloads (preview, prior tool results) capped so the
     * metadata row stays bounded. Tool results arrive already redacted; the
     * preview gets redacted here because it lands in the database.
     *
     * The reviewed params are what executes on confirm, so they cannot be
     * redacted, but they can carry raw credentials (mainwp/add-site-v1 takes
     * adminpassword, http_pass and uniqueid) and this context is persisted:
     * to the confirmation-request row's metadata for history-on, to an option
     * row for the opt-out transient. Those values are therefore encrypted
     * with the same envelope site credentials use, and peek_row() /
     * peek_transient() decrypt them back on the way to execution. The
     * model-facing tool_call copy gets the display redaction instead: it is
     * replayed as conversation context, never executed.
     *
     * Each envelope leaves its key file behind once the request settles, the
     * same lifecycle site credentials already have (nothing in the Dashboard
     * deletes those either). The files are tiny and only a confirmation that
     * actually carried a credential creates one, so the litter is bounded by
     * how often someone hands the assistant a password.
     *
     * @since 6.0.0
     *
     * @param array            $result           The requires_confirmation ability result.
     * @param array            $tool_call        The tool call being confirmed (wire shape).
     * @param array            $all_tool_results Prior tool results from this turn.
     * @param MainWP_Assistant $assistant        Assistant instance providing the
     *                                           redaction and history-cap shaping.
     * @param array            $turn_tool_calls  Every wire-shaped call this turn made,
     *                                           so replayed results keep their calls.
     *
     * @return array Stored pending-confirmation context.
     */
    public function build_context( $result, $tool_call, $all_tool_results, MainWP_Assistant $assistant, $turn_tool_calls = array() ) {
        $pending = is_array( $result ) ? $result : array();

        if ( isset( $pending['preview'] ) ) {
            $pending['preview'] = $assistant->cap_tool_result_for_history(
                $assistant->redact_sensitive_result( $pending['preview'] ),
                $pending['ability'] ?? ''
            );
        }

        if ( isset( $pending['params'] ) && is_array( $pending['params'] ) ) {
            $pending['params'] = $this->encrypt_sensitive_values( $pending['params'] );
        }

        $tool_call = $this->redact_tool_call_arguments( $tool_call, $assistant );

        // Google keys function responses by name and rejects a response
        // without a matching call in the model message, so when the caller
        // supplies the turn's calls, every stored result travels with its
        // originating call and an unmatched result is dropped rather than
        // replayed as an orphan. Without the map (legacy callers) results
        // pass through as before.
        $calls_by_id = array();
        foreach ( (array) $turn_tool_calls as $candidate ) {
            if ( is_array( $candidate ) && ! empty( $candidate['id'] ) && ! empty( $candidate['function']['name'] ) ) {
                $calls_by_id[ (string) $candidate['id'] ] = $candidate;
            }
        }

        $stored_tool_results = array();
        $prior_calls         = array();
        foreach ( (array) $all_tool_results as $prior_result ) {
            if ( ! is_array( $prior_result ) || empty( $prior_result['tool_call_id'] ) ) {
                continue;
            }
            $prior_id = (string) $prior_result['tool_call_id'];
            if ( ! empty( $calls_by_id ) ) {
                if ( ! isset( $calls_by_id[ $prior_id ] ) ) {
                    continue;
                }
                if ( (string) ( $tool_call['id'] ?? '' ) !== $prior_id ) {
                    $prior_calls[ $prior_id ] = $this->redact_tool_call_arguments( $calls_by_id[ $prior_id ], $assistant );
                }
            }
            $stored_tool_results[] = array(
                'tool_call_id' => $prior_result['tool_call_id'],
                'ability'      => $prior_result['ability'] ?? '',
                'result'       => $assistant->cap_tool_result_for_history( $prior_result['result'] ?? array(), $prior_result['ability'] ?? '' ),
            );
        }

        $pending['tool_call_id'] = $tool_call['id'] ?? '';
        $pending['tool_calls']   = array_merge( array_values( $prior_calls ), array( $tool_call ) );
        $pending['tool_results'] = $stored_tool_results;

        return $pending;
    }

    /**
     * Encrypt the credential-bearing values of a reviewed-params array.
     *
     * Keyed on the same field list the log redaction uses, so a param the
     * audit trail refuses to print is a param this refuses to persist in the
     * clear. A failed encrypt drops the value instead of falling back to
     * plaintext: the confirmed action then fails visibly (a site added
     * without its HTTP auth password cannot connect) rather than writing the
     * credential to the database.
     *
     * @since 6.0.0
     *
     * @param array $params Reviewed params.
     * @param int   $depth  Current recursion depth.
     *
     * @return array Params with sensitive values enveloped.
     */
    private function encrypt_sensitive_values( array $params, $depth = 0 ) {
        if ( $depth >= 5 ) {
            return $params;
        }

        foreach ( $params as $key => $value ) {
            if ( is_array( $value ) ) {
                $params[ $key ] = $this->encrypt_sensitive_values( $value, $depth + 1 );
                continue;
            }

            if ( ! is_string( $value ) || '' === $value || ! $this->is_sensitive_key( $key ) ) {
                continue;
            }

            $encrypted      = MainWP_Credential_Storage::encrypt_credential( $value, (string) $key );
            $params[ $key ] = is_string( $encrypted ) ? $encrypted : '';
        }

        return $params;
    }

    /**
     * Decrypt the credential envelopes build_context() wrote.
     *
     * Envelope detection is prefix-exact, so params stored before this
     * (and every non-credential value) pass through untouched.
     *
     * @since 6.0.0
     *
     * @param array $params Stored params.
     * @param int   $depth  Current recursion depth.
     *
     * @return array Params with envelopes resolved to plaintext.
     */
    private function decrypt_sensitive_values( array $params, $depth = 0 ) {
        if ( $depth >= 5 ) {
            return $params;
        }

        foreach ( $params as $key => $value ) {
            if ( is_array( $value ) ) {
                $params[ $key ] = $this->decrypt_sensitive_values( $value, $depth + 1 );
                continue;
            }

            if ( MainWP_Credential_Storage::is_credential_envelope( $value ) ) {
                $params[ $key ] = MainWP_Credential_Storage::decrypt_credential( $value );
            }
        }

        return $params;
    }

    /**
     * Does this param key name a credential the audit trail refuses to print?
     *
     * @since 6.0.0
     *
     * @param int|string $key Param key.
     *
     * @return bool True when the key matches a redacted field.
     */
    private function is_sensitive_key( $key ) {
        $key = strtolower( (string) $key );

        foreach ( MainWP_Assistant::REDACTED_FIELDS as $field ) {
            if ( false !== strpos( $key, $field ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact the credential values embedded in the stored tool-call copy.
     *
     * The arguments JSON repeats the reviewed params, so persisting it as the
     * model emitted it would put the credentials back in the row the
     * encryption above just took them out of. The stored copy is only
     * replayed to the model as the assistant turn that asked for the
     * confirmation, so the display redaction is enough here.
     *
     * @since 6.0.0
     *
     * @param array            $tool_call Tool call being confirmed (wire shape).
     * @param MainWP_Assistant $assistant Assistant instance providing the redaction.
     *
     * @return array Tool call with redacted arguments.
     */
    private function redact_tool_call_arguments( $tool_call, MainWP_Assistant $assistant ) {
        if ( ! is_array( $tool_call ) || ! isset( $tool_call['function']['arguments'] ) || ! is_string( $tool_call['function']['arguments'] ) ) {
            return $tool_call;
        }

        $arguments = json_decode( $tool_call['function']['arguments'], true );
        if ( ! is_array( $arguments ) || empty( $arguments ) ) {
            return $tool_call;
        }

        $redacted = $assistant->redact_confirmation_for_client( array( 'params' => $arguments ) );
        $encoded  = wp_json_encode( $redacted['params'] );
        if ( is_string( $encoded ) ) {
            $tool_call['function']['arguments'] = $encoded;
        }

        return $tool_call;
    }

    /**
     * Arm a confirmation request on a conversation row (history-on path).
     *
     * Composes the confirmation-owned metadata keys around the caller's
     * turn-level metadata and persists the "please confirm" assistant row.
     * The returned row id is the request_id the card echoes back on
     * confirm/cancel, so the server resolves exactly the request the user
     * saw, never whatever request is newest.
     *
     * @since 6.0.0
     *
     * @param int         $conversation_id Conversation ID.
     * @param int         $user_id         Owning user.
     * @param string      $content         The confirmation-request message text.
     * @param array       $context         build_context() output to store as pending_confirmation.
     * @param array       $turn_metadata   Turn-level metadata (executed_abilities, usage).
     * @param array       $cost_fields     Per-message cost fields merged after the confirmation keys.
     * @param object|null $regenerate_target Bound answer including expected content and metadata.
     *
     * @return int|\WP_Error Request row id, or WP_Error when the write failed.
     */
    public function arm_row( $conversation_id, $user_id, $content, array $context, array $turn_metadata = array(), array $cost_fields = array(), $regenerate_target = null ) {
        $metadata = $turn_metadata;

        // Keep stored confirmation requests tagged by route.
        $metadata['route']                 = 'agent';
        $metadata['requires_confirmation'] = true;
        $metadata['pending_ability']       = $context['ability'] ?? null;
        $metadata['pending_ability_label'] = $context['label'] ?? null;
        // Server-held resume context: the wire copy of tool_calls and
        // tool_results round-trips through the browser and is lost on a page
        // reload, so the confirm handler prefers this stored copy and the
        // loader uses it to re-arm the confirmation card.
        $metadata['pending_confirmation'] = $context;
        $metadata                         = array_merge( $metadata, $cost_fields );

        if ( $regenerate_target ) {
            return MainWP_DB_Assistant::instance()->swap_assistant_message(
                $conversation_id,
                (int) $regenerate_target->id,
                $content,
                $user_id,
                $metadata,
                (string) $regenerate_target->content,
                (string) $regenerate_target->metadata
            );
        }

        return MainWP_DB_Assistant::instance()->add_message(
            $conversation_id,
            'assistant',
            $content,
            $user_id,
            $metadata
        );
    }

    /**
     * Arm the ephemeral pending-confirmation state for a stateless confirm.
     *
     * History opt-out (conversation_id 0) persists no rows, so the reviewed
     * params and the resume context live in a short-TTL transient instead.
     * The returned token rides confirmation_data as the request_id the card
     * echoes back, giving the stateless path the same pinning the row id
     * gives history-on confirms.
     *
     * @since 6.0.0
     *
     * @param array $pending_context   build_context() output.
     * @param array $stateless_history Turn history plus the synthetic confirmation-request message.
     * @param array $resolved          Resolved provider_id/provider_name/model_id for the resume.
     * @param int   $user_id           Owning user, verified again on claim.
     *
     * @return string|false Request token, or false when the transient write failed.
     */
    public function arm_transient( $pending_context, $stateless_history, $resolved, $user_id ) {
        $token = wp_generate_uuid4();
        if ( ! is_string( $token ) || '' === $token ) {
            return false;
        }

        $this->purge_expired_claims();

        $stored = set_transient(
            self::TRANSIENT_PREFIX . $token,
            array(
                'user_id'  => absint( $user_id ),
                'context'  => is_array( $pending_context ) ? $pending_context : array(),
                'history'  => is_array( $stateless_history ) ? $stateless_history : array(),
                'resolved' => is_array( $resolved ) ? $resolved : array(),
                'created'  => time(),
            ),
            self::TRANSIENT_TTL
        );

        return $stored ? $token : false;
    }

    /**
     * Load the stored resume context for an unresolved confirmation request.
     *
     * Returns the pending_confirmation metadata only while the confirmation
     * request is still the conversation's last message and its ability
     * matches the one being confirmed; anything persisted after it (a resume
     * summary, a closing message, a new turn) settles the request. The
     * history opt-out case (conversation_id 0) returns null before querying
     * messages because there is no stored conversation to resume.
     *
     * @since 6.0.0
     *
     * @param int    $conversation_id Conversation ID (0 when history is disabled).
     * @param int    $user_id         Current user ID.
     * @param string $ability         Ability being confirmed.
     * @param bool   $include_settled Also return a request another resolver
     *                                already claimed but has not yet closed
     *                                with a resolving row (settled => true).
     *
     * @return array|null Stored context, or null when none applies.
     */
    public function peek_row( $conversation_id, $user_id, $ability, $include_settled = false ) {
        if ( empty( $conversation_id ) ) {
            return null;
        }

        $messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 1, 0, 'DESC' );
        if ( is_wp_error( $messages ) || empty( $messages ) ) {
            return null;
        }

        $msg = $messages[0];
        if ( ! is_object( $msg ) || 'assistant' !== ( $msg->role ?? '' ) || empty( $msg->metadata ) || ! is_string( $msg->metadata ) ) {
            return null;
        }

        $decoded = json_decode( $msg->metadata, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $is_pending = ! empty( $decoded['requires_confirmation'] );
        $is_settled = ! $is_pending && ! empty( $decoded['confirmation_settled'] );
        if ( ! $is_pending && ( ! $include_settled || ! $is_settled ) ) {
            return null;
        }

        $pending = $decoded['pending_confirmation'] ?? null;
        if ( ! is_array( $pending ) || ( $pending['ability'] ?? '' ) !== $ability ) {
            return null;
        }

        if ( isset( $pending['params'] ) && is_array( $pending['params'] ) ) {
            $pending['params'] = $this->decrypt_sensitive_values( $pending['params'] );
        }

        // The request row's id lets callers claim the request atomically
        // (claim_row()), and settled tells a racing cancel it lost the
        // claim; both are internal, never sent to the client.
        $pending['message_id'] = (int) ( $msg->id ?? 0 );
        $pending['settled']    = $is_settled;

        return $pending;
    }

    /**
     * Read a stateless pending-confirmation transient without consuming it.
     *
     * The peek runs before schema/policy/site-access re-validation so a
     * validation failure or a wrong-user probe never burns the owner's
     * pending confirmation, matching history-on, whose unclaimed request row
     * survives such failures. One generic error covers missing, expired,
     * wrong-user and wrong-ability so the response discloses nothing about
     * which case applied.
     *
     * @since 6.0.0
     *
     * @param string $token   Request token echoed by the card.
     * @param int    $user_id Current user ID.
     * @param string $ability Ability being confirmed.
     *
     * @return array|\WP_Error Stored payload, or WP_Error( 'confirm_expired' ).
     */
    public function peek_transient( $token, $user_id, $ability ) {
        $error = new \WP_Error(
            'confirm_expired',
            __( 'This confirmation has expired or was already used, so it was not executed. Ask again to retry.', 'mainwp' )
        );

        // The token is attacker-controlled and is concatenated into an option name via
        // the transient key, so it is rejected unless it has the exact UUIDv4 shape
        // arm_transient() generated. Nothing past this point can probe other options.
        $token = is_string( $token ) ? $token : '';
        if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $token ) ) {
            return $error;
        }

        $payload = get_transient( self::TRANSIENT_PREFIX . $token );

        if ( ! is_array( $payload )
            || absint( $payload['user_id'] ?? 0 ) !== absint( $user_id )
            || ( $payload['context']['ability'] ?? '' ) !== $ability
        ) {
            return $error;
        }

        if ( isset( $payload['context']['params'] ) && is_array( $payload['context']['params'] ) ) {
            $payload['context']['params'] = $this->decrypt_sensitive_values( $payload['context']['params'] );
        }

        return $payload;
    }

    /**
     * Claim a history-on confirmation request so exactly one resolver wins.
     *
     * Delegates to the MAX(id)-anchored compare-and-swap in
     * MainWP_DB_Assistant::settle_confirmation_request(): a second Proceed, a
     * racing cancel, or a claim against a request displaced by a newer turn
     * all lose here and must not act.
     *
     * @since 6.0.0
     *
     * @param int $message_id      Confirmation-request row id.
     * @param int $conversation_id Conversation ID.
     * @param int $user_id         Current user ID.
     *
     * @return bool True when this caller won the claim.
     */
    public function claim_row( $message_id, $conversation_id, $user_id ) {
        return MainWP_DB_Assistant::instance()->settle_confirmation_request( $message_id, $conversation_id, $user_id );
    }

    /**
     * Claim a stateless confirmation request so exactly one resolver wins.
     *
     * Called immediately before execution, the same ladder position as the
     * history-on compare-and-swap claim, so a replayed confirm can never
     * execute twice.
     *
     * The claim is an INSERT of a marker row keyed by the request token: the
     * options table's unique index on option_name makes it atomic, so of
     * several requests racing the same token exactly one inserts a row and
     * every other gets zero rows back. Deleting the pending transient used to
     * be the claim, and that only arbitrated on the options-table transient
     * backend; under a persistent external object cache delete_transient()
     * routes through wp_cache_delete(), whose single-winner behaviour is
     * backend-specific, and the confirm handler then executed on an unproven
     * claim. The marker row arbitrates the same way on every backend.
     *
     * The pending payload is dropped either way: the request is resolved once
     * this returns, for the winner and the loser alike.
     *
     * @since 6.0.0
     *
     * @param string $token Request token echoed by the card.
     *
     * @return bool True when this caller won the claim.
     */
    public function claim_transient( $token ) {
        // Same UUIDv4 gate peek_transient() applies: the token is
        // attacker-controlled and is concatenated into an option name.
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9-]{36}$/i', $token ) ) {
            return false;
        }

        global $wpdb;

        // The marker is never read back through get_option(), so the write
        // deliberately skips the option cache layers; autoload 'no' keeps it
        // out of alloptions. INSERT IGNORE turns the duplicate-key collision
        // into zero affected rows, which is the lost claim.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim marker; the options API offers no compare-and-set.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )',
                $wpdb->options,
                self::CLAIM_OPTION_PREFIX . $token,
                (string) time(),
                'no'
            )
        );

        $this->consume_transient( $token );

        return 1 === (int) $inserted;
    }

    /**
     * Drop a stateless pending-confirmation transient.
     *
     * The payload store only; claim_transient() decides who resolved the
     * request. Callers must not read a false here as a lost claim.
     *
     * @since 6.0.0
     *
     * @param string $token Request token echoed by the card.
     *
     * @return bool True when the transient was removed.
     */
    public function consume_transient( $token ) {
        if ( ! is_string( $token ) || '' === $token ) {
            return false;
        }

        return (bool) delete_transient( self::TRANSIENT_PREFIX . $token );
    }

    /**
     * Delete claim markers whose confirmation can no longer be replayed.
     *
     * The markers have to outlive the pending transient they arbitrate, or a
     * request that peeked just before the winner consumed it could still win
     * a second claim. Once the transient's TTL has passed twice over there is
     * nothing left to replay, so the row is dead weight. Runs on the arm,
     * which is the only place a new marker can ever become necessary.
     *
     * @since 6.0.0
     *
     * @return void
     */
    private function purge_expired_claims() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Housekeeping delete over rows the options API never reads.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s AND CAST( option_value AS UNSIGNED ) < %d',
                $wpdb->options,
                $wpdb->esc_like( self::CLAIM_OPTION_PREFIX ) . '%',
                time() - ( 2 * self::TRANSIENT_TTL )
            )
        );
    }

    /**
     * Persist a closing assistant message when the resume summary is unavailable.
     *
     * The confirmed action already executed, so history must not end on the
     * unresolved confirmation request. A stored thread ending there makes the
     * next turn replay an unanswered confirmation and the model re-asks.
     * Respects the history opt-out (conversation_id 0) and the consent
     * boundary; the metadata preserves the executed-ability chip for reloads
     * and keeps the stored closing message tagged by route.
     *
     * @since 6.0.0
     *
     * @param int   $conversation_id    Conversation ID (0 when history is disabled).
     * @param int   $user_id            Current user ID.
     * @param mixed $result             The executed ability result.
     * @param array $executed_abilities Executed-ability chips for the message metadata.
     *
     * @return void
     */
    public function close( $conversation_id, $user_id, $result, $executed_abilities ) {
        if ( empty( $conversation_id ) ) {
            return;
        }

        if ( MainWP_Assistant_Policies::instance()->consent_boundary_error() ) {
            return;
        }

        $closing_text = ( is_array( $result ) && ! empty( $result['message'] ) && is_string( $result['message'] ) )
            ? $result['message']
            : __( 'The action was completed successfully.', 'mainwp' );

        $closing_metadata = array( 'route' => 'agent' );
        if ( ! empty( $executed_abilities ) ) {
            $closing_metadata['executed_abilities'] = $executed_abilities;
        }

        MainWP_DB_Assistant::instance()->add_message(
            $conversation_id,
            'assistant',
            $closing_text,
            $user_id,
            $closing_metadata
        );
    }

    /**
     * Close a claimed confirmation request as cancelled.
     *
     * Persists the user's typed decline (when any) ahead of the closing row on
     * a best-effort basis, the closing row being the one that matters, then
     * writes the cancelled assistant row that settles the thread so the reload
     * re-arm stops returning the card. Runs only after the caller won the claim.
     *
     * @since 6.0.0
     *
     * @param int    $conversation_id Conversation ID.
     * @param int    $user_id         Current user ID.
     * @param string $user_message    The user's literal decline text, or ''.
     *
     * @return int|\WP_Error The closing row id, or WP_Error when its write failed.
     */
    public function close_cancelled( $conversation_id, $user_id, $user_message ) {
        if ( '' !== $user_message ) {
            MainWP_DB_Assistant::instance()->add_message( $conversation_id, 'user', $user_message, $user_id );
        }

        return MainWP_DB_Assistant::instance()->add_message(
            $conversation_id,
            'assistant',
            __( 'Cancelled. The action was not executed.', 'mainwp' ),
            $user_id,
            array(
                'route'     => 'agent',
                'cancelled' => true,
            )
        );
    }
}
