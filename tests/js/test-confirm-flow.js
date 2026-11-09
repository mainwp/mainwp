/**
 * Confirmation-flow characterization tests.
 *
 * Mirrors the typed-confirmation branches in
 * assets/js/mainwp-mintlify-chat.js: classifyAckLocally() normalization,
 * the sendMessage() pending-confirmation intercept, and the
 * buildConfirmationSummary() parameter filtering and humanization added
 * with the inline confirmation card.
 *
 * Run: node tests/js/test-confirm-flow.js
 *      composer test-js
 */

'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const { classifyAckLocally, humanizeParamKey, nextChatPollFallbackState } = require( '../../assets/js/mainwp-assistant-chat-logic.js' );

let passed = 0;
let failed = 0;

function runTest( name, fn ) {
	try {
		fn();
		passed++;
		console.log( `  ✓ ${ name }` );
	} catch ( err ) {
		failed++;
		console.log( `  ✗ ${ name }` );
		console.log( `    ${ err.message }` );
	}
}

// ─── Source-drift guard ─────────────────────────────────────────────────────────
// classifyAckLocally() and humanizeParamKey() are required straight from
// mainwp-assistant-chat-logic.js above — real coverage, not a copy. The
// functions below this point still duplicate logic that is inlined inside
// larger, side-effecting handlers in mainwp-mintlify-chat.js (the confirm/
// cancel money path, hardened over many review rounds) rather than living in
// standalone functions, so lifting them carries real behavior risk. Each of
// those specification functions is instead paired with an assertion that the
// exact tokens it depends on are still present verbatim in the source, so
// editing the real logic without updating this test fails loudly.

const CHAT_SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/mainwp-mintlify-chat.js' ),
	'utf8'
);

function assertSourceContains( description, needle ) {
	assert.ok(
		CHAT_SRC.includes( needle ),
		`drift guard failed (${ description }): expected mainwp-mintlify-chat.js to contain:\n${ needle }`
	);
}

/**
 * sendMessage() pending-confirmation intercept.
 *
 * Returns the branch taken: 'execute' (typed affirmative fires the pending
 * action), 'cancel' (typed negative dismisses it), 'abandon-and-send' (other
 * text drops the pending action and sends normally), or 'send' (no pending
 * confirmation, or an override bypasses the intercept).
 */
function resolveSendIntercept( pendingConfirmation, messageOverride, userMessage ) {
	if ( pendingConfirmation && ! messageOverride ) {
		const ack = classifyAckLocally( userMessage );
		if ( 'affirmative' === ack ) {
			return 'execute';
		}
		if ( 'negative' === ack ) {
			return 'cancel';
		}
		return 'abandon-and-send';
	}
	return 'send';
}

/**
 * buildConfirmationSummary() parameter row filter: whether a key/value pair
 * renders a row. Inlined in buildConfirmationSummary()'s Object.keys loop
 * (mainwp-mintlify-chat.js) rather than a standalone function, so this stays
 * a characterization copy; drift-guarded below.
 */
function paramRowRenders( key, value ) {
	if ( key === 'dry_run' || key === 'confirm' ) {
		return false;
	}
	if ( value === null || typeof value === 'undefined' || value === '' ) {
		return false;
	}
	if ( Array.isArray( value ) && value.length === 0 ) {
		return false;
	}
	return true;
}

runTest( 'drift guard: buildConfirmationSummary() param filter matches paramRowRenders()', () => {
	assertSourceContains( 'dry_run/confirm skip', "if ( key === 'dry_run' || key === 'confirm' ) {" );
	assertSourceContains( 'empty-value skip', "if ( value === null || typeof value === 'undefined' || value === '' ) {" );
	assertSourceContains( 'empty-array skip', 'if ( Array.isArray( value ) && value.length === 0 ) {' );
} );

// ─── classifyAckLocally ────────────────────────────────────────────────────────

console.log( '\nclassifyAckLocally()' );

runTest( 'bare affirmatives match', () => {
	assert.equal( classifyAckLocally( 'confirmed' ), 'affirmative' );
	assert.equal( classifyAckLocally( 'yes' ), 'affirmative' );
	assert.equal( classifyAckLocally( 'go ahead' ), 'affirmative' );
	assert.equal( classifyAckLocally( 'yes please' ), 'affirmative' );
} );

runTest( 'case, whitespace, and punctuation are normalized', () => {
	assert.equal( classifyAckLocally( '  Yes!  ' ), 'affirmative' );
	assert.equal( classifyAckLocally( 'Do it.' ), 'affirmative' );
	assert.equal( classifyAckLocally( 'CANCEL' ), 'negative' );
} );

runTest( 'negatives match', () => {
	assert.equal( classifyAckLocally( 'no' ), 'negative' );
	assert.equal( classifyAckLocally( 'never mind' ), 'negative' );
	assert.equal( classifyAckLocally( 'stop!' ), 'negative' );
} );

runTest( 'longer messages and non-acks return null', () => {
	assert.equal( classifyAckLocally( 'yes, sync all my sites now please' ), null );
	assert.equal( classifyAckLocally( 'maybe' ), null );
	assert.equal( classifyAckLocally( '' ), null );
	assert.equal( classifyAckLocally( '???' ), null );
} );

// ─── sendMessage intercept ─────────────────────────────────────────────────────

console.log( '\nsendMessage() pending-confirmation intercept' );

const PENDING = { ability: 'mainwp/sync-sites-v1', params: {} };

runTest( 'typed affirmative executes the pending action', () => {
	assert.equal( resolveSendIntercept( PENDING, null, 'confirmed' ), 'execute' );
} );

runTest( 'typed negative cancels the pending action', () => {
	assert.equal( resolveSendIntercept( PENDING, null, 'cancel' ), 'cancel' );
} );

runTest( 'other text abandons the pending action and sends normally', () => {
	assert.equal( resolveSendIntercept( PENDING, null, 'what about child6-3?' ), 'abandon-and-send' );
} );

runTest( 'no pending confirmation sends normally', () => {
	assert.equal( resolveSendIntercept( null, null, 'confirmed' ), 'send' );
} );

runTest( 'regeneration/escalation overrides bypass the intercept', () => {
	assert.equal( resolveSendIntercept( PENDING, 'regenerated message', 'confirmed' ), 'send' );
} );

runTest( 'drift guard: sendMessage() pending-confirmation intercept matches resolveSendIntercept()', () => {
	assertSourceContains( 'intercept guard', 'if ( pendingConfirmationData && ! messageOverride ) {' );
	assertSourceContains( 'ack classification call', 'let ack = classifyAckLocally( userMessage );' );
	assertSourceContains( 'affirmative branch', "if ( 'affirmative' === ack ) {" );
	assertSourceContains( 'negative branch', "if ( 'negative' === ack ) {" );
} );

// ─── buildConfirmationSummary filtering ────────────────────────────────────────

console.log( '\nbuildConfirmationSummary() parameter filtering' );

runTest( 'empty arrays, empty strings, and null are skipped', () => {
	assert.equal( paramRowRenders( 'site_ids_or_domains', [] ), false );
	assert.equal( paramRowRenders( 'site_ids', [] ), false );
	assert.equal( paramRowRenders( 'exclude_ids', [] ), false );
	assert.equal( paramRowRenders( 'tag', '' ), false );
	assert.equal( paramRowRenders( 'client_id', null ), false );
	assert.equal( paramRowRenders( 'type', undefined ), false );
} );

runTest( 'dry_run and confirm control keys are skipped', () => {
	assert.equal( paramRowRenders( 'dry_run', true ), false );
	assert.equal( paramRowRenders( 'confirm', true ), false );
} );

runTest( 'real values render', () => {
	assert.equal( paramRowRenders( 'site_ids', [ 5 ] ), true );
	assert.equal( paramRowRenders( 'slug', 'twentytwentyfive' ), true );
	assert.equal( paramRowRenders( 'site_id', 0 ), true );
	assert.equal( paramRowRenders( 'enabled', false ), true );
} );

// ─── humanizeParamKey ──────────────────────────────────────────────────────────

console.log( '\nhumanizeParamKey()' );

runTest( 'mapped keys get friendly labels', () => {
	assert.equal( humanizeParamKey( 'site_ids_or_domains' ), 'Sites' );
	assert.equal( humanizeParamKey( 'exclude_ids' ), 'Excluded sites' );
} );

runTest( 'unmapped keys are title-cased', () => {
	assert.equal( humanizeParamKey( 'theme_slug' ), 'Theme Slug' );
	assert.equal( humanizeParamKey( 'per_page' ), 'Per Page' );
} );

// ─── persistCancellation outcome notice ────────────────────────────────────────

/**
 * cancelNoticeKey() — which l10n notice the cancel response renders.
 *
 * Duplicates the persistCancellation() response handling: the notice comes
 * from the server's answer. A lost claim (already_settled) swaps in the
 * corrective text, and a successful response that cancelled nothing renders
 * the nothing-pending notice — the conversation moved past the request
 * before the cancel arrived (a racing resolver settled AND closed it), so
 * "Cancelled." would claim something that did not happen. Only a confirmed
 * server-side cancel earns the plain "Cancelled. The action was not
 * executed." — a transport failure or an error reply means the pending
 * action may still be armed, so those render the unconfirmed notice
 * instead of a false safety claim. (The no-conversation opt-out dismissal
 * keeps the plain notice: nothing was armed server-side to begin with.)
 */
function cancelNoticeKey( response ) {
	if ( response && response.data && response.data.already_settled ) {
		return 'cancel_already_settled';
	}
	if ( response && response.success && response.data && ! response.data.cancelled ) {
		return 'cancel_nothing_pending';
	}
	if ( response && response.success ) {
		return 'confirm_cancelled';
	}
	return 'confirm_cancel_unconfirmed';
}

console.log( '\ncancelNoticeKey() outcome notice selection' );

runTest( 'a settled cancel renders the plain cancelled notice', () => {
	assert.equal( cancelNoticeKey( { success: true, data: { cancelled: true } } ), 'confirm_cancelled' );
} );

runTest( 'a lost claim renders the corrective already-settled notice', () => {
	assert.equal( cancelNoticeKey( { success: true, data: { cancelled: false, already_settled: true } } ), 'cancel_already_settled' );
} );

runTest( 'a nothing-pending no-op renders the nothing-to-cancel notice', () => {
	assert.equal( cancelNoticeKey( { success: true, data: { cancelled: false } } ), 'cancel_nothing_pending' );
} );

runTest( 'transport failure and error replies render the unconfirmed notice, never a false safety claim', () => {
	assert.equal( cancelNoticeKey( null ), 'confirm_cancel_unconfirmed' );
	assert.equal( cancelNoticeKey( { success: false } ), 'confirm_cancel_unconfirmed' );
} );

runTest( 'drift guard: persistCancellation() response branches match cancelNoticeKey()', () => {
	assertSourceContains( 'already_settled branch', 'if ( response && response.data && response.data.already_settled ) {' );
	assertSourceContains( 'nothing-pending branch', 'if ( response && response.success && response.data && ! response.data.cancelled ) {' );
	assertSourceContains( 'confirmed-cancel gate', 'if ( response && response.success ) {' );
	assertSourceContains( 'unconfirmed-cancel notice', "l10n( 'confirm_cancel_unconfirmed'," );
} );

// ─── refreshSettledConversation plan ───────────────────────────────────────────

/**
 * settledRefusalPlan() — whether an already_settled refusal reloads the
 * conversation before rendering the corrective notice.
 *
 * Duplicates the refreshSettledConversation() precondition logic: a refresh
 * needs a positive conversation id (history opt-out has nothing to reload)
 * and an enabled input (loadConversation()'s disabled-input guard would
 * silently swallow the load, so the deferred notice would never render).
 * When either precondition fails, the notice renders immediately — the
 * pre-refresh behavior. Both refusal paths (confirm error and cancel .done)
 * route through the same helper, so one plan covers both.
 */
function settledRefusalPlan( conversationId, inputDisabled ) {
	let id = parseInt( conversationId, 10 );
	if ( ! Number.isInteger( id ) || id <= 0 || inputDisabled ) {
		return 'notice-only';
	}
	return 'refresh-then-notice';
}

console.log( '\nsettledRefusalPlan() stale-tab refresh decision' );

runTest( 'history-on with enabled input refreshes, notice deferred to onLoaded', () => {
	assert.equal( settledRefusalPlan( 171, false ), 'refresh-then-notice' );
	assert.equal( settledRefusalPlan( '171', false ), 'refresh-then-notice' );
} );

runTest( 'history opt-out (no conversation id) renders the notice immediately', () => {
	assert.equal( settledRefusalPlan( null, false ), 'notice-only' );
	assert.equal( settledRefusalPlan( 0, false ), 'notice-only' );
	assert.equal( settledRefusalPlan( undefined, false ), 'notice-only' );
} );

runTest( 'a disabled input skips the refresh the load guard would swallow', () => {
	assert.equal( settledRefusalPlan( 171, true ), 'notice-only' );
} );

runTest( 'garbage conversation ids never attempt a refresh', () => {
	assert.equal( settledRefusalPlan( 'abc', false ), 'notice-only' );
	assert.equal( settledRefusalPlan( -3, false ), 'notice-only' );
} );

runTest( 'drift guard: refreshSettledConversation() precondition matches settledRefusalPlan()', () => {
	assertSourceContains( 'refresh precondition', "if ( ! Number.isInteger( conversationId ) || conversationId <= 0 || $input.prop( 'disabled' ) ) {" );
} );

// ─── persistCancellation dispatch plan ─────────────────────────────────────────

/**
 * persistCancellationPlan() — whether a cancel posts to the server at all.
 *
 * Duplicates the persistCancellation() precondition logic: a history-on
 * cancel settles a conversation row, and a history opt-out cancel with a
 * request token consumes the server-held confirmation transient — either
 * one needs the POST. Only when neither exists (opt-out card armed before
 * the transient path, or a failed transient write) is the local dismissal
 * the whole outcome.
 */
function persistCancellationPlan( conversationId, requestId ) {
	if ( ! conversationId && ! requestId ) {
		return 'notice-only';
	}
	return 'post-cancel';
}

console.log( '\npersistCancellationPlan() server-dispatch decision' );

runTest( 'history-on cancels post regardless of request token', () => {
	assert.equal( persistCancellationPlan( 171, 42 ), 'post-cancel' );
	assert.equal( persistCancellationPlan( 171, '' ), 'post-cancel' );
} );

runTest( 'opt-out with a request token posts so the transient is consumed', () => {
	assert.equal( persistCancellationPlan( 0, '3b9d8c5e-3f4a-4a11-9c3e-2f1d0a7b6c5d' ), 'post-cancel' );
	assert.equal( persistCancellationPlan( null, '3b9d8c5e-3f4a-4a11-9c3e-2f1d0a7b6c5d' ), 'post-cancel' );
} );

runTest( 'opt-out with no token dismisses locally', () => {
	assert.equal( persistCancellationPlan( 0, '' ), 'notice-only' );
	assert.equal( persistCancellationPlan( null, undefined ), 'notice-only' );
} );

runTest( 'drift guard: persistCancellation() dispatch precondition matches persistCancellationPlan()', () => {
	assertSourceContains( 'dispatch precondition', 'if ( ! conversationId && ! requestId ) {' );
} );

// ─── Batch Stop truthfulness ─────────────────────────────────────────────────────
// There is no server-side batch-cancel endpoint, so handleStopGeneration()'s
// batch branch must not claim the operation stopped and must not discard the
// localStorage resume record (clearActiveJob( true ) preserves it). These live
// inside the side-effecting Stop handler, so they are pinned as source-drift
// guards rather than lifted, matching the confirm/cancel guards above.

console.log( '\nbatch Stop preserves resume state and tells the truth' );

runTest( 'drift guard: batch Stop preserves the localStorage resume record', () => {
	// clearActiveJob only removes STORAGE_KEY when NOT told to preserve it, so
	// a reload can still resume a batch that Stop could not actually cancel.
	assertSourceContains( 'preserve guard', 'if ( ! preserveResumeState ) {' );
	assertSourceContains( 'batch Stop keeps resume state', 'clearActiveJob( true );' );
} );

runTest( 'drift guard: batch Stop uses the honest stop-monitoring message', () => {
	// The batch branch must not reuse generation_stopped (which claims the
	// operation ended); it announces that the operation keeps running.
	assertSourceContains( 'honest batch-stop message key', "l10n( 'batch_stop_monitoring'," );
} );

runTest( 'drift guard: New Chat keeps the resolved provider check', () => {
	// reset() used to set providerCheckComplete = false and never re-ran
	// checkProviderAvailability(), leaving sendMessage()'s readiness gate
	// closed until a page reload. Provider availability does not change
	// because a conversation was reset, so the flag survives New Chat. The
	// declaration at the top of the file uses a comma, so the semicolon
	// form below is unique to the removed reset() line.
	assert.ok(
		! CHAT_SRC.includes( 'providerCheckComplete = false;' ),
		'drift guard failed: reset() clears providerCheckComplete again — New Chat would block sending until reload'
	);
	assertSourceContains(
		'reset() preserves the provider check',
		'// providerCheckComplete deliberately survives New Chat: provider'
	);
} );

runTest( 'drift guard: New Chat cancels a running chat job server-side like Stop does', () => {
	// reset() must tell the server before discarding the client handle —
	// otherwise New Chat silently leaves the job running (and billing)
	// unattended. The needle carries reset()-unique context: in
	// handleStopGeneration the clearChatJob() call sits INSIDE the if.
	assertSourceContains(
		'reset() server-cancel before clearChatJob',
		'if ( activeChatJob ) {\n            requestServerCancel( activeChatJob.job_id );\n        }\n        clearChatJob();'
	);
} );

// ─── Chat poll fallback decision ─────────────────────────────────────────────────
// nextChatPollFallbackState() is required straight from
// mainwp-assistant-chat-logic.js — real coverage. Only persistent 'queued'
// (no worker has claimed the job, the WP-Cron-dead signal) may trigger the
// synchronous fallback; a 'processing' reply proves a live worker owns the
// job and must reset the counter so the fallback can never steal it.

console.log( '\nnextChatPollFallbackState() fallback decision' );

const FALLBACK_AFTER = 5;

function runPollSequence( statuses ) {
	let count = 0;
	let fired = false;
	for ( const status of statuses ) {
		const next = nextChatPollFallbackState( status, count, FALLBACK_AFTER );
		count = next.queuedCount;
		if ( next.triggerFallback ) {
			fired = true;
		}
	}
	return { count, fired };
}

runTest( 'five consecutive queued polls trigger the fallback', () => {
	const result = runPollSequence( [ 'queued', 'queued', 'queued', 'queued', 'queued' ] );
	assert.equal( result.fired, true );
} );

runTest( 'four queued polls do not trigger the fallback', () => {
	const result = runPollSequence( [ 'queued', 'queued', 'queued', 'queued' ] );
	assert.equal( result.fired, false );
	assert.equal( result.count, 4 );
} );

runTest( 'a processing reply proves a live worker and resets the counter', () => {
	const result = runPollSequence( [ 'queued', 'queued', 'queued', 'queued', 'processing', 'queued' ] );
	assert.equal( result.fired, false );
	assert.equal( result.count, 1 );
} );

runTest( 'a running worker is never stolen no matter how long it runs', () => {
	const result = runPollSequence( new Array( 20 ).fill( 'processing' ) );
	assert.equal( result.fired, false );
	assert.equal( result.count, 0 );
} );

runTest( 'other statuses reset the counter too', () => {
	assert.deepEqual( nextChatPollFallbackState( 'completed', 4, FALLBACK_AFTER ), { queuedCount: 0, triggerFallback: false } );
	assert.deepEqual( nextChatPollFallbackState( 'failed', 4, FALLBACK_AFTER ), { queuedCount: 0, triggerFallback: false } );
} );

runTest( 'drift guard: pollChatStatus() routes queued/processing through the helper', () => {
	assertSourceContains( 'queued/processing poll branch', "if ( statusData.status === 'processing' || statusData.status === 'queued' ) {" );
	assertSourceContains( 'fallback state call', 'nextChatPollFallbackState( statusData.status, chatPollQueuedCount, CHAT_POLL_QUEUED_FALLBACK )' );
} );

// ─── Chat job lifecycle races ────────────────────────────────────────────────────
// Three guards against stale async callbacks settling the wrong job, all
// inlined in side-effecting handlers (poll AJAX, SSE listeners, the sync
// fallback), so they are pinned as source-drift guards: a single-flight
// boolean on chat polling (mirroring the batch isPollInFlight pattern),
// tracked SSE timers that die with the job, and a job-ID identity gate on
// every completion path.

console.log( '\nchat job lifecycle race guards' );

runTest( 'drift guard: chat polling is single-flight like batch polling', () => {
	assertSourceContains( 'chat poll single-flight guard', 'if ( isChatPollInFlight ) {' );
	assertSourceContains( 'chat poll in-flight set', 'isChatPollInFlight = true;' );
	// clearChatJob() resets the flag so an aborted job never wedges polling.
	assertSourceContains(
		'clearChatJob resets the in-flight flag',
		'activeChatJob = null;\n        chatPollQueuedCount = 0;\n        chatPollRetryCount = 0;\n        isChatPollInFlight = false;'
	);
} );

runTest( 'drift guard: poll/SSE/fallback callbacks are gated on the current job id', () => {
	assertSourceContains( 'job identity helper', 'function isCurrentChatJob( jobId ) {' );
	assertSourceContains( 'job identity check', 'return !! ( activeChatJob && activeChatJob.job_id === jobId );' );
	assertSourceContains( 'stale-callback gate', 'if ( ! isCurrentChatJob( jobId ) ) {' );
} );

runTest( 'drift guard: SSE timers are tracked and die with the connection', () => {
	// The reconnect setTimeout is stored, not fire-and-forget…
	assertSourceContains( 'tracked reconnect timer', 'sseReconnectTimeout = setTimeout( function() {' );
	// …and closeSSEConnection() cancels both pending timers, so a cleared or
	// superseded job cannot be revived by a stale reconnect/connect-timeout.
	assertSourceContains(
		'closeSSEConnection clears the connect timeout',
		'if ( sseConnectionTimeout ) {\n            clearTimeout( sseConnectionTimeout );\n            sseConnectionTimeout = null;\n        }'
	);
	assertSourceContains(
		'closeSSEConnection clears the reconnect timer',
		'if ( sseReconnectTimeout ) {\n            clearTimeout( sseReconnectTimeout );\n            sseReconnectTimeout = null;\n        }'
	);
} );

// ─── A11y contracts ──────────────────────────────────────────────────────────────
// Markup built in JS template strings, so screen-reader/keyboard contracts
// are pinned as source-drift guards; the rendered behavior is verified in
// the browser.

console.log( '\na11y contracts' );

runTest( 'drift guard: the send button announces its Stop state', () => {
	// setSendButtonStop()/setSendButtonDefault() swap the icon; the
	// accessible name must move with it or the button reads "Send message"
	// while it functions as Stop.
	assertSourceContains( 'stop-state label', ".attr( 'aria-label', l10n( 'stop_generation', 'Stop' ) )" );
	assertSourceContains( 'send-state label', ".attr( 'aria-label', l10n( 'send_message', 'Send message' ) )" );
} );

runTest( 'drift guard: the batch errors toggle is a real button with expand state', () => {
	assertSourceContains(
		'errors header is a button with aria wiring',
		'<button type="button" class="mainwp-batch-errors-header" aria-expanded="false" aria-controls="'
	);
	// The list id is unique per render — several batch results can share one
	// conversation and aria-controls needs an unambiguous target.
	assertSourceContains( 'unique list id per render', "let listId = 'mainwp-batch-errors-list-' + batchErrorsListSeq;" );
	// The inline onclick is gone; a delegated handler flips aria-expanded.
	assert.ok(
		! CHAT_SRC.includes( 'onclick="jQuery(this).next().toggle()"' ),
		'drift guard failed: the batch errors header regressed to an inline onclick with no keyboard access'
	);
	assertSourceContains(
		'delegated toggle flips aria-expanded',
		"$( document ).on( 'click.mintlify', '.mainwp-batch-errors-header', function() {"
	);
} );

// ─── Summary ───────────────────────────────────────────────────────────────────

console.log( `\n${ passed } passed, ${ failed } failed` );
process.exit( failed > 0 ? 1 : 0 );
