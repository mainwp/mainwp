'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const source = fs.readFileSync( 'assets/js/mainwp-assistant-insights.js', 'utf8' );
function fn( name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    return source.slice( start, source.indexOf( '\n    }', start ) + 6 );
}
test( 'conversation starts use their own label and omit request duration', () => {
    let table;
    const context = {
        mainwpAssistantInsights: { strings: { started: 'New conversation' }, canViewAllUsers: false }, config: {},
        $: () => ( { html: () => '', DataTable: options => { table = options; } } ),
        escapeHtml: value => value,
    };
    vm.createContext( context );
    vm.runInContext( fn( 'formatDuration' ) + fn( 'formatStatus' ) + fn( 'initActivityTable' ), context );
    assert.match( context.formatStatus( 'started' ), /> New conversation</ );
    context.initActivityTable();
    const duration = table.columns.find( column => column.data === 'duration' );
    assert.equal( duration.render( 49, 'display', { status: 'started' } ), '—' );
    assert.equal( duration.render( 295, 'display', { status: 'failed', ability: '' } ), '—' );
    assert.notEqual( duration.render( 49, 'display', { status: 'executed', ability: 'mainwp/sync-sites-v1' } ), '—' );
    assert.notEqual( duration.render( 49, 'display', { status: 'failed', ability: 'mainwp/sync-sites-v1' } ), '—' );
} );
