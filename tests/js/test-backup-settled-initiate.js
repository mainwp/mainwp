'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const request = source.indexOf( "action: 'mainwp_native_backup_initiate'" );
const start = source.indexOf( 'function ( response ) {', request );
const end = source.indexOf( "}, 'json' ).fail", start );
assert.ok( request >= 0 && start > request && end > start );

function callback() {
    const calls = [];
    const context = {
        siteId: 7, currentSite: 7, backupSinceTs: 0,
        stopBackupTimers: () => calls.push( 'stop' ),
        setBackupStatus: ( message, error ) => calls.push( [ message, error ] ),
        reenableBackupBtn: () => calls.push( 'enable' ),
        showCancelBackup: visible => calls.push( [ 'cancel', visible ] ),
        loadSnapshots: site => calls.push( [ 'load', site ] ),
        cancelApplies: () => false,
        pollBackup: () => calls.push( 'poll' ),
        window: { setTimeout: ( fn, delay ) => { calls.push( [ 'timer', delay ] ); return 99; } }
    };
    vm.createContext( context );
    vm.runInContext( 'var initiate = ' + source.slice( start, end ) + '};', context );
    return { context, calls };
}

test( 'settled initiate displays the server message and never polls', () => {
    const { context, calls } = callback();
    const message = 'This site finished a backup a few seconds ago, so a new one was not started. Snapshot time: October 18, 2026 10:00 am.';
    context.initiate( { settled: true, message, snapshot_id: 27, started: 123, status: 'queued' } );
    assert.deepEqual( calls, [ 'stop', [ message, false ], 'enable', [ 'cancel', false ], [ 'load', 7 ] ] );
    assert.equal( context.backupSinceTs, 0 );
} );

test( 'ordinary initiate keeps polling the run handle', () => {
    const { context, calls } = callback();
    context.initiate( { started: 123, status: 'running' } );
    assert.deepEqual( calls, [ [ 'timer', 1500 ] ] );
    assert.equal( context.backupSinceTs, 123 );
} );
