/**
 * Onboarding wizard pure-logic tests.
 *
 * Covers the four decision helpers the connect-free-AI flow shares between
 * the browser and this suite: which wizard panel a status probe leads to,
 * what a save_key error code means for step 3, what a no-provider chat
 * surface should offer, and whether a wizard event has to reload the settings
 * tab.
 *
 * Run: node tests/js/test-onboarding-wizard.js
 *      composer test-js
 */

'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const {
	nextWizardStep,
	classifyKeyFailure,
	resolveConnectPrompt,
	shouldReloadSettings
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

// ─── Source-drift guards ───────────────────────────────────────────────────────
// The three helpers above are required straight from the shared module, so they
// are real coverage. The call sites that consume them live inside larger
// side-effecting handlers, so each is pinned by an assertion that the exact
// token is still present in the source it belongs to.

const CHAT_SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/mainwp-mintlify-chat.js' ),
	'utf8'
);

const WIZARD_SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/mainwp-assistant-onboarding.js' ),
	'utf8'
);

const SETTINGS_SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/mainwp-assistant-settings.js' ),
	'utf8'
);

function assertContains( description, source, needle ) {
	assert.ok(
		source.includes( needle ),
		`drift guard failed (${ description }): expected:\n${ needle }`
	);
}

// ─── nextWizardStep ────────────────────────────────────────────────────────────

const READY = {
	wp_version_ok: true,
	ai_supported: true,
	contract_ok: true,
	key_source: 'none'
};

runTest( '1. A ready dashboard opens on the Get key step', () => {
	assert.equal( nextWizardStep( { requested: 'get_key', status: READY } ), 'get_key' );
	assert.equal( nextWizardStep( { status: READY } ), 'get_key' );
} );

runTest( '2. Replace key opens straight on Paste', () => {
	assert.equal( nextWizardStep( { requested: 'paste', status: READY } ), 'paste' );
} );

runTest( '3. An environment key never reaches the paste field', () => {
	const env = Object.assign( {}, READY, { key_source: 'env' } );
	assert.equal( nextWizardStep( { requested: 'get_key', status: env } ), 'override' );
	// Even when the caller explicitly asked for Paste: a database write would
	// be silently outranked by the environment value at runtime.
	assert.equal( nextWizardStep( { requested: 'paste', status: env } ), 'override' );
} );

runTest( '4. A constant key never reaches the paste field', () => {
	const constant = Object.assign( {}, READY, { key_source: 'constant' } );
	assert.equal( nextWizardStep( { requested: 'paste', status: constant } ), 'override' );
} );

runTest( '5. A stored database key still allows a replacement', () => {
	const stored = Object.assign( {}, READY, { key_source: 'database' } );
	assert.equal( nextWizardStep( { requested: 'paste', status: stored } ), 'paste' );
} );

runTest( '6. Each unmet precondition blocks the wizard', () => {
	assert.equal( nextWizardStep( { status: Object.assign( {}, READY, { wp_version_ok: false } ) } ), 'blocked' );
	assert.equal( nextWizardStep( { status: Object.assign( {}, READY, { ai_supported: false } ) } ), 'blocked' );
	assert.equal( nextWizardStep( { status: Object.assign( {}, READY, { contract_ok: false } ) } ), 'blocked' );
	// No status at all (a failed probe) is not a reason to offer the field.
	assert.equal( nextWizardStep( {} ), 'blocked' );
	assert.equal( nextWizardStep(), 'blocked' );
} );

runTest( '7. Blocked outranks an environment override', () => {
	const blocked = Object.assign( {}, READY, { contract_ok: false, key_source: 'env' } );
	assert.equal( nextWizardStep( { status: blocked } ), 'blocked' );
} );

runTest( '8. Verify is reachable once the caller asks for it', () => {
	assert.equal( nextWizardStep( { requested: 'verify', status: READY } ), 'verify' );
} );

runTest( '9. An inactive provider never reaches the paste field', () => {
	const inactive = Object.assign( {}, READY, { provider_active: false } );
	assert.equal( nextWizardStep( { requested: 'get_key', status: inactive } ), 'blocked' );
	assert.equal( nextWizardStep( { requested: 'paste', status: inactive } ), 'blocked' );
	// A payload from before the flag existed is not a report of an inactive
	// provider, so it still opens the wizard.
	assert.equal( nextWizardStep( { requested: 'paste', status: READY } ), 'paste' );
	assert.equal( nextWizardStep( { requested: 'paste', status: Object.assign( {}, READY, { provider_active: true } ) } ), 'paste' );
} );

// ─── classifyKeyFailure ────────────────────────────────────────────────────────

runTest( '10. A rejected credential asks for another key', () => {
	[ 'invalid_key', 'wrong_provider_key', 'malformed_key', 'empty_key', 'unchanged' ].forEach( ( code ) => {
		assert.deepEqual( classifyKeyFailure( code ), { severity: 'error', action: 'retry_key' }, code );
	} );
} );

runTest( '11. A stored but unverified key offers another verification', () => {
	[ 'validation_quota', 'validation_network', 'validation_provider_down', 'validation_unknown' ].forEach( ( code ) => {
		assert.deepEqual( classifyKeyFailure( code ), { severity: 'warning', action: 'retry_verify' }, code );
	} );
} );

runTest( '12. States the wizard cannot fix link out instead', () => {
	[
		'wp_version_unsupported',
		'ai_unsupported',
		'connector_contract_mismatch',
		'provider_inactive',
		'key_source_override',
		'save_failed',
		'forbidden',
		'too_many_attempts'
	].forEach( ( code ) => {
		assert.deepEqual( classifyKeyFailure( code ), { severity: 'error', action: 'link_out' }, code );
	} );
} );

runTest( '13. An unknown code lands on the generic state, never a blank panel', () => {
	assert.deepEqual( classifyKeyFailure( 'something_new' ), { severity: 'error', action: 'retry_key' } );
	assert.deepEqual( classifyKeyFailure( '' ), { severity: 'error', action: 'retry_key' } );
	assert.deepEqual( classifyKeyFailure( undefined ), { severity: 'error', action: 'retry_key' } );
} );

// ─── resolveConnectPrompt ──────────────────────────────────────────────────────

runTest( '14. Below WordPress 7.0 every surface gets the plain message', () => {
	assert.equal( resolveConnectPrompt( { reason_code: 'wp_version', wizard_available: false } ), 'version' );
	// The version block wins even if the gate somehow reported the wizard.
	assert.equal( resolveConnectPrompt( { reason_code: 'wp_version', wizard_available: true } ), 'version' );
} );

runTest( '15. No wizard right means no card anywhere', () => {
	assert.equal( resolveConnectPrompt( { reason_code: 'no_provider', wizard_available: false } ), 'legacy' );
	assert.equal( resolveConnectPrompt( {} ), 'legacy' );
	assert.equal( resolveConnectPrompt(), 'legacy' );
} );

runTest( '16. A settings-capable user on 7.0+ gets the card', () => {
	assert.equal( resolveConnectPrompt( { reason_code: 'no_provider', wizard_available: true } ), 'card' );
} );

// ─── Call-site drift guards ────────────────────────────────────────────────────

runTest( '17. The three no-provider chat surfaces still route through connectPrompt()', () => {
	// showModeDropdownError(): the card replaces the fading error.
	assertContains( 'mode switch', CHAT_SRC, "if ( 'card' === prompt && renderConnectCard( 'mode_switch' ) ) {" );
	// The Docs offer keeps connector setup available before a Sites request.
	assertContains( 'docs handoff', CHAT_SRC, "buildConnectCard( 'docs_handoff', false )" );
	// buildErrorDeepLink(): the provider codes become a wizard anchor.
	assertContains( 'error deep link', CHAT_SRC, "if ( ( errorCode === 'no_provider' || errorCode === 'no_usable_provider' || errorCode === 'provider_key_rejected' ) && wizardReady() ) {" );
	// ...and when the wizard is unavailable (stored-but-rejected key reads as
	// available), provider_key_rejected still gets the Connectors fallback link.
	assertContains( 'rejected-key fallback link', CHAT_SRC, "if ( errorCode === 'no_provider' || errorCode === 'no_usable_provider' || errorCode === 'provider_key_rejected' || errorCode === 'ai_result_parse_failed' ) {" );
} );

runTest( '18. The panel-open card stays gated on the welcome state and the dismissal', () => {
	assertContains( 'welcome gate', CHAT_SRC, "$messages.find( '.mainwp-mintlify-welcome' ).length" );
	assertContains( 'dismiss key', CHAT_SRC, "var CONNECT_CARD_DISMISSED_KEY = 'mainwp_assistant_connect_card_dismissed';" );
} );

runTest( '19. A repeated trigger moves the card down instead of stacking one', () => {
	// Later messages can leave an existing card before the last child, so a
	// position-based guard would miss it and allow a duplicate.
	assertContains( 'dedupe guard', CHAT_SRC, "$messages.find( '.mainwp-assistant-connect-card' ).closest( '.mainwp-mintlify-message' ).remove();\n\n        $messages.append( buildConnectCard( source, dismissible === true ) );" );
} );

runTest( '20. Provider text reaches the wizard as a text node, never as HTML', () => {
	assertContains( 'provider message', WIZARD_SRC, '$provider[ 0 ].textContent = String( data.provider_message );' );
} );

runTest( '21. The key field is emptied whenever the wizard closes', () => {
	assertContains( 'clear on hide', WIZARD_SRC, 'onHidden: function() {' );
	assertContains( 'clear on hide', WIZARD_SRC, 'clearKeyField();' );
} );

runTest( '22. The verified save announces the connection, not the Try it click', () => {
	assertContains( 'announce on save', WIZARD_SRC, "$m.trigger( 'mainwp-assistant-connected', [ {\n            agent_available: agentAvailable,\n            modal_open: modalOpen,\n            source: openSource\n        } ] );" );
	// Try it is only offered where the same save reported a usable agent mode.
	assertContains( 'try it gate', WIZARD_SRC, "return agentAvailable && $( '#mainwp-assistant-panel' ).length ? [ 'try-it' ] : [];" );
} );

runTest( '23. The chat panel turns Sites mode on only for a usable agent mode', () => {
	assertContains( 'agent gate', CHAT_SRC, "if ( true !== detail.agent_available ) {\n                return;\n            }" );
} );

// ─── shouldReloadSettings ──────────────────────────────────────────────────────

runTest( '24. A verified session reloads the tab when the modal is closed', () => {
	assert.equal( shouldReloadSettings( { type: 'closed', connected: true, action: 'close' } ), true );
} );

runTest( '25. Try it never reloads, whichever event reports it', () => {
	// The chat panel is being handed the conversation and the suggested prompt;
	// a reload would throw both away.
	assert.equal( shouldReloadSettings( { type: 'closed', connected: true, action: 'try_it' } ), false );
	assert.equal( shouldReloadSettings( { type: 'connected', action: 'try_it', modalOpen: false } ), false );
} );

runTest( '26. A close with nothing connected leaves the tab alone', () => {
	assert.equal( shouldReloadSettings( { type: 'closed', connected: false, action: 'close' } ), false );
} );

runTest( '27. A verification that lands after the close still reloads', () => {
	// Closing mid-verification fires the close event first, so that close
	// reported connected:false and this is the only notice there is.
	assert.equal( shouldReloadSettings( { type: 'connected', modalOpen: false } ), true );
	// While the modal is still up, the close event does the reloading.
	assert.equal( shouldReloadSettings( { type: 'connected', modalOpen: true } ), false );
} );

runTest( '28. An unrecognized or empty event never reloads', () => {
	assert.equal( shouldReloadSettings( { type: 'something_else' } ), false );
	assert.equal( shouldReloadSettings( {} ), false );
	assert.equal( shouldReloadSettings(), false );
} );

// ─── Reload-protocol drift guards ──────────────────────────────────────────────

runTest( '29. The close event carries what happened, not a flag set elsewhere', () => {
	assertContains( 'closed payload', WIZARD_SRC, "$modal().trigger( 'mainwp-assistant-onboarding-closed', [ {\n                        connected: sessionConnected,\n                        action: closeAction,\n                        source: openSource\n                    } ] );" );
	assertContains( 'try it names itself', WIZARD_SRC, "closeAction = 'try_it';" );
} );

runTest( '31. Async answers stand down when a newer launch owns the modal', () => {
	// Both callbacks capture their launch session and bail when it moved on.
	assertContains( 'status guard', WIZARD_SRC, 'var session = launchSession;' );
	// The failure path of the status probe stands down too - a stale launch's
	// failed probe must not blank a newer launch's panel.
	assertContains( 'status fail guard', WIZARD_SRC, "} ).fail( function() {\n            if ( session !== launchSession ) {\n                return;\n            }\n            showBlocked( '' );" );
	assertContains( 'save guard', WIZARD_SRC, 'var session = launchSession,\n            sessionSource = openSource;' );
	// A stale but verified answer still announces the connection under the
	// source it was launched with, so that tab's reload rule can claim it.
	assertContains( 'stale success announce', WIZARD_SRC, "agent_available: !! response.data.agent_available,\n                        modal_open: false,\n                        source: sessionSource" );
} );

runTest( '30. The settings tab reloads through the shared rule, once', () => {
	assertContains( 'shared rule', SETTINGS_SRC, 'window.MainWPAssistantChatLogic.shouldReloadSettings( evt )' );
	// The tab claims only its own launches, via the source carried on the
	// event - a sticky local flag would survive an abandoned launch and
	// misclassify a later chat-panel one.
	assertContains( 'double reload guard', SETTINGS_SRC, 'if ( -1 === TAB_WIZARD_SOURCES.indexOf( evt.source ) || reloadRequested ) {' );
} );

// ─── Summary ───────────────────────────────────────────────────────────────────

console.log( `\n${ passed } passed, ${ failed } failed\n` );
process.exit( failed > 0 ? 1 : 0 );
