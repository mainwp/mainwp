/**
 * Batch-scroll characterization tests.
 *
 * Mirrors the isBatchRendering guard and scrollToBottom(instant) logic in
 * assets/js/mainwp-mintlify-chat.js that suppresses per-message scroll
 * animations during conversation restore.
 *
 * Run: node tests/js/test-batch-scroll.js
 *      composer test-js
 */

'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

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
// The isBatchRendering guard is inlined at every call site in
// mainwp-mintlify-chat.js ( if ( ! isBatchRendering ) { scrollToBottom(); } )
// rather than living behind one shared predicate, and the real scrollToBottom()
// drives a jQuery element and a jQuery .animate() call — neither is a
// DOM/jQuery-free pure function, so there is nothing here to lift into
// mainwp-assistant-chat-logic.js without dragging jQuery into the Node test
// run. shouldScroll() and the mock scrollToBottom() below stay
// characterization copies; this guard confirms the real guard pattern and the
// real scrollToBottom() instant/animated branching are still present verbatim,
// so editing either without updating this test fails loudly.

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

runTest( 'drift guard: scrollToBottom() instant/animated branching matches the mock', () => {
	assertSourceContains(
		'scrollToBottom instant/animated branch',
		"function scrollToBottom( instant ) {\n" +
		"        if ( ! $messages || ! $messages.length ) {\n" +
		"            return;\n" +
		"        }\n" +
		"        if ( instant ) {\n" +
		"            $messages[0].scrollTop = $messages[0].scrollHeight;\n" +
		"        } else {\n" +
		"            $messages.animate( {"
	);
} );

runTest( 'drift guard: isBatchRendering guard pattern is still present at real call sites', () => {
	const GUARDED_CALL = 'if ( ! isBatchRendering ) {\n            scrollToBottom();\n        }';
	const occurrences = CHAT_SRC.split( GUARDED_CALL ).length - 1;
	// Six render call sites currently gate scrollToBottom() on isBatchRendering
	// (appendUserMessage, appendAssistantMessage, appendSystemMessage,
	// appendErrorMessage, batch progress/completion, and the confirmation
	// card). A count of zero means the guard pattern itself changed shape.
	assert.ok( occurrences > 0, 'expected at least one isBatchRendering-guarded scrollToBottom() call' );
} );

// ─── Minimal DOM stub ─────────────────────────────────────────────────────────

function createMockMessages() {
	let scrollTopValue = 0;
	const el = {
		scrollTop: 0,
		scrollHeight: 5000,
	};
	// Track which scroll method was used.
	const calls = { instant: 0, animated: 0 };
	return { el, calls };
}

// ─── Specification functions ──────────────────────────────────────────────────
// Mirror the exact logic from the source file. Both are DOM/jQuery-entangled
// in the real implementation (scrollToBottom drives a jQuery element and
// .animate()), so they stay mocks here — drift-guarded above instead of lifted.

function scrollToBottom( mock, instant ) {
	if ( instant ) {
		mock.el.scrollTop = mock.el.scrollHeight;
		mock.calls.instant++;
	} else {
		// In the real code this is a jQuery .animate() call.
		mock.el.scrollTop = mock.el.scrollHeight;
		mock.calls.animated++;
	}
}

function shouldScroll( isBatchRendering ) {
	return ! isBatchRendering;
}

// ─── Tests ────────────────────────────────────────────────────────────────────

console.log( 'Batch rendering guard' );

runTest( 'scrollToBottom is suppressed when isBatchRendering is true', () => {
	const isBatchRendering = true;
	const mock = createMockMessages();
	// Simulates what appendUserMessage / appendAssistantMessage do.
	if ( shouldScroll( isBatchRendering ) ) {
		scrollToBottom( mock );
	}
	assert.equal( mock.calls.instant, 0 );
	assert.equal( mock.calls.animated, 0 );
} );

runTest( 'scrollToBottom fires when isBatchRendering is false', () => {
	const isBatchRendering = false;
	const mock = createMockMessages();
	if ( shouldScroll( isBatchRendering ) ) {
		scrollToBottom( mock );
	}
	assert.equal( mock.calls.animated, 1 );
} );

console.log( '\nInstant scroll parameter' );

runTest( 'scrollToBottom(true) sets scrollTop directly (instant mode)', () => {
	const mock = createMockMessages();
	scrollToBottom( mock, true );
	assert.equal( mock.el.scrollTop, mock.el.scrollHeight );
	assert.equal( mock.calls.instant, 1 );
	assert.equal( mock.calls.animated, 0 );
} );

runTest( 'scrollToBottom() without argument uses animated mode', () => {
	const mock = createMockMessages();
	scrollToBottom( mock );
	assert.equal( mock.el.scrollTop, mock.el.scrollHeight );
	assert.equal( mock.calls.animated, 1 );
	assert.equal( mock.calls.instant, 0 );
} );

// ─── Summary ──────────────────────────────────────────────────────────────────

console.log( `\n${ passed } passed, ${ failed } failed\n` );
process.exit( failed > 0 ? 1 : 0 );
