'use strict';

/**
 * Runs the real updateBudgetNotice() from mainwp-mintlify-chat.js against a
 * minimal jQuery stand-in and checks which spend-limit line the panel shows.
 *
 * Run: node --test tests/js/test-budget-notice.js
 */

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/js/mainwp-mintlify-chat.js' ), 'utf8' );

function functionSource( name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    const end = source.indexOf( '\n    }', start ) + 6;
    assert.ok( start > 0 && end > start, name );
    return source.slice( start, end );
}

function render( budget ) {
    const visible = {};
    const node = ( selector ) => ( {
        find: ( child ) => node( child ),
        toggle: ( show ) => {
            visible[ selector ] = show;
        },
    } );
    const context = { $: node, budgetState: null };
    vm.createContext( context );
    vm.runInContext( functionSource( 'updateBudgetNotice' ) + '\nupdateBudgetNotice( ' + JSON.stringify( budget ) + ' );', context );
    return {
        notice: visible[ '#mainwp-assistant-budget-notice' ],
        dashboard: visible[ '.mainwp-assistant-budget-reached' ],
        user: visible[ '.mainwp-assistant-budget-user-reached' ],
        unavailable: visible[ '.mainwp-assistant-budget-unavailable' ],
    };
}

test( 'dashboard-wide limit shows the dashboard message', () => {
    assert.deepEqual( render( { blocked: true, unavailable: false, user_reached: false } ), {
        notice: true, dashboard: true, user: false, unavailable: false,
    } );
} );

test( 'per-user limit shows the per-user message', () => {
    assert.deepEqual( render( { blocked: true, unavailable: false, user_reached: true } ), {
        notice: true, dashboard: false, user: true, unavailable: false,
    } );
} );

test( 'an unreadable spend total wins over the per-user flag', () => {
    assert.deepEqual( render( { blocked: true, unavailable: true, user_reached: true } ), {
        notice: true, dashboard: false, user: false, unavailable: true,
    } );
} );

test( 'a server without the per-user flag keeps the dashboard message', () => {
    assert.deepEqual( render( { blocked: true, unavailable: false } ), {
        notice: true, dashboard: true, user: false, unavailable: false,
    } );
} );

test( 'nothing blocked hides the notice', () => {
    assert.equal( render( { blocked: false, unavailable: false, user_reached: false } ).notice, false );
    assert.equal( render( null ).notice, false );
} );
