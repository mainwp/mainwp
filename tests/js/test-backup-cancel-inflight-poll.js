'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const QUEUED = 'Queued behind another backup step. This one starts as soon as that step finishes. Keep this tab open until then.';

function phpToJs( code ) {
    return code.replace( /<\?php[\s\S]*?\?>/g, php => {
        const match = php.match( /'((?:\\.|[^'\\])*)'/ );
        return match[1];
    } );
}

function harness() {
    const requests = [];
    const timers = new Map();
    const handlers = new Map();
    const calls = [];
    const elements = new Map();
    let nextTimer = 0;
    const context = {
        currentSite: 7, ajaxurl: '/ajax', nonces: {},
        setSnapshotDeleteBusy() {}, showCancelBackup() {}, fmtClock: () => '',
        renderBackupStatus: () => calls.push( [ 'render', context.backupProgressCache.label ] ),
        setBackupStatus: ( text, error ) => calls.push( [ 'status', text, error ] ),
        reenableBackupBtn: () => calls.push( [ 'enable' ] ),
        loadSnapshots: () => calls.push( [ 'load' ] ),
        window: {
            clearTimeout: id => timers.delete( id ), clearInterval() {}, setInterval: () => 99,
            setTimeout: fn => { timers.set( ++nextTimer, fn ); return nextTimer; },
            MainWPBackupCancelResult: { describeCancelResult: result => ( { message: result === 'cancelled' ? 'Backup cancelled.' : 'Already finished', keepPolling: result !== 'cancelled' } ) }
        }
    };
    context.$ = selector => {
        if ( typeof selector === 'object' ) return selector;
        if ( ! elements.has( selector ) ) elements.set( selector, {
            disabled: false,
            prop( key, value ) { if ( value === undefined ) return this.disabled; this.disabled = value; return this; },
            hasClass() { return false; }, addClass() { return this; }, removeClass() { return this; },
            on( event, fn ) { handlers.set( selector, fn ); return this; }
        } );
        return elements.get( selector );
    };
    context.$.post = ( url, data, success ) => {
        const request = { data, success };
        requests.push( request );
        return { fail( fn ) { request.fail = fn; return this; } };
    };
    vm.createContext( context );
    const vars = source.indexOf( 'var backupPollTimer = ' );
    vm.runInContext( source.slice( vars, source.indexOf( 'var currentSiteUrl', vars ) ), context );
    for ( const name of [ 'stopBackupTimers', 'resumeBackupFollow', 'pollBackup' ] ) {
        const start = source.indexOf( 'function ' + name + '(' );
        vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), context );
    }
    for ( const selector of [ '#mainwp-native-backup-now-btn', '#mainwp-native-backup-cancel-btn' ] ) {
        const start = source.indexOf( "$( '" + selector + "' ).on( 'click'" );
        vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            } );', start ) + '\n            } );'.length ) ), context );
    }
    return { context, requests, timers, calls,
        click: selector => handlers.get( selector ).call( context.$( selector ) ),
        next: () => { const [ id, fn ] = timers.entries().next().value; timers.delete( id ); fn(); }
    };
}

for ( const reply of [ 'success', 'fail' ] ) {
    test( 'cancelled reply invalidates an in-flight poll ' + reply, () => {
        const h = harness();
        h.context.pollBackup( 7, 123 );
        h.click( '#mainwp-native-backup-cancel-btn' );
        h.requests[1].success( { result: 'cancelled' } );
        const afterCancel = h.calls.slice();
        if ( reply === 'success' ) h.requests[0].success( { status: 'failed', error: 'No snapshot' } );
        else h.requests[0].fail();
        assert.deepEqual( h.calls, afterCancel, 'No status repaint or second load/enable after cancel.' );
        assert.deepEqual( h.calls, [ [ 'status', 'Cancelling backup...', false ], [ 'status', 'Backup cancelled.', false ], [ 'enable' ], [ 'load' ] ] );
        assert.equal( h.context.backupPollRetries, 0 );
        assert.equal( h.timers.size, 0, 'A stale failure must not schedule a retry.' );
    } );
}

for ( const reply of [ 'success', 'error', 'settled', 'fail' ] ) {
    test( 'cancelled reply before the kickoff reply keeps the follow ended (' + reply + ')', () => {
        const h = harness();
        h.click( '#mainwp-native-backup-now-btn' );
        h.click( '#mainwp-native-backup-cancel-btn' );
        h.requests[1].success( { result: 'cancelled' } );
        const afterCancel = h.calls.slice();
        if ( reply === 'success' ) h.requests[0].success( { started: 456 } );
        else if ( reply === 'error' ) h.requests[0].success( { error: 'Another backup is running.' } );
        else if ( reply === 'settled' ) h.requests[0].success( { settled: true, message: 'Backup complete.' } );
        else h.requests[0].fail();
        assert.deepEqual( h.calls, afterCancel, 'The kickoff reply must not repaint the cancelled status.' );
        assert.equal( h.calls.at( -3 )[1], 'Backup cancelled.' );
        assert.equal( h.timers.size, 0, 'The kickoff reply must not restart the follow.' );
    } );
}

for ( const follow of [ 'click', 'resume' ] ) {
    test( 'new ' + follow + ' follow paints after a terminal state and rejects the old poll', () => {
        const h = harness();
        h.context.pollBackup( 7, 123 );
        h.context.stopBackupTimers();
        if ( follow === 'click' ) {
            h.click( '#mainwp-native-backup-now-btn' );
            h.requests.at( -1 ).success( { started: 456 } );
        } else h.context.resumeBackupFollow();
        h.next();
        const beforeStale = h.calls.slice();
        h.requests[0].success( { status: 'failed', error: 'Old run' } );
        assert.deepEqual( h.calls, beforeStale );
        h.requests.at( -1 ).success( { status: 'completed' } );
        assert.deepEqual( h.calls.slice( -3 ), [ [ 'status', 'Backup complete.', false ], [ 'enable' ], [ 'load' ] ] );
    } );
}

test( 'already_finished keeps the current poll and its immediate re-poll valid', () => {
    const h = harness();
    h.context.pollBackup( 7, 123 );
    h.click( '#mainwp-native-backup-cancel-btn' );
    h.requests[1].success( { result: 'already_finished' } );
    h.next();
    h.requests[0].success( { status: 'running', label: 'Still running' } );
    assert.deepEqual( h.calls.at( -1 ), [ 'render', 'Still running' ] );
    h.requests[2].success( { status: 'completed' } );
    assert.deepEqual( h.calls.slice( -3 ), [ [ 'status', 'Backup complete.', false ], [ 'enable' ], [ 'load' ] ] );
} );

test( 'snapshots queued progress explains the step wait', () => {
    const h = harness();
    h.context.pollBackup( 7, 123 );
    h.requests[0].success( { status: 'queued' } );
    assert.deepEqual( h.calls.at( -1 ), [ 'render', QUEUED ] );
} );

test( 'Manage Backups queued progress uses the same translated copy', () => {
    const manage = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );
    const start = manage.indexOf( 'let render = function (response)' );
    const end = manage.indexOf( 'let closed = ', start );
    const translations = [];
    let painted;
    const context = { generation: 1, manageBackupsPollGeneration: 1, started: 0, click: 123, queuedSince: null,
        queuedExpired: () => false, QUEUED_NOTICE_MS: 60000,
        waiting: { text: text => { painted = text; } }, MainWP: { I18n: { t: text => { translations.push( text ); return text; } } } };
    vm.createContext( context );
    vm.runInContext( manage.slice( start, end ).replace( 'let render', 'var render' ) + '}; render({ status: "queued" });', context );
    assert.equal( painted, QUEUED );
    assert.deepEqual( translations, [ QUEUED ] );
} );
