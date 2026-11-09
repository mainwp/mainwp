# Assistant Confirmation Flow — Architecture Notes

This document maps the confirmation state machine behind the assistant's destructive-action
gate: where its state lives, the transitions, the invariants the implementation relies on, and
the reasoning behind the parts that look odd on first read. Read it before modifying anything
in this flow. Anchors are symbol names rather than line numbers; line numbers rot.

The flow in one paragraph: when the model proposes a tool call that policy marks as requiring
confirmation, the server arms a pending confirmation (a chat row for users with history, a
transient for users opted out of history), the panel renders an inline confirmation card, and
the user resolves it by button click or by typing an affirmative or negative reply. Resolution
claims the pending state through a compare-and-swap so exactly one resolver wins, then either
executes the ability and resumes the model, or records the cancellation. History never ends on
an unresolved request: a closing row is always written, and page reloads re-arm only cards that
are genuinely still pending.

## 1. Storage map

All server-side confirmation state lives in exactly four places. If you add a fifth, add it
here and add an invariant test.

**`mainwp_assistant_messages.metadata` (JSON column) — the cid>0 path.**
Written when a conversation row backs the confirmation (user has history enabled).

| Key | Written by | Read by |
|---|---|---|
| `requires_confirmation` (bool) | `MainWP_Assistant_Confirmation_State::arm_row()` via the arming block in `MainWP_Assistant::process_tool_call_loop()` | `MainWP_Assistant_Confirmation_State::peek_row()`, the reload path in `handle_load_conversation()` |
| `confirmation_settled` (bool) | `MainWP_DB_Assistant::settle_confirmation_request()` (flips both flags atomically) | `MainWP_Assistant_Confirmation_State::peek_row()` |
| `pending_confirmation` (ability, params, preview, `tool_call_id`, `tool_calls`, `tool_results`, `request_id`) | `MainWP_Assistant_Confirmation_State::build_context()` via the arming block | server confirm/cancel handlers; the reload payload (trimmed — see invariant 6) |

**Transient `mainwp_assistant_confirm_{uuid}` — the cid=0 path** (user opted out of history;
`MainWP_Assistant_Confirmation_State::TRANSIENT_PREFIX`, TTL `TRANSIENT_TTL` = 15 minutes;
`MainWP_Assistant` keeps aliasing constants for compatibility). Holds the entire pending
state: user id, confirmation context, stateless message history, resolved provider/model.
Written by `MainWP_Assistant_Confirmation_State::arm_transient()` (via the protected
`MainWP_Assistant::store_pending_confirmation_transient()` seam — see §5), read
non-destructively by `peek_transient()`, settled by `claim_transient()` (which drops the
payload through `consume_transient()` once the claim is decided).

**Transient `mainwp_chat_job_{job_id}` — the Stop flag.** Not confirmation state itself, but
the confirmation path consults it constantly: `cancel_requested` is set by
`handle_cancel_job()` and read through `is_active_job_cancelled()`.

**User meta — policy inputs, not pending state.** `mainwp_assistant_confirmation_level` and
`mainwp_assistant_protected_tags` (see `MainWP_Assistant_Policies`) decide whether a
confirmation is required at all. `mainwp_assistant_history_opt_out` selects the row-vs-transient
storage path above.

The JS lives in `assets/js/mainwp-mintlify-chat.js` (global `MainWPMintlifyChat`) — not in
`mainwp-assistant-panel.js` (which only delegates) and not in `mainwp-assistant-chat-logic.js`
(which only exports the typed-reply word lists).

## 2. Lifecycle

**Arm.** `process_tool_call_loop()` sees `requires_confirmation` on a tool result, builds the
context via `MainWP_Assistant_Confirmation_State::build_context()`, and persists it: a
"please confirm" assistant row (cid>0, `arm_row()`) or the transient (cid=0,
`arm_transient()` behind the protected store seam). The wire response carries
`requires_confirmation` plus a trimmed `confirmation_data`.

**Render.** `handleConfirmationRequired()` → `renderConfirmationCard()` builds the inline card,
first disabling any previously live card. Proceed wires to `executeConfirmedAction()`, Cancel to
`persistCancellation()`. A typed reply is intercepted in `sendMessage()`: affirmatives confirm,
negatives cancel, anything else abandons the card and sends normally.

**Settle.** Both `handle_confirm_action()` and `handle_cancel_action()` claim before acting
through the state owner: cid>0 via `claim_row()` (the CAS in
`MainWP_DB_Assistant::settle_confirmation_request()`), cid=0 via `peek_transient()` then
`claim_transient()`. A losing claim returns `already_settled` and the client converges via
`refreshSettledConversation()`.

**Execute / cancel / close.** Confirm re-validates schema, policy, and site access, executes the
ability, then resumes the model via `resume_after_confirmation()` using the server-held tool
copies. Cancel writes a cancelled row via `close_cancelled()` (persisting any typed cancel
message) and runs nothing. Either way the owner's `close()` guarantees the conversation does
not end on an unresolved request.

**Reload / stale tabs.** `loadConversation()` re-arms the card only when the server still
reports a pending confirmation. A tab that lost the race reloads the settled conversation state
instead of trusting its stale card.

## 3. Invariants

Each row: the rule, the enforcing symbol, and the test that pins it. If you change the rule,
change the test in the same commit — several of these exist because a live race or a review
round proved the naive version wrong.

| # | Invariant | Enforced in | Pinned by |
|---|---|---|---|
| 1 | Stored params always win; POSTed copies are advisory and never executed | `handle_confirm_action()` (both paths) | `test_confirm_action_executes_stored_params_not_posted`, `test_confirm_action_errors_when_stored_params_missing` |
| 2 | Settle must claim before execute | ordering inside `handle_confirm_action()` | `test_settle_confirmation_request_claims_exactly_once`, `test_double_confirm_executes_once` |
| 3 | Exactly one resolver wins (CAS on re-read metadata, `MAX(id)`-anchored) | `MainWP_DB_Assistant::settle_confirmation_request()` | `test_confirm_then_cancel_settles_once`, `test_cancel_then_confirm_refuses_execution` |
| 4 | A claim must match the stored `request_id` or be refused as superseded | `handle_confirm_action()`, `handle_cancel_action()` | `test_settle_confirmation_request_refuses_stale_request`, `test_confirm_action_history_opt_out_requires_request_id` |
| 5 | Arm failure answers in plain text; a card is never rendered without its backing state persisted | transient-write failure branch in `process_tool_call_loop()` | (code comment at the branch; no dedicated test — add one if you touch this) |
| 6 | Model-facing `tool_calls`/`tool_results` copies never travel to the browser | the wire trim in `process_tool_call_loop()`; reload path mirrors it | `test_confirmation_response_omits_tool_copies`, `test_load_conversation_pending_confirmation_omits_tool_copies` |
| 7 | A pending confirmation belongs to its conversation; switching conversations drops the card | `resolveActiveConfirmCard()` call in `loadConversation()` (JS) | `tests/js/test-confirm-flow.js` |
| 8 | History-on with no backing conversation row is tampering, not a downgrade to the stateless path | the guard at the top of the confirm path in `handle_chat_request()`/`handle_confirm_action()` | `test_confirm_action_resumes_from_stored_context_without_client_copies` (shape), plus the guard's own denial |
| 9 | Consent and enabled-state are re-checked at every persistence point, not once per request | `consent_boundary_error()` call sites | `test_resume_after_confirmation_blocks_persistence_when_consent_revoked_mid_turn`, `..._when_disabled_mid_turn` |
| 10 | Immutable Stop: a Stop landing mid-tool-execution blocks arming the card entirely | `is_active_job_cancelled()` check immediately before persisting the arm row | `test_stop_during_confirmation_tool_execution_persists_no_card`, `test_stop_during_confirmation_ask_round_trip_persists_no_card` |
| 11 | The Stop flag is visible across the per-request object cache | cache-drop inside `is_active_job_cancelled()` | `test_stop_flag_visible_across_request_cache_boundary` |
| 12 | Exactly one live card in the DOM; a newer card disables its predecessor first | `renderConfirmationCard()` (JS) | `tests/js/test-confirm-flow.js` |
| 13 | Fail closed on disabled/no-consent even from a stale open panel | independent re-checks at the top of `handle_confirm_action()` and `handle_cancel_action()` | `test_handle_confirm_action_requires_consent`, `test_handle_confirm_action_requires_assistant_enabled` |
| 14 | The cid=0 transient is single-use, expiring, and owner-checked, and its claim arbitrates a single winner on every object-cache backend | `MainWP_Assistant_Confirmation_State::peek_transient()` + `claim_transient()` (options-row INSERT); confirm and cancel both refuse on a lost claim, unconditionally | `test_confirm_action_stateless_transient_expired_refuses`, `..._cross_user_probe_does_not_burn`, `..._consumed_on_claim`, `test_claim_transient_reports_single_winner` |

## 4. Why it is the way it is

**Peek, then claim on an options row.** WordPress transients offer no peek-and-claim in one
call, so the cid=0 path peeks (validating ownership and ability) and then claims.
`claim_transient()` inserts a marker row named after the request token: the options table's
unique index on `option_name` makes the INSERT atomic, so exactly one of several requests
racing the same token gets a row inserted and every other gets zero rows back. Confirm and
cancel both go through it, so a concurrent double-Proceed executes once and a Proceed racing a
Cancel does not execute after the cancel settled the token.

The claim used to be the transient delete, whose rows-affected only arbitrates on the
options-table backend; under a persistent external object cache `delete_transient()` routes
through `wp_cache_delete()`, whose single-winner behaviour is backend-specific, and the handler
executed anyway rather than refusing spuriously. The marker row removes the backend dependency:
`consume_transient()` is now the payload drop only and its return means nothing about the claim.
Markers are swept by `purge_expired_claims()` on the next arm, once the pending transient they
arbitrate has been dead for twice its TTL.

**Reviewed params are encrypted at rest.** The stored context is what executes, so it cannot be
redacted, and `mainwp/add-site-v1` carries `adminpassword`, `http_pass` and `uniqueid`.
`build_context()` puts the credential-named values through the site-credential envelope
(`MainWP_Credential_Storage`) before the row or the transient is written, and `peek_row()` /
`peek_transient()` decrypt them on the way back out. The model-facing `tool_calls` copy repeats
those arguments, so it gets the display redaction instead: it is replayed as conversation
context and never executed.

**The CAS anchors on `MAX(id)`.** A claim is only valid against the newest message row in the
conversation. Anchoring on the passed-in message id alone would let a stale tab settle an old,
superseded confirmation while a newer one is live.

**`request_id` pinning.** The card in the browser displays exactly one request. If the stored
pending state has moved on (new turn, re-ask, expiry), a claim carrying the old `request_id` is
refused as superseded rather than being applied to whatever is pending now.

**Stored params win.** The parameters shown on the card at arm time are the parameters that
execute. POSTed copies from the browser exist only for the opt-out wire format and are never
trusted; this closes tampering between render and confirm.

**The wire trim.** `pending_confirmation` holds model-facing `tool_calls`/`tool_results` copies
because the model needs them verbatim to resume. The browser does not, so they are stripped
from `confirmation_data` on every path that leaves the server (arm response and reload payload).

**The empty-content `tool_calls` assistant message.** When resuming after a confirmation, the
transcript replayed to the provider must contain the assistant turn that issued the tool call,
even though it has no text content. gpt-5.5 rejects resumed threads without it
(`prompt_invalid_argument`), and its absence also caused the model to lose state and re-ask.

**Arm failure degrades to plain text.** If the transient write fails on the cid=0 path, no card
is rendered and the reply answers plainly without the ability's action chip. A card whose
backing state was never persisted could never be honored, and rendering one would strand the
user on a dead control.

**The 15-minute transient TTL.** Opt-out users asked not to have history stored, so their
pending context lives server-side only transiently: long enough for a human decision, short
enough to bound how long un-asked-for state survives. Expiry surfaces as `confirm_expired` and
the model simply asks again.

**Stop is checked before the arm row is persisted.** A persisted "please confirm" row re-arms
the card on every reload. If Stop lands while the tool call is still executing, persisting the
row would resurrect a turn the user explicitly killed — so the check sits before the write, not
after.

**History opt-out deletes before it flips** (`ajax_save_history_settings()` in
`MainWP_Assistant_Settings`). The opt-out flag unlocks the stateless confirm path, so it
must never be set while stored confirmations could still survive a failed delete.

## 5. Modification rules

- Run all of: `tests/assistant/test-assistant-chat.php`, `tests/assistant/test-assistant-guardrails.php`,
  and `tests/js/test-confirm-flow.js` for any change in this flow. The JS file contains
  drift-guard tests that mirror production functions (`resolveSendIntercept()` ↔
  `sendMessage()`'s intercept, `cancelNoticeKey()` ↔ `persistCancellation()`,
  `settledRefusalPlan()` ↔ `refreshSettledConversation()`); update both sides together or the
  guards fail by design.
- New storage location for confirmation state → add it to the map in section 1 and pin it with
  an invariant test.
- SSE events must never be named `error` — it is a reserved EventSource name and collides with
  the transport's own error event, causing reconnect loops.
- Ability schema changes require bumping `ABILITY_SCHEMA_CACHE_REV`, or stale cached schemas
  keep serving the old shape.
- Assistant tests run against a stub-free WordPress test harness; see the suite ordering note in
  `test-assistant-settings-ajax.php` (`skip_if_capability_checks_bypassed()`) before adding
  capability-denial assertions.

## 6. The state owner

The consolidation this section used to defer is done: the transitions live in
`MainWP_Assistant_Confirmation_State` (`class/class-mainwp-assistant-confirmation-state.php`),
a singleton exposing arm (`build_context()`, `arm_row()`, `arm_transient()`), peek
(`peek_row()`, `peek_transient()`), claim (`claim_row()`, `claim_transient()`), consume
(`consume_transient()`), and close (`close()`, `close_cancelled()`). The storage primitives stay put: the CAS remains
`MainWP_DB_Assistant::settle_confirmation_request()` (tests call it directly), and the owner
wraps it as `claim_row()`.

Two seams deliberately stay on `MainWP_Assistant`: the protected
`store_pending_confirmation_transient()` wrapper (tests subclass-override it to simulate arm
failure — the arm path must keep calling it rather than the owner directly), and the
`CONFIRMATION_TRANSIENT_PREFIX`/`CONFIRMATION_TRANSIENT_TTL` constants, which alias the
owner's. The reload re-arm read in `handle_load_conversation()` also stays where it is — it
trims an already-fetched row for the wire and is not a state transition.

Unit tests for the transitions themselves live in
`tests/assistant/test-assistant-confirmation-state.php`; the section 3 handler tests remain
the end-to-end contract.
