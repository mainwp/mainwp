/**
 * Mode-guard characterization tests.
 *
 * Mirrors the server-response guard branches in
 * assets/js/mainwp-mintlify-chat.js that decide whether to call
 * updateModeIndicator and with what arguments, plus the truthful-receipt
 * convergence rule: the pills are the request, the message badge is the
 * receipt, and an explicit Agent request answered by docs converges the UI.
 *
 * State variables (valid values):
 *   userModeOverride  — null | 'ask' | 'agent'
 *   availableProviderName — null | false | string (e.g. 'OpenAI')
 *
 * Run: node tests/js/test-mode-guards.js
 *      composer test-js
 */

'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const {
	isAgentRefusal,
	resolveTransportFailureRecovery,
	resolveTransportRecoveryEligibility,
	resolveRegenerateAvailability,
	resolveInputRestoreOnTransportFailure,
	normalizeStoredSources
} = require( '../../assets/js/mainwp-assistant-chat-logic.js' );

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
// isAgentRefusal(), resolveTransportFailureRecovery(),
// resolveTransportRecoveryEligibility(), resolveInputRestoreOnTransportFailure(),
// and normalizeStoredSources() are required straight from
// mainwp-assistant-chat-logic.js above — real coverage, not a copy. The
// specification functions below this point still mirror if/else chains and
// preconditions that are inlined inside larger, side-effecting handlers in
// mainwp-mintlify-chat.js rather than living in standalone functions, so
// lifting them carries real behavior risk. Each is instead paired with an
// assertion that the exact tokens it depends on are still present verbatim in
// the source, so editing the real logic without updating this test fails
// loudly.

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

// ─── Specification functions ───────────────────────────────────────────────────
// Each duplicates the exact if/else chain from one source location.
// state: { override, availProvider }; data: server response fields.
// Returns { converge: true, mode: 'ask', provider: null } when the convergence
// branch fires, { mode, provider } for a plain updateModeIndicator call, or
// null (no call).

/**
 * sendToAPI success handler.
 */
function resolveResponseMode( state, data ) {
	if ( isAgentRefusal( data, state.override ) ) {
		return { converge: true, mode: 'ask', provider: null };
	}
	if ( state.override ) {
		return { mode: state.override, provider: state.override === 'agent' ? state.availProvider : null };
	}
	if ( data.mode === 'ask' ) {
		return { mode: 'ask', provider: null };
	}
	if ( data.provider_name ) {
		return { mode: 'agent', provider: data.provider_name };
	}
	if ( data.provider ) {
		return { mode: 'agent', provider: data.provider };
	}
	return null;
}

/**
 * Async chat poll completion.
 *
 * Carries the same mismatch branch for shape symmetry, though async results
 * are always mode 'agent' (jobs are only queued after the route resolved to
 * agent), so it never fires in practice.
 */
function resolveAsyncMode( state, data ) {
	if ( isAgentRefusal( data, state.override ) ) {
		return { converge: true, mode: 'ask', provider: null };
	}
	if ( state.override ) {
		return { mode: state.override, provider: state.override === 'agent' ? state.availProvider : null };
	}
	if ( data.mode === 'ask' ) {
		return { mode: 'ask', provider: null };
	}
	if ( data.provider_name ) {
		return { mode: 'agent', provider: data.provider_name };
	}
	return null;
}

runTest( 'drift guard: sendToAPI success handler matches resolveResponseMode()', () => {
	assertSourceContains(
		'sendToAPI mode chain',
		"if ( isAgentRefusal( response.data, userModeOverride ) ) {\n" +
		"                    convergeAfterAgentRefusal();\n" +
		"                } else if ( userModeOverride ) {\n" +
		"                    updateModeIndicator( userModeOverride, userModeOverride === 'agent' ? availableProviderName : null );\n" +
		"                } else if ( response.data.mode === 'ask' ) {\n" +
		"                    updateModeIndicator( 'ask', null );\n" +
		"                } else if ( response.data.provider_name ) {"
	);
} );

runTest( 'drift guard: handleChatJobComplete matches resolveAsyncMode()', () => {
	assertSourceContains(
		'async poll mode chain',
		"if ( isAgentRefusal( resultData, userModeOverride ) ) {\n" +
		"            convergeAfterAgentRefusal();\n" +
		"        } else if ( userModeOverride ) {\n" +
		"            updateModeIndicator( userModeOverride, userModeOverride === 'agent' ? availableProviderName : null );\n" +
		"        } else if ( resultData.mode === 'ask' ) {\n" +
		"            updateModeIndicator( 'ask', null );\n" +
		"        } else if ( resultData.provider_name ) {"
	);
} );

/**
 * loadConversation.
 * Mode comes from stored conversation metadata (a different provenance than
 * live routing), so no receipt/convergence logic applies here. An explicit
 * load must move requestMode together with the override — the pill it paints
 * IS the mode the next request posts (data.mode = modeOverride ||
 * requestMode), so a stale requestMode would post a different mode. localStorage is deliberately NOT touched: viewing an
 * old conversation is not a preference change.
 * Returns { mode, provider, newOverride, newRequestMode } or null (no
 * updateModeIndicator call, requestMode untouched).
 */
function resolveLoadMode( override, isSilentRestore, serverMode, serverProviderName, knownProvider ) {
	if ( ! isSilentRestore ) {
		if ( serverMode === 'agent' && serverProviderName && false !== knownProvider ) {
			return { mode: 'agent', provider: serverProviderName, newOverride: 'agent', newRequestMode: 'agent' };
		}
		return { mode: 'ask', provider: null, newOverride: 'ask', newRequestMode: 'ask' };
	}
	// Silent restore — no updateModeIndicator call.
	return null;
}

/**
 * Confirm-action handler.
 *
 * This endpoint is agent-only (it only ever returns mode 'agent'), so the
 * badge derives from the response — no user-override branch: honouring a
 * pill switched while the confirmation was pending would mislabel the badge.
 * Falls back to a generic Agent badge when an AI summary is present without
 * a provider name.
 */
function resolveConfirmMode( serverProviderName, hasAiResponse ) {
	if ( serverProviderName ) {
		return { mode: 'agent', provider: serverProviderName };
	}
	if ( hasAiResponse ) {
		return { mode: 'agent', provider: null };
	}
	return null;
}

/**
 * Mode sent with each chat request (sendToAPI).
 * requestMode is a state variable (never derived from the DOM); an escalation
 * passes a one-shot modeOverride without touching it.
 */
function resolveSentMode( requestMode, modeOverride ) {
	return modeOverride || requestMode;
}

/**
 * Initial requestMode from the persisted preference (module-init IIFE).
 * Legacy ai/docs values migrate; anything else (stored 'auto', missing key)
 * defaults to 'ask'.
 */
function resolveInitialRequestMode( saved ) {
	if ( saved === 'ai' ) {
		saved = 'agent';
	} else if ( saved === 'docs' ) {
		saved = 'ask';
	}
	return ( saved === 'agent' || saved === 'ask' ) ? saved : 'ask';
}

/**
 * Both modes keep the pill and info bar in sync with the indicator.
 */
function resolveIndicatorDisplay() {
	return 'full';
}

/**
 * Receipt attribution label (appendAssistantMessage): Sites receipts carry the
 * serving provider when known, so a Sites answer names who produced it.
 */
function resolveAttributionLabel( mode, provider ) {
	let label = mode === 'agent' ? 'Sites' : 'Docs';
	if ( mode === 'agent' && provider && typeof provider === 'string' ) {
		label += ' · ' + provider;
	}
	return label;
}

/**
 * SSE reconnect budget simulation, mirroring startSSEConnection's counter
 * logic (mainwp-mintlify-chat.js): sseReconnectCount starts at 0 per job
 * (handleAsyncChat), increments in onerror, and a reconnect is scheduled only
 * while count <= SSE_MAX_RECONNECTS — otherwise the client falls back to
 * polling. The budget refills ONLY on meaningful server events
 * (status/heartbeat listeners); onopen deliberately does not refill it.
 *
 * events: array of 'open' | 'status' | 'heartbeat' | 'error'.
 * opts.resetOnOpen re-enables the pre-fix onopen reset to document the bug it
 * caused (an open-then-die server refilled the budget on every attempt).
 * Returns { outcome: 'sse' | 'polling', reconnects, unsupportedMarked }.
 *
 * unsupportedMarked mirrors the session-wide markSSEUnsupported() flag: the
 * reconnect-budget path never sets it — exhausting one job's budget is
 * evidence about that job, not about SSE capability, so only that job falls
 * back to polling and the next job attempts SSE again. Only the
 * connect-timeout path (simulateConnectTimeout below) marks the session.
 */
function simulateSSEReconnects( events, opts = {} ) {
	const SSE_MAX_RECONNECTS = 2;
	let count = 0;
	let reconnects = 0;

	for ( const event of events ) {
		if ( event === 'open' ) {
			if ( opts.resetOnOpen ) {
				count = 0;
			}
		} else if ( event === 'status' || event === 'heartbeat' ) {
			count = 0;
		} else if ( event === 'error' ) {
			count++;
			if ( count <= SSE_MAX_RECONNECTS ) {
				reconnects++;
			} else {
				return { outcome: 'polling', reconnects, unsupportedMarked: false };
			}
		}
	}

	return { outcome: 'sse', reconnects, unsupportedMarked: false };
}

/**
 * Connect-timeout path, mirroring startSSEConnection's connectionTimeout
 * callback: the EventSource never left CONNECTING within
 * SSE_CONNECTION_TIMEOUT, the one signal that genuinely means this
 * environment can't do SSE (proxy/hosting blocks it), so the session-wide
 * unsupported flag is set and the job falls back to polling.
 */
function simulateConnectTimeout() {
	return { outcome: 'polling', unsupportedMarked: true };
}

// resolveTransportFailureRecovery(), resolveTransportRecoveryEligibility(),
// resolveInputRestoreOnTransportFailure(), and normalizeStoredSources() are
// no longer duplicated here — required from mainwp-assistant-chat-logic.js
// at the top of this file instead.

runTest( 'drift guard: loadConversation() mode restore matches resolveLoadMode()', () => {
	assertSourceContains(
		'explicit-load mode restore',
		"if ( ! isSilentRestore ) {\n" +
		"                        // Explicit load: match the conversation's mode and update the override.\n" +
		"                        // requestMode moves with the pill so the next request posts what\n" +
		"                        // the pill shows; the localStorage preference is NOT touched.\n" +
		"                        // A provider check that already found no provider (false) wins over the stored one.\n" +
		"                        if ( response.data.mode === 'agent' && response.data.provider_name && false !== availableProviderName ) {\n" +
		"                            availableProviderName = response.data.provider_name;\n" +
		"                            userModeOverride = 'agent';\n" +
		"                            requestMode = 'agent';\n" +
		"                            updateModeIndicator( 'agent', response.data.provider_name );\n" +
		"                        } else {\n" +
		"                            userModeOverride = 'ask';\n" +
		"                            requestMode = 'ask';\n" +
		"                            updateModeIndicator( 'ask', null );\n" +
		"                        }"
	);
} );

runTest( 'drift guard: executeConfirmedAction() response handling matches resolveConfirmMode()', () => {
	assertSourceContains(
		'confirm-action mode chain',
		"if ( response.data.provider_name ) {\n" +
		"                        availableProviderName = response.data.provider_name;\n" +
		"                        updateModeIndicator( 'agent', response.data.provider_name );\n" +
		"                    } else if ( response.data.ai_response ) {\n" +
		"                        updateModeIndicator( 'agent', null );\n" +
		"                    }"
	);
} );

runTest( 'drift guard: sendToAPI() sent-mode and the migration IIFE match resolveSentMode()/resolveInitialRequestMode()', () => {
	assertSourceContains( 'mode sent with request', "data.mode = modeOverride || requestMode;" );
	assertSourceContains( 'legacy ai/docs migration', "if ( 'ai' === saved ) {" );
	assertSourceContains( 'legacy docs migration', "} else if ( 'docs' === saved ) {" );
} );

runTest( 'drift guard: updateModeIndicator synchronizes both pills', () => {
	const body = CHAT_SRC.match( /function updateModeIndicator\( mode, providerName \) \{([\s\S]*?)\n    \}/ )[1];
	assert.ok( ! body.includes( 'return;' ) );
	assert.ok( body.includes( 'syncPillToggle( mode );' ) );
	assert.ok( body.includes( 'updateInfoBar( mode );' ) );
} );

runTest( 'drift guard: appendAssistantMessage() attribution label matches resolveAttributionLabel()', () => {
	assertSourceContains(
		'attribution label',
		"let modeLabel = badgeMode === 'agent'\n" +
		"            ? l10n( 'mode_agent', 'Sites' )\n" +
		"            : l10n( 'mode_ask', 'Docs' );\n" +
		"        if ( badgeMode === 'agent' && currentProvider && typeof currentProvider === 'string' ) {"
	);
} );

runTest( 'drift guard: startSSEConnection() reconnect budget matches simulateSSEReconnects()/simulateConnectTimeout()', () => {
	assertSourceContains( 'onopen does not refill the budget', 'activeSSEConnection.onopen = function() {\n            clearTimeout( sseConnectionTimeout );\n            sseSupported = true;\n        };' );
	assertSourceContains( 'status event refills the budget', "activeSSEConnection.addEventListener( 'status', function( e ) {\n            sseReconnectCount = 0;" );
	assertSourceContains( 'heartbeat event refills the budget', "activeSSEConnection.addEventListener( 'heartbeat', function() {\n            sseReconnectCount = 0;\n        } );" );
	assertSourceContains( 'reconnect-budget check', 'if ( sseReconnectCount <= SSE_MAX_RECONNECTS ) {' );
	assertSourceContains( 'connect-timeout marks session-wide unsupported', 'markSSEUnsupported();\n                startChatPolling( jobId );' );
} );

/**
 * checkProviderAvailability preference restore.
 *
 * checkState: 'error' (check failed) | 'unavailable' | 'available'.
 * Returns { indicator, needsSetup, override, clearPref, pill } describing the
 * calls the branch makes; provider name abstracted as 'PROVIDER'.
 */
function resolveAvailabilityRestore( checkState, savedPref ) {
	if ( checkState === 'error' ) {
		// Availability unknown: keep the preference, correct Agent to Ask.
		return savedPref === 'agent'
			? { indicator: { mode: 'ask', provider: null } }
			: {};
	}
	if ( checkState === 'unavailable' ) {
		const result = { needsSetup: true, indicator: { mode: 'ask', provider: null } };
		if ( savedPref === 'agent' ) {
			result.clearPref = true;
		}
		return result;
	}
	// Available.
	if ( savedPref === 'ask' ) {
		return { needsSetup: false, override: 'ask', indicator: { mode: 'ask', provider: null } };
	}
	if ( savedPref === 'agent' ) {
		return { needsSetup: false, indicator: { mode: 'agent', provider: 'PROVIDER' } };
	}
	// Unknown preferences use the Docs default.
	return { needsSetup: false, indicator: { mode: 'ask', provider: null } };
}

runTest( 'drift guard: checkProviderAvailability() preference restore matches resolveAvailabilityRestore()', () => {
	assertSourceContains( 'check-failed correction', "if ( savedPref === 'agent' ) {\n                        applyInitialMode( 'ask', null );\n                    }" );
	assertSourceContains( 'unavailable clears stale Agent pref', "if ( savedPref === 'agent' ) {\n                        localStorage.removeItem( 'mainwp_assistant_mode_preference' );\n                    }" );
	assertSourceContains( 'available + saved ask restore', "if ( savedPref === 'ask' ) {\n                        applyInitialMode( 'ask', null );\n                        if ( ! userModeOverride ) {\n                            userModeOverride = 'ask';\n                        }\n                    } else if ( savedPref === 'agent' ) {" );
} );

// ─── Test scenarios ────────────────────────────────────────────────────────────

console.log( '\nloadConversation (resolveLoadMode)' );

runTest( '1. Silent restore, override=ask, server=agent+provider → null', () => {
	const result = resolveLoadMode( 'ask', true, 'agent', 'OpenAI' );
	assert.equal( result, null );
} );

runTest( '2. Silent restore, override=null, server=agent+provider → null', () => {
	const result = resolveLoadMode( null, true, 'agent', 'OpenAI' );
	assert.equal( result, null );
} );

runTest( '3. Explicit click, Agent conv → agent mode, override=agent, requestMode=agent', () => {
	const result = resolveLoadMode( null, false, 'agent', 'OpenAI' );
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI', newOverride: 'agent', newRequestMode: 'agent' } );
} );

runTest( '4. Explicit click, Ask conv → ask mode, override=ask, requestMode=ask', () => {
	const result = resolveLoadMode( null, false, 'ask', null );
	assert.deepEqual( result, { mode: 'ask', provider: null, newOverride: 'ask', newRequestMode: 'ask' } );
} );

runTest( 'explicit load of a Sites conversation stays in Docs when no provider is available', () => {
	const result = resolveLoadMode( null, false, 'agent', 'OpenAI', false );
	assert.strictEqual( result.mode, 'ask' );
	assert.strictEqual( result.newRequestMode, 'ask' );
} );

runTest( 'Reload shows Regenerate only for a bound user/assistant tail without cancellation', () => {
	assertSourceContains( 'reload cancellation flag', 'let lastLoadedMessageCancelled = false;' );
	assertSourceContains( 'last loaded cancellation state', 'response.data.messages.forEach( function( msg ) {\n                            lastLoadedMessageCancelled = !! msg.cancelled;' );
	assertSourceContains(
		'reload Regenerate gate',
		"if ( lastAssistantMessageId && messageHistory.length >= 2 && lastMsg.role === 'assistant' && messageHistory[ messageHistory.length - 2 ].role === 'user' && ! lastLoadedMessageCancelled ) {\n                        showRegenerateLink();\n                    } else {\n                        hideRegenerateLinks();"
	);

	assertSourceContains( 'loaded message id', 'messageHistory.push( { role: msg.role, content: msg.content, message_id: msg.message_id } );' );

	function canRegenerate( messages ) {
		let lastLoadedMessageCancelled = false;
		const messageHistory = [];
		messages.forEach( msg => {
			lastLoadedMessageCancelled = !! msg.cancelled;
			messageHistory.push( { role: msg.role, content: msg.content, message_id: msg.message_id } );
		} );
		const lastMsg = messageHistory.length > 0 ? messageHistory[ messageHistory.length - 1 ] : null;
		return !! ( lastMsg && lastMsg.message_id ) && messageHistory.length >= 2 && lastMsg.role === 'assistant' && messageHistory[ messageHistory.length - 2 ].role === 'user' && ! lastLoadedMessageCancelled;
	}

	for ( const [ label, messages, expected ] of [
		[ 'answer', [ { role: 'user' }, { role: 'assistant', message_id: 42 } ], true ],
		[ 'unbound answer', [ { role: 'user' }, { role: 'assistant' } ], false ],
		[ 'confirmation closing', [ { role: 'assistant', content: 'request' }, { role: 'assistant', content: 'closing' } ], false ],
		[ 'system notice', [ { role: 'user' }, { role: 'system' } ], false ],
		[ 'typed cancel', [ { role: 'user', content: 'cancel' }, { role: 'assistant', cancelled: true } ], false ],
		[ 'empty history', [], false ],
		[ 'assistant only', [ { role: 'assistant' } ], false ],
	] ) {
		assert.equal( canRegenerate( messages ), expected, label );
	}
} );

console.log( '\nsendToAPI success (resolveResponseMode)' );

runTest( '5. Override=ask, server says agent → ask (override wins)', () => {
	const result = resolveResponseMode(
		{ override: 'ask', availProvider: null },
		{ mode: 'agent', requested_mode: 'ask', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'ask', provider: null } );
} );

runTest( '6. No override, server says agent (provider_name) → agent', () => {
	const result = resolveResponseMode(
		{ override: null, availProvider: null },
		{ mode: 'agent', requested_mode: 'auto', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI' } );
} );

runTest( '7. No override, server says agent (provider fallback) → agent', () => {
	const result = resolveResponseMode(
		{ override: null, availProvider: null },
		{ mode: 'agent', requested_mode: 'auto', provider: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI' } );
} );

runTest( '8. No override, server says ask → ask', () => {
	const result = resolveResponseMode(
		{ override: null, availProvider: null },
		{ mode: 'ask', requested_mode: 'ask' }
	);
	assert.deepEqual( result, { mode: 'ask', provider: null } );
} );

runTest( '9. Requested agent, server answered ask → converge (badge reads Ask)', () => {
	const result = resolveResponseMode(
		{ override: 'agent', availProvider: null },
		{ mode: 'ask', requested_mode: 'agent', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { converge: true, mode: 'ask', provider: null } );
} );

runTest( '9b. Old backend (no receipt fields), override=agent, mode ask → converge', () => {
	const result = resolveResponseMode(
		{ override: 'agent', availProvider: null },
		{ mode: 'ask' }
	);
	assert.deepEqual( result, { converge: true, mode: 'ask', provider: null } );
} );

runTest( '9c. Auto request answered from docs → plain Ask receipt, no convergence', () => {
	const result = resolveResponseMode(
		{ override: null, availProvider: 'OpenAI' },
		{ mode: 'ask', requested_mode: 'auto' }
	);
	assert.deepEqual( result, { mode: 'ask', provider: null } );
} );

console.log( '\nAsync poll completion (resolveAsyncMode)' );

runTest( '10. Override=ask, poll says agent → ask (override wins)', () => {
	const result = resolveAsyncMode(
		{ override: 'ask', availProvider: null },
		{ mode: 'agent', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'ask', provider: null } );
} );

runTest( '11. No override, poll says agent → agent', () => {
	const result = resolveAsyncMode(
		{ override: null, availProvider: null },
		{ mode: 'agent', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI' } );
} );

runTest( '11b. Symmetry: async agent result under agent override → no convergence', () => {
	const result = resolveAsyncMode(
		{ override: 'agent', availProvider: 'OpenAI' },
		{ mode: 'agent', provider_name: 'OpenAI' }
	);
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI' } );
} );

console.log( '\nConfirm-action handler (resolveConfirmMode)' );

runTest( '12. Provider present → agent badge (endpoint is agent-only, no override branch)', () => {
	const result = resolveConfirmMode( 'OpenAI', true );
	assert.deepEqual( result, { mode: 'agent', provider: 'OpenAI' } );
} );

runTest( '13. No provider name, AI summary present → generic agent badge', () => {
	const result = resolveConfirmMode( null, true );
	assert.deepEqual( result, { mode: 'agent', provider: null } );
} );

runTest( '14. No provider, no AI summary → null (no mode call)', () => {
	const result = resolveConfirmMode( null, false );
	assert.equal( result, null );
} );

console.log( '\nRequest mode (resolveSentMode / resolveInitialRequestMode)' );

runTest( '15. Docs pill sends ask', () => {
	assert.equal( resolveSentMode( 'ask', undefined ), 'ask' );
} );

runTest( '16. Agent pill sends agent; escalation override is one-shot', () => {
	assert.equal( resolveSentMode( 'agent', undefined ), 'agent' );
	assert.equal( resolveSentMode( 'ask', 'agent' ), 'agent' );
} );

runTest( '17. Missing or stored-auto preference initialises to ask', () => {
	assert.equal( resolveInitialRequestMode( null ), 'ask' );
	assert.equal( resolveInitialRequestMode( 'auto' ), 'ask' );
} );

runTest( 'Stored Auto and unknown preferences run the real migration as Docs', () => {
	const vm = require( 'vm' );
	const migration = CHAT_SRC.match( /    \( function\(\) \{\n        var saved = localStorage.getItem[\s\S]*?    \} \)\(\);/ )[0];
	for ( const stored of [ 'auto', 'unexpected', null, 'ai', 'docs', 'agent', 'ask' ] ) {
		const expected = stored === 'ai' || stored === 'agent' ? 'agent' : 'ask';
		const values = new Map( stored === null ? [] : [ [ 'mainwp_assistant_mode_preference', stored ] ] );
		const context = { requestMode: 'ask', currentMode: 'ask', localStorage: {
			getItem: key => values.get( key ) ?? null,
			setItem: ( key, value ) => values.set( key, value )
		} };
		vm.runInNewContext( migration, context );
		assert.equal( context.requestMode, expected );
		assert.equal( context.currentMode, expected );
		assert.equal( values.get( 'mainwp_assistant_mode_preference' ), expected );
	}
} );

runTest( '18. Explicit and legacy preferences survive the Docs default', () => {
	assert.equal( resolveInitialRequestMode( 'ask' ), 'ask' );
	assert.equal( resolveInitialRequestMode( 'agent' ), 'agent' );
	assert.equal( resolveInitialRequestMode( 'ai' ), 'agent' );
	assert.equal( resolveInitialRequestMode( 'docs' ), 'ask' );
} );

console.log( '\nReceipt vs pill decoupling (resolveIndicatorDisplay)' );

runTest( '19. Docs receipt synchronizes the indicator', () => {
	assert.equal( resolveIndicatorDisplay( 'ask', null ), 'full' );
} );

runTest( '20. Explicit pills keep full indicator sync', () => {
	assert.equal( resolveIndicatorDisplay( 'ask', 'ask' ), 'full' );
	assert.equal( resolveIndicatorDisplay( 'agent', 'agent' ), 'full' );
	// Post-convergence: requestMode already flipped to ask.
	assert.equal( resolveIndicatorDisplay( 'ask', 'ask' ), 'full' );
} );

console.log( '\nAvailability restore matrix (resolveAvailabilityRestore)' );

runTest( '23. Available + auto/missing pref → Docs indicator', () => {
	assert.deepEqual( resolveAvailabilityRestore( 'available', 'auto' ), { needsSetup: false, indicator: { mode: 'ask', provider: null } } );
	assert.deepEqual( resolveAvailabilityRestore( 'available', null ), { needsSetup: false, indicator: { mode: 'ask', provider: null } } );
} );

runTest( '24. Available preferences keep their indicator and Docs override', () => {
	assert.deepEqual(
		resolveAvailabilityRestore( 'available', 'ask' ),
		{ needsSetup: false, override: 'ask', indicator: { mode: 'ask', provider: null } }
	);
	assert.deepEqual(
		resolveAvailabilityRestore( 'available', 'agent' ),
		{ needsSetup: false, indicator: { mode: 'agent', provider: 'PROVIDER' } }
	);
} );

runTest( '25. Unavailable → needs-setup pill, ask indicator, stale agent pref cleared', () => {
	assert.deepEqual(
		resolveAvailabilityRestore( 'unavailable', 'agent' ),
		{ needsSetup: true, indicator: { mode: 'ask', provider: null }, clearPref: true }
	);
	assert.deepEqual(
		resolveAvailabilityRestore( 'unavailable', 'auto' ),
		{ needsSetup: true, indicator: { mode: 'ask', provider: null } }
	);
} );

runTest( '26. Check error → keep preference, only correct agent to ask', () => {
	assert.deepEqual( resolveAvailabilityRestore( 'error', 'agent' ), { indicator: { mode: 'ask', provider: null } } );
	assert.deepEqual( resolveAvailabilityRestore( 'error', 'auto' ), {} );
} );

// ─── SSE reconnect budget (simulateSSEReconnects) ─────────────────────────────

console.log( '\nSSE reconnect budget (simulateSSEReconnects)' );

runTest( '27. Open-then-die stream without events stops after the cap → polling', () => {
	// Each retry opens fine but the server dies before sending any event.
	const result = simulateSSEReconnects( [ 'open', 'error', 'open', 'error', 'open', 'error' ] );
	assert.deepEqual( result, { outcome: 'polling', reconnects: 2, unsupportedMarked: false } );
} );

runTest( '28. Pre-fix onopen reset never exhausted the budget (the bug)', () => {
	const result = simulateSSEReconnects(
		[ 'open', 'error', 'open', 'error', 'open', 'error', 'open', 'error' ],
		{ resetOnOpen: true }
	);
	assert.equal( result.outcome, 'sse', 'old rule reconnects forever' );
	assert.equal( result.reconnects, 4 );
} );

runTest( '29. Meaningful events refill the budget: long healthy job survives stream cycling', () => {
	// Three 120s server streams, each proven real by status/heartbeat, each
	// ending in a connection error → always within budget, SSE stays active.
	const cycle = [ 'open', 'status', 'heartbeat', 'error' ];
	const result = simulateSSEReconnects( [ ...cycle, ...cycle, ...cycle ] );
	assert.deepEqual( result, { outcome: 'sse', reconnects: 3, unsupportedMarked: false } );
} );

runTest( '30. Open without any server event does not refill: budget spans silent reopens', () => {
	// One real stream, then the server turns into open-then-die: two silent
	// reconnects and the third error falls back to polling.
	const result = simulateSSEReconnects( [ 'open', 'status', 'error', 'open', 'error', 'open', 'error' ] );
	assert.deepEqual( result, { outcome: 'polling', reconnects: 2, unsupportedMarked: false } );
} );

runTest( '31. New job starts with a fresh budget', () => {
	const first = simulateSSEReconnects( [ 'open', 'error', 'open', 'error', 'open', 'error' ] );
	assert.equal( first.outcome, 'polling' );
	// handleAsyncChat resets the counter per job — a new simulation call.
	const second = simulateSSEReconnects( [ 'open', 'status' ] );
	assert.deepEqual( second, { outcome: 'sse', reconnects: 0, unsupportedMarked: false } );
} );

runTest( '33. Sites receipt names the serving provider when known', () => {
	assert.equal( resolveAttributionLabel( 'agent', 'OpenAI' ), 'Sites · OpenAI' );
	assert.equal( resolveAttributionLabel( 'agent', null ), 'Sites' );
	assert.equal( resolveAttributionLabel( 'agent', false ), 'Sites' );
	assert.equal( resolveAttributionLabel( 'ask', 'OpenAI' ), 'Docs' );
} );

runTest( '34. Cap-exceeded reconnect polls this job only, session stays SSE-eligible', () => {
	// Same event sequence as test 27; exhausting one job's reconnect budget is
	// evidence about that job, not about SSE support, so the session-wide
	// unsupported flag must NOT be set.
	const result = simulateSSEReconnects( [ 'open', 'error', 'open', 'error', 'open', 'error' ] );
	assert.equal( result.outcome, 'polling' );
	assert.equal( result.unsupportedMarked, false );
} );

runTest( '35. Connect-timeout is a genuine unsupported signal, marks session-wide', () => {
	// The EventSource never left CONNECTING within SSE_CONNECTION_TIMEOUT —
	// the one case that still disables SSE for the rest of the session.
	assert.deepEqual( simulateConnectTimeout(), { outcome: 'polling', unsupportedMarked: true } );
} );

// ─── Transport-failure recovery (resolveTransportFailureRecovery) ──────────────

console.log( '\nTransport-failure recovery (resolveTransportFailureRecovery)' );

runTest( '36. Stored [user(sent), assistant] tail recovers the lost answer', () => {
	const tail = [
		{ role: 'user', content: 'How do I add a site?' },
		{ role: 'assistant', content: 'Go to Sites > Add New.' },
	];
	assert.equal( resolveTransportFailureRecovery( tail, 'How do I add a site?' ), 'recover' );
	// Trim-compared: server-side sanitization may alter surrounding whitespace.
	assert.equal( resolveTransportFailureRecovery( tail, '  How do I add a site? ' ), 'recover' );
} );

runTest( '37. Tail ending on the user row (async job still running) shows the error', () => {
	const tail = [
		{ role: 'assistant', content: 'Earlier answer.' },
		{ role: 'user', content: 'Update my plugins' },
	];
	assert.equal( resolveTransportFailureRecovery( tail, 'Update my plugins' ), 'error' );
} );

runTest( '38. Tail belonging to a different turn shows the error', () => {
	const tail = [
		{ role: 'user', content: 'An older question' },
		{ role: 'assistant', content: 'An older answer.' },
	];
	assert.equal( resolveTransportFailureRecovery( tail, 'The question that failed' ), 'error' );
} );

runTest( '39. Empty or short tail shows the error', () => {
	assert.equal( resolveTransportFailureRecovery( [], 'Anything' ), 'error' );
	assert.equal( resolveTransportFailureRecovery( [ { role: 'user', content: 'Anything' } ], 'Anything' ), 'error' );
	assert.equal( resolveTransportFailureRecovery( null, 'Anything' ), 'error' );
} );

runTest( '40. Input restore returns the typed message for plain sends only', () => {
	assert.equal( resolveInputRestoreOnTransportFailure( false, 'my question' ), 'my question' );
	// Regenerate has its own restoreRegenerationOriginal() path.
	assert.equal( resolveInputRestoreOnTransportFailure( true, 'my question' ), null );
} );

runTest( 'Regenerate is available for bound answers and stateless turns', () => {
	for ( const [ id, historyDisabled, expected ] of [
		[ 42, false, true ],
		[ 42, true, true ],
		[ null, false, false ],
		[ null, true, true ],
		[ 0, false, false ],
		[ 0, true, true ],
		[ undefined, false, false ],
		[ undefined, true, true ]
	] ) {
		assert.equal( resolveRegenerateAvailability( id, historyDisabled ), expected, `id=${ id }, historyDisabled=${ historyDisabled }` );
	}
} );

runTest( 'Live regenerate gates share the stateless availability guard', () => {
	const guard = 'resolveRegenerateAvailability( lastAssistantMessageId, isHistoryDisabled )';
	assert.equal( CHAT_SRC.split( guard ).length - 1, 4, 'Both live responses, link display and click handling must use the guard.' );
	assert.equal( CHAT_SRC.split( 'if ( lastUserMessage && ' + guard + ' ) {' ).length - 1, 2 );
	assertSourceContains( 'link availability', 'if ( ! ' + guard + ' ) {' );
	assertSourceContains( 'click availability', 'if ( ! lastUserMessage || ! ' + guard + ' ) {' );
	assertSourceContains( 'optional bound id', 'if ( lastAssistantMessageId ) {\n                data.regenerate_message_id = lastAssistantMessageId;\n            }' );
} );

runTest( '41. Recovery runs only for plain sends in a known, stored conversation', () => {
	assert.equal( resolveTransportRecoveryEligibility.length, 3, 'Recovery accepts only conversation, history and regenerate inputs.' );
	assert.equal( resolveTransportRecoveryEligibility( 42, false, false ), true );
	// First turn: no conversation id to query yet.
	assert.equal( resolveTransportRecoveryEligibility( null, false, false ), false );
	// History opt-out: nothing stored server-side, endpoint rejects the load.
	assert.equal( resolveTransportRecoveryEligibility( 42, true, false ), false );
	// Regenerate: restoreRegenerationOriginal() already handles failure.
	assert.equal( resolveTransportRecoveryEligibility( 42, false, true ), false );
} );

runTest( '42. Stored sources pass through to render; anything else becomes []', () => {
	const sources = [ { title: 'Test Doc', url: 'https://kb.mainwp.com/test' } ];
	assert.deepEqual( normalizeStoredSources( sources ), sources );
	// Rows written before the metadata column, user rows, malformed payloads.
	assert.deepEqual( normalizeStoredSources( undefined ), [] );
	assert.deepEqual( normalizeStoredSources( null ), [] );
	assert.deepEqual( normalizeStoredSources( 'not-an-array' ), [] );
	assert.deepEqual( normalizeStoredSources( {} ), [] );
} );

// ─── Summary ───────────────────────────────────────────────────────────────────

console.log( `\n${ passed } passed, ${ failed } failed\n` );
process.exit( failed > 0 ? 1 : 0 );
