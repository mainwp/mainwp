/**
 * Assistant settings autosave characterization tests.
 *
 * Mirrors the save-lifecycle logic in assets/js/mainwp-assistant-settings.js:
 * the setGroupSaving() disabled-state selector (which must reach both group
 * containers AND fields whose id sits on the <input> itself, like the two
 * budget fields), and the per-field save-generation gate that keeps an
 * older, slower response from overwriting a newer value or re-enabling the
 * field while a newer save is still in flight.
 *
 * Run: node tests/js/test-settings-save.js
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
// The save handlers are jQuery-coupled, so the specification functions below
// duplicate their decision logic and each is paired with an assertion that
// the exact tokens it depends on are still present verbatim in the source.

const SETTINGS_SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/mainwp-assistant-settings.js' ),
	'utf8'
);

function assertSourceContains( description, needle ) {
	assert.ok(
		SETTINGS_SRC.includes( needle ),
		`drift guard failed (${ description }): expected mainwp-assistant-settings.js to contain:\n${ needle }`
	);
}

// ─── Save-generation gate ──────────────────────────────────────────────────────

/**
 * Characterization copy of the per-field save-generation counters:
 * beginSaveGeneration() stamps each outgoing save, and only the response
 * carrying the newest stamp may touch the field.
 */
function makeGenerations() {
	const generations = {};
	return {
		begin( key ) {
			generations[ key ] = ( generations[ key ] || 0 ) + 1;
			return generations[ key ];
		},
		isCurrent( key, generation ) {
			return generations[ key ] === generation;
		}
	};
}

console.log( '\nsave-generation gate' );

runTest( 'an out-of-order stale response is dropped, the newest wins', () => {
	const gens = makeGenerations();
	const first = gens.begin( 'daily_budget' );
	const second = gens.begin( 'daily_budget' );
	// Responses arrive out of order: the older one must not apply.
	assert.equal( gens.isCurrent( 'daily_budget', first ), false );
	assert.equal( gens.isCurrent( 'daily_budget', second ), true );
} );

runTest( 'a stale response cannot re-enable the field while a newer save is in flight', () => {
	const gens = makeGenerations();
	const first = gens.begin( 'daily_budget' );
	gens.begin( 'daily_budget' );
	// The .always() re-enable is gated on the same check.
	assert.equal( gens.isCurrent( 'daily_budget', first ), false );
} );

runTest( 'fields gate independently of each other', () => {
	const gens = makeGenerations();
	const site = gens.begin( 'daily_budget' );
	const user = gens.begin( 'user_daily_budget' );
	assert.equal( gens.isCurrent( 'daily_budget', site ), true );
	assert.equal( gens.isCurrent( 'user_daily_budget', user ), true );
} );

runTest( 'drift guard: per-field save generations exist and gate the budget handlers', () => {
	assertSourceContains( 'generation begin helper', 'beginSaveGeneration: function( key ) {' );
	assertSourceContains( 'generation check helper', 'isCurrentSaveGeneration: function( key, generation ) {' );
	assertSourceContains( 'site budget stamps a generation', "const generation = self.beginSaveGeneration( 'daily_budget' );" );
	assertSourceContains( 'user budget stamps a generation', "const generation = self.beginSaveGeneration( 'user_daily_budget' );" );
	assertSourceContains( 'stale responses are dropped', "if ( ! self.isCurrentSaveGeneration( 'daily_budget', generation ) ) {" );
	assertSourceContains( 'stale user responses are dropped', "if ( ! self.isCurrentSaveGeneration( 'user_daily_budget', generation ) ) {" );
	assertSourceContains( 'always re-enable is generation-gated', "if ( self.isCurrentSaveGeneration( 'daily_budget', generation ) ) {" );
	assertSourceContains( 'user always re-enable is generation-gated', "if ( self.isCurrentSaveGeneration( 'user_daily_budget', generation ) ) {" );
} );

// ─── setGroupSaving selector ───────────────────────────────────────────────────

runTest( 'drift guard: setGroupSaving() reaches inputs that ARE the selector target', () => {
	// The budget fields carry the id on the <input> itself, so a descendant
	// selector ('#id input') never matched and the field was never disabled
	// during the save round-trip. addBack() covers both shapes.
	assertSourceContains( 'self-or-descendant input selection', ".find( 'input' ).addBack( 'input' ).prop( 'disabled', !! saving );" );
} );

// ─── Summary ───────────────────────────────────────────────────────────────────

console.log( `\n${ passed } passed, ${ failed } failed` );
process.exit( failed > 0 ? 1 : 0 );
