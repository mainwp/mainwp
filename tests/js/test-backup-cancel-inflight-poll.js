'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const source = fs.readFileSync( process.env.SRC || require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
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
        currentSite: 7, ajaxurl: '/ajax', nonces: {}, siteDisconnected: false,
        currentJob: 0, rollbackRequestPending: false, canRestore: true,
        statusLabels: { pending: 'Queued' }, restoreCancelLabels: { cancelRollback: 'Cancel Rollback' },
        setPane() {}, startJobFollow( id ) { context.currentJob = id; },
        setSnapshotDeleteBusy( busy ) { context.snapshotDeleteBusy = busy; }, fmtClock: () => '',
        renderBackupStatus: () => calls.push( [ 'render', context.backupProgressCache.label ] ),
        setBackupStatus: ( text, error ) => calls.push( [ 'status', text, error ] ),
        snapshotsPlaceholders: {}, setSnapshotsPlaceholder() {}, escAttr: String,
        snapshotsTable: { clear() { return this; }, draw() { return this; }, rows: { add() {} } },
        window: {
            clearTimeout: id => timers.delete( id ), clearInterval() {}, setInterval: () => 99,
            setTimeout: fn => { timers.set( ++nextTimer, fn ); return nextTimer; },
        }
    };
    context.$ = selector => {
        if ( typeof selector === 'object' ) return selector;
        if ( ! elements.has( selector ) ) elements.set( selector, {
            disabled: false, visible: false, classes: new Set(),
            show() { this.visible = true; return this; }, hide() { this.visible = false; return this; },
            toggle( value ) { this.visible = value; return this; }, is() { return this.visible; },
            css( key, value ) { if ( key === 'display' ) this.visible = value !== 'none'; return this; },
            toggleClass( key, value ) { if ( value ) this.classes.add( key ); else this.classes.delete( key ); return this; },
            attr() { return this; }, html() { return this; }, empty() { return this; },
            find( name ) { return context.$( selector + ' ' + name ); }, modal() { return this; },
            text() { return this; },
            prop( key, value ) { if ( value === undefined ) return this.disabled; this.disabled = value; return this; },
            hasClass( key ) { return this.classes.has( key ); },
            addClass( key ) { this.classes.add( key ); return this; },
            removeClass( key ) { this.classes.delete( key ); return this; },
            on( event, fn ) { handlers.set( selector, fn ); return this; }
        } );
        return elements.get( selector );
    };
    context.$.post = ( url, data, success ) => {
        const request = { data, success };
        requests.push( request );
        return { fail( fn ) { request.fail = fn; return this; }, always( fn ) { request.always = fn; return this; } };
    };
    vm.createContext( context );
    context.mainwpBackupCancelMessages = Object.fromEntries( [ ...source.slice( source.indexOf( "'mainwpBackupCancelMessages'" ), source.indexOf( '$native_enabled' ) ).matchAll( /'([a-z_]+)'\s*=> __\( '([^']*)'/g ) ].map( match => [ match[1], match[2] ] ) );
    vm.runInContext( fs.readFileSync( require.resolve( '../../assets/js/mainwp-backup-cancel-result.js' ), 'utf8' ), context );
    context.window.MainWPBackupCancelResult = context.MainWPBackupCancelResult;
    const vars = source.indexOf( 'var backupPollTimer = ' );
    vm.runInContext( source.slice( vars, source.indexOf( 'var currentSiteUrl', vars ) ), context );
    for ( const name of [ 'stopBackupTimers', 'scheduleBackupPoll', 'showCancelBackup', 'enableSnapshotControls', 'showDisconnected', 'reenableBackupBtn', 'setBackupFollowControls', 'resumeBackupFollow', 'pollBackup', 'cancelApplies', 'startRollback', 'loadSnapshots' ] ) {
        const start = source.indexOf( 'function ' + name + '(' );
        if ( start < 0 && name === 'scheduleBackupPoll' ) continue; // Baseline has no scheduling helper.
        vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), context );
    }
    for ( const [ name, call ] of [ [ 'reenableBackupBtn', 'enable' ], [ 'loadSnapshots', 'load' ] ] ) {
        const real = context[ name ];
        context[ name ] = ( ...args ) => { calls.push( [ call ] ); return real( ...args ); };
    }
    for ( const selector of [ '#mainwp-native-backup-now-btn', '#mainwp-native-backup-cancel-btn' ] ) {
        const start = source.indexOf( "$( '" + selector + "' ).on( 'click'" );
        vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            } );', start ) + '\n            } );'.length ) ), context );
    }
    function requestList() {
        context.loadSnapshots( context.currentSite );
        return requests.at( -1 );
    }
    return { context, requests, timers, calls, requestList,
        list( response ) { requestList().success( response ); },
        switchSite( id ) {
            context.stopBackupTimers(); context.currentSite = id;
            context.backupSinceTs = 0;
            context.$( '#mainwp-native-backup-now-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
            context.$( '#mainwp-native-backup-cancel-btn' ).prop( 'disabled', false );
            context.showCancelBackup( false );
        },
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

test( 'a site switch away and back before a queued kickoff reply keeps its follow', () => {
    const h = harness();
    h.click( '#mainwp-native-backup-now-btn' );
    // The selector handler calls stopBackupTimers() on each switch.
    h.context.currentSite = 8;
    h.context.stopBackupTimers();
    h.context.currentSite = 7;
    h.context.stopBackupTimers();
    h.requests[0].success( { status: 'queued', started: 456 } );
    assert.equal( h.timers.size, 1, 'The queued kickoff must still schedule the poll that opens its run.' );
    h.next();
    assert.deepEqual( h.requests.at( -1 ).data.since, 456 );
} );

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

test( 'already_finished replaces the current poll with its immediate re-poll', () => {
    const h = harness();
    h.context.pollBackup( 7, 123 );
    h.click( '#mainwp-native-backup-cancel-btn' );
    h.requests[1].success( { result: 'already_finished' } );
    h.next();
    const before = h.calls.slice();
    h.requests[0].success( { status: 'running', label: 'Still running' } );
    assert.deepEqual( h.calls, before );
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

const NOW = '#mainwp-native-backup-now-btn';
const CANCEL = '#mainwp-native-backup-cancel-btn';
const VISIT = '#mainwp-restore-visit-site-wrap';
const rows = [];
function row( id, run ) { rows.push( { id, run } ); }
function lastStatus( h ) { return h.calls.filter( call => call[0] === 'status' || call[0] === 'render' ).at( -1 )?.[1]; }
function replyKick( request, kind ) {
    if ( kind === 'transport' ) request.fail();
    else request.success( kind === 'settled' ? { settled: true, message: 'Settled result' } : kind === 'error' ? { error: 'Kickoff refused' } : { status: kind, started: 456 } );
}
function running( h ) { h.context.resumeBackupFollow(); h.next(); return h.requests.at( -1 ); }
function finishPoll( request, kind, h ) {
    if ( kind === 'transport4' ) { h.context.backupPollRetries = 3; request.fail(); }
    else request.success( kind === 'error' ? { error: 'Progress refused' } : { status: kind } );
}
function assertEnded( h, message ) {
    const count = h.requests.length;
    for ( let i = 0; h.timers.size && i < 10; i++ ) h.next();
    assert.equal( h.timers.size, 0, 'No timer may keep a chain alive after an end.' );
    assert.equal( h.requests.length, count, 'Stale timers must not issue requests.' );
    assert.equal( lastStatus( h ), message, 'The end status must stay visible.' );
}
function assertFollowing( h ) {
    assert.deepEqual( {
        disabled: h.context.$( NOW ).prop( 'disabled' ),
        cancelShown: h.context.$( CANCEL ).visible,
        deleteBusy: h.context.snapshotDeleteBusy,
        chains: h.timers.size
    }, { disabled: true, cancelShown: true, deleteBusy: true, chains: 1 } );
}
row( 'START-click', h => { h.click( NOW ); assert.equal( lastStatus( h ), 'Starting backup...' ); assert.equal( h.timers.size, 0 ); assert.equal( h.requests[0].data.action, 'mainwp_native_backup_initiate' ); } );
row( 'START-list', h => { h.list( { manual_backup_running: true } ); assert.equal( h.timers.size, 1 ); h.next(); h.requests.at( -1 ).success( { status: 'running' } ); assert.equal( lastStatus( h ), 'Backing up...' ); assert.equal( h.timers.size, 1 ); } );
for ( const kind of [ 'started', 'queued', 'settled', 'error', 'transport' ] ) {
    row( 'KICK-' + kind, h => {
        h.click( NOW ); replyKick( h.requests[0], kind );
        if ( [ 'started', 'queued' ].includes( kind ) ) {
            assert.equal( h.timers.size, 1 ); h.next();
            assert.equal( h.requests.at( -1 ).data.since, 456 );
            h.requests.at( -1 ).success( { status: kind === 'queued' ? 'queued' : 'running' } );
            assert.equal( lastStatus( h ), kind === 'queued' ? QUEUED : 'Backing up...' );
            assert.equal( h.timers.size, 1 );
        } else assertEnded( h, kind === 'settled' ? 'Settled result' : kind === 'error' ? 'Kickoff refused' : 'Could not start the backup. Please try again.' );
    } );
}
for ( const kind of [ 'running', 'queued', 'completed', 'failed', 'error', 'transport4' ] ) {
    row( 'POLL-' + kind, h => {
        let request = running( h );
        if ( kind === 'transport4' ) {
            for ( let i = 0; i < 3; i++ ) {
                request.fail(); assert.equal( h.timers.size, 1 ); h.next(); request = h.requests.at( -1 );
            }
            assert.equal( h.context.backupPollRetries, 3 );
        }
        finishPoll( request, kind, h );
        if ( [ 'running', 'queued' ].includes( kind ) ) {
            assert.equal( h.timers.size, 1 ); assert.equal( lastStatus( h ), kind === 'queued' ? QUEUED : 'Backing up...' );
        } else {
            assertEnded( h, kind === 'completed' ? 'Backup complete.' : kind === 'failed' ? 'Backup failed.' : kind === 'error' ? 'Progress refused' : 'Lost the connection while backing up. The backup may still be running; reload to check.' );
            // The backup may still be running after a lost connection, so only that end keeps Cancel.
            assert.equal( h.context.$( CANCEL ).visible, kind === 'transport4', 'Cancel visibility after the follow ended.' );
        }
    } );
}
row( 'POLL-retry-reset', h => {
    let request = running( h );
    for ( let i = 1; i <= 3; i++ ) { request.fail(); assert.equal( h.context.backupPollRetries, i ); assert.equal( h.timers.size, 1 ); h.next(); request = h.requests.at( -1 ); }
    request.success( { status: 'running' } ); assert.equal( h.context.backupPollRetries, 0 );
    h.next(); h.requests.at( -1 ).fail(); assert.equal( h.context.backupPollRetries, 1 ); assert.equal( h.timers.size, 1 );
} );
row( 'SWITCH-return-queued', h => {
    h.click( NOW ); h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: false } );
    replyKick( h.requests[0], 'queued' ); assert.equal( h.timers.size, 1 ); h.next(); assert.equal( h.requests.at( -1 ).data.since, 456 );
} );
row( 'AR3-1F', h => {
    h.click( NOW ); h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: true } );
    replyKick( h.requests[0], 'started' );
    assert.equal( h.timers.size, 1, 'Resume and kickoff must leave exactly one chain.' );
    h.next(); h.requests.at( -1 ).success( { status: 'running' } );
    h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } ); assertEnded( h, 'Backup cancelled.' );
} );
row( 'AR3-1C', h => {
    h.click( NOW ); h.switchSite( 8 ); h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } );
    h.switchSite( 7 ); h.list( { manual_backup_running: false } ); replyKick( h.requests[0], 'queued' );
    assert.equal( h.timers.size, 1, 'Cancelling B must not discard A kickoff.' ); h.next();
    assert.equal( h.requests.at( -1 ).data.site_id, 7 ); assert.equal( h.requests.at( -1 ).data.since, 456 );
} );
for ( const kind of [ 'started', 'queued' ] ) {
    row( 'SWITCH-away-' + kind, h => {
        h.click( NOW ); h.switchSite( 8 ); const before = h.calls.slice(); replyKick( h.requests[0], kind );
        assert.deepEqual( h.calls, before ); assert.equal( h.timers.size, 0 );
        h.switchSite( 7 ); h.list( { manual_backup_running: kind === 'started' } );
        assertFollowing( h );
        assert.equal( h.timers.size, 1, 'Return must recover a queued kickoff even without an open run.' ); h.next();
        assert.equal( h.requests.at( -1 ).data.site_id, 7 ); assert.equal( h.requests.at( -1 ).data.since, 456 );
    } );
}
row( 'SWITCH-stale-poll', h => {
    const old = running( h ); h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: true } );
    const before = h.calls.slice(); old.success( { status: 'failed' } ); old.fail();
    assert.deepEqual( h.calls, before ); assert.equal( h.timers.size, 1 );
} );
row( 'CANCEL-away-own', h => {
    h.click( NOW ); h.click( CANCEL ); const cancel = h.requests.at( -1 ); h.switchSite( 8 );
    const before = h.calls.slice(); cancel.success( { result: 'cancelled' } ); assert.deepEqual( h.calls, before );
    h.switchSite( 7 ); replyKick( h.requests[0], 'queued' ); h.list( { manual_backup_running: false } ); assert.equal( h.timers.size, 0 );
} );
for ( const result of [ 'cancelled', 'busy', 'not_running', 'already_finished', 'already_failed', 'no_active_on_site', 'no_access', 'unknown', 'error', 'transport' ] ) {
    row( 'CANCEL-' + result, h => {
        running( h ).success( { status: 'running' } ); h.click( CANCEL ); const request = h.requests.at( -1 );
        if ( result === 'transport' ) request.fail(); else request.success( result === 'error' ? { error: 'Cancel refused' } : { result } );
        const keep = ! [ 'cancelled', 'no_access', 'unknown' ].includes( result );
        const message = result === 'transport' ? 'Could not cancel the backup. Try again.' : result === 'error' ? 'Cancel refused' : h.context.MainWPBackupCancelResult.describeCancelResult( result ).message;
        assert.equal( lastStatus( h ), message );
        if ( keep ) assert.equal( h.timers.size, 1 ); else assertEnded( h, message );
    } );
}
for ( const result of [ 'already_finished', 'already_failed' ] ) {
    row( 'CANCEL-inflight-' + result, h => {
        const old = running( h ); h.click( CANCEL ); h.requests.at( -1 ).success( { result } ); h.next();
        const fresh = h.requests.at( -1 ), before = h.calls.slice(); old.success( { status: 'running', label: 'Stale run' } ); old.fail();
        assert.deepEqual( h.calls, before, 'The replaced request must not repaint or reschedule.' );
        fresh.success( { status: result === 'already_finished' ? 'completed' : 'failed' } );
        assertEnded( h, result === 'already_finished' ? 'Backup complete.' : 'Backup failed.' );
    } );
}
for ( const kind of [ 'started', 'queued', 'error', 'settled', 'transport' ] ) {
    row( 'KICK-after-cancel-' + kind, h => {
        h.click( NOW ); h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } );
        const before = h.calls.slice(); replyKick( h.requests[0], kind ); assert.deepEqual( h.calls, before ); assertEnded( h, 'Backup cancelled.' );
    } );
}
for ( const end of [ 'completed', 'failed', 'error', 'transport4', 'cancelled', 'no_access', 'unknown', 'kick-settled', 'kick-error', 'kick-transport' ] ) {
    row( 'ORPHAN-' + end, h => {
        h.click( NOW ); const kickoff = h.requests[0];
        h.context.pollBackup( 7, 456 ); const first = h.requests.at( -1 );
        h.context.pollBackup( 7, 456 ); const second = h.requests.at( -1 );
        first.success( { status: 'running' } );
        const orphan = [ ...h.timers.values() ][0];
        // Simulate a lost handle, including callbacks already queued by the browser.
        h.context.backupPollTimer = null;
        if ( end.startsWith( 'kick-' ) ) replyKick( kickoff, end.slice( 5 ) );
        else if ( [ 'cancelled', 'no_access', 'unknown' ].includes( end ) ) { h.click( CANCEL ); h.requests.at( -1 ).success( { result: end } ); }
        else { h.context.pollBackup( 7, 456 ); finishPoll( h.requests.at( -1 ), end, h ); }
        const message = lastStatus( h ), before = h.calls.slice(), count = h.requests.length;
        orphan(); second.success( { status: 'running', label: 'Orphan repaint' } ); second.fail();
        assert.deepEqual( h.calls, before ); assert.equal( h.requests.length, count ); assertEnded( h, message );
    } );
}
for ( const kind of [ 'started', 'queued' ] ) {
    row( 'RESUME-kick-' + kind, h => {
        h.click( NOW ); h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: true } ); h.next();
        const old = h.requests.at( -1 ); replyKick( h.requests[0], kind ); const before = h.calls.slice();
        old.success( { status: 'running', label: 'Stale resumed run' } ); old.fail(); assert.deepEqual( h.calls, before );
        assert.equal( h.timers.size, 1 ); h.next(); assert.equal( h.requests.at( -1 ).data.since, 456 );
    } );
}
for ( const kind of [ 'refused', 'accepted', 'unanswered', 'transport', 'refused-hidden' ] ) {
    row( 'AR3-2-' + kind, h => {
        h.context.$( VISIT ).toggle( kind !== 'refused-hidden' ); h.context.startRollback( 123 );
        assert.equal( h.context.$( VISIT ).visible, false ); const request = h.requests.at( -1 );
        if ( kind.startsWith( 'refused' ) ) request.success( { error: 'Disconnected', retryable: true } );
        else if ( kind === 'transport' ) request.fail();
        else request.success( kind === 'accepted' ? { job_id: 456 } : {} );
        assert.equal( h.context.$( VISIT ).visible, kind === 'refused', 'Only a definite refusal restores prior visibility.' );
        if ( [ 'unanswered', 'transport' ].includes( kind ) ) {
            h.context.response = { active_restore_job_id: 456 };
            h.context.$( '#mainwp-restore-modal' ).modal = () => true;
            const start = source.indexOf( 'if ( response.active_restore_job_id && ! currentJob )' );
            vm.runInContext( source.slice( start, source.indexOf( 'if ( response.manual_backup_running', start ) ), h.context );
            assert.equal( h.context.currentJob, 456 ); assert.equal( h.context.$( VISIT ).visible, false );
        }
        assert.equal( h.timers.size, 0 );
    } );
}
row( 'SWITCH-queued-repeat', h => {
    h.click( NOW ); replyKick( h.requests[0], 'queued' );
    for ( let i = 0; i < 2; i++ ) {
        h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: false } );
        assert.equal( h.timers.size, 1 );
    }
    h.next(); assert.equal( h.requests.at( -1 ).data.since, 456 );
    h.requests.at( -1 ).success( { status: 'queued' } );
    h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: false } );
    assert.equal( h.timers.size, 1 );
} );
for ( const end of [ 'cancel', 'terminal' ] ) {
    row( 'LIST-stale-after-' + end, h => {
        const request = running( h ), list = h.requestList();
        if ( end === 'cancel' ) { h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } ); }
        else request.success( { status: 'completed' } );
        const message = lastStatus( h ); list.success( { manual_backup_running: true } ); assertEnded( h, message );
    } );
}
for ( const kind of [ 'error', 'settled', 'transport' ] ) {
    row( 'SWITCH-away-' + kind, h => {
        h.click( NOW ); h.switchSite( 8 ); const before = h.calls.slice(); replyKick( h.requests[0], kind );
        assert.deepEqual( h.calls, before ); h.switchSite( 7 ); h.list( { manual_backup_running: false } );
        assert.equal( h.timers.size, 0 );
    } );
}
for ( const kind of [ 'started', 'queued' ] ) {
    row( 'SWITCH-return-kick-before-list-' + kind, h => {
        h.click( NOW ); h.switchSite( 8 ); h.switchSite( 7 );
        const list = h.requestList();
        replyKick( h.requests[0], kind ); assertFollowing( h );
        list.success( { manual_backup_running: kind === 'started', snapshot_delete_busy: false } );
        assertFollowing( h );
    } );
}
row( 'LIST-during-kickoff', h => {
    const list = h.requestList();
    h.click( NOW ); const kickoff = h.requests.at( -1 );
    list.success( { manual_backup_running: false, snapshot_delete_busy: false } );
    assert.deepEqual( {
        disabled: h.context.$( NOW ).prop( 'disabled' ), cancelShown: h.context.$( CANCEL ).visible,
        deleteBusy: h.context.snapshotDeleteBusy, chains: h.timers.size
    }, { disabled: true, cancelShown: true, deleteBusy: true, chains: 0 }, 'The pending kickoff still owns the controls.' );
    h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } );
    assert.equal( h.requests.at( -1 ).data.action, 'mainwp_restore_snapshot_list' );
    h.requests.at( -1 ).success( { manual_backup_running: false, snapshot_delete_busy: false } );
    function cancelled() {
        assertEnded( h, 'Backup cancelled.' );
        assert.equal( h.context.$( NOW ).prop( 'disabled' ), false );
        assert.equal( h.context.$( CANCEL ).visible, false );
        assert.equal( h.context.snapshotDeleteBusy, false );
    }
    cancelled();
    replyKick( kickoff, 'started' ); cancelled();
} );
for ( const timing of [ 'before', 'after' ] ) {
    row( 'CANCEL-' + timing + '-new-kickoff', h => {
        h.click( NOW ); h.requests.at( -1 ).success( { started: 123 } );
        h.next(); h.requests.at( -1 ).success( { status: 'running' } );
        h.click( CANCEL ); const cancel = h.requests.at( -1 );
        h.switchSite( 8 ); h.switchSite( 7 ); h.list( { manual_backup_running: false } );
        h.click( NOW ); const kickoff = h.requests.at( -1 );
        if ( timing === 'after' ) kickoff.success( { started: 789 } );
        const before = h.calls.slice(), requestsBefore = h.requests.length;
        const timersBefore = [ ...h.timers.entries() ], genBefore = h.context.backupFollowGen;
        cancel.success( { result: 'cancelled' } );
        assert.deepEqual( h.calls, before, 'The older cancel must not repaint, enable, or reload the newer follow.' );
        assert.equal( h.requests.length, requestsBefore );
        assert.deepEqual( [ ...h.timers.entries() ], timersBefore, 'The newer chain must not be replaced.' );
        assert.equal( h.context.backupFollowGen, genBefore );
        assert.equal( h.context.$( CANCEL ).visible, true );
        assert.equal( h.context.$( CANCEL ).prop( 'disabled' ), false );
        assert.equal( h.context.$( CANCEL ).hasClass( 'disabled' ), false );
        if ( timing === 'before' ) kickoff.success( { started: 789 } );
        assert.equal( h.context.backupPendingStarts[7], 789 );
        assertFollowing( h ); h.next();
        assert.equal( h.requests.at( -1 ).data.since, 789 );
        h.requests.at( -1 ).success( { status: 'running' } );
        assert.equal( lastStatus( h ), 'Backing up...' ); assertFollowing( h );
    } );
}
row( 'RETAINED-cleared-by-run', h => {
    h.click( NOW ); replyKick( h.requests.at( -1 ), 'started' );
    h.next(); h.requests.at( -1 ).success( { status: 'running' } );
    h.next(); h.requests.at( -1 ).success( { status: 'completed' } );
    h.requests.at( -1 ).success( { manual_backup_running: false } );
    assertEnded( h, 'Backup complete.' );
    assert.equal( Object.hasOwn( h.context.backupPendingStarts, 7 ), false );
} );
row( 'RETAINED-cleared-by-cancel', h => {
    h.click( NOW ); replyKick( h.requests.at( -1 ), 'queued' );
    h.next(); h.requests.at( -1 ).success( { status: 'queued' } );
    h.click( CANCEL ); h.requests.at( -1 ).success( { result: 'cancelled' } );
    h.requests.at( -1 ).success( { manual_backup_running: false } );
    assertEnded( h, 'Backup cancelled.' );
    assert.equal( Object.hasOwn( h.context.backupPendingStarts, 7 ), false );
} );
function assertCancelHeld( h ) {
    assert.equal( h.context.$( CANCEL ).visible, true );
    assert.equal( h.context.$( CANCEL ).prop( 'disabled' ), true, 'A cancel in flight keeps the button off.' );
    const count = h.requests.length;
    h.click( CANCEL );
    assert.equal( h.requests.length, count, 'No second cancel while the first is in flight.' );
}
row( 'CANCEL-inflight-kickoff-reply', h => {
    h.click( NOW ); const kickoff = h.requests.at( -1 );
    h.click( CANCEL ); const cancel = h.requests.at( -1 );
    replyKick( kickoff, 'started' ); assertCancelHeld( h );
    cancel.success( { result: 'cancelled' } );
    h.requests.at( -1 ).success( { manual_backup_running: false } );
    assertEnded( h, 'Backup cancelled.' );
    assert.equal( h.context.$( CANCEL ).visible, false );
} );
row( 'CANCEL-inflight-list-reply', h => {
    running( h ).success( { status: 'running' } );
    h.click( CANCEL ); const cancel = h.requests.at( -1 );
    h.list( { manual_backup_running: true } ); assertCancelHeld( h );
    cancel.success( { result: 'cancelled' } );
    h.requests.at( -1 ).success( { manual_backup_running: false } );
    assertEnded( h, 'Backup cancelled.' );
    assert.equal( h.context.$( CANCEL ).visible, false );
} );
row( 'CANCEL-inflight-busy-reenables', h => {
    running( h ).success( { status: 'running' } );
    h.click( CANCEL ); const cancel = h.requests.at( -1 );
    h.list( { manual_backup_running: true } ); assertCancelHeld( h );
    cancel.success( { result: 'busy' } );
    assert.equal( h.context.$( CANCEL ).prop( 'disabled' ), false, 'A kept follow gets its Cancel back.' );
    assertFollowing( h );
} );
row( 'LIST-request-holds-kickoff', h => {
    h.click( NOW ); h.switchSite( 8 ); h.switchSite( 7 );
    const list = h.requestList(), count = h.requests.length;
    assert.equal( h.context.$( NOW ).prop( 'disabled' ), true, 'The pending kickoff holds Back Up Now before the list answers.' );
    h.click( NOW ); assert.equal( h.requests.length, count, 'No second kickoff while the first is pending.' );
    list.success( { manual_backup_running: false } );
    replyKick( h.requests[0], 'queued' ); assertFollowing( h );
} );
row( 'KICK-older-click-dropped', h => {
    h.click( NOW ); const older = h.requests.at( -1 );
    // The controls hold while a kickoff is pending; force the button to prove the reply guard on its own.
    h.context.$( NOW ).prop( 'disabled', false ).removeClass( 'disabled' );
    h.click( NOW ); const newer = h.requests.at( -1 );
    newer.success( { status: 'queued', started: 200 } );
    const timersBefore = [ ...h.timers.entries() ], before = h.calls.slice();
    older.success( { status: 'started', started: 100 } );
    assert.deepEqual( h.calls, before, 'The older kickoff reply must not repaint.' );
    assert.deepEqual( [ ...h.timers.entries() ], timersBefore, 'The newer chain must not be replaced.' );
    assert.equal( h.context.backupPendingStarts[7], 200 );
    h.next(); assert.equal( h.requests.at( -1 ).data.since, 200 );
} );
for ( const { id, run } of rows ) test( 'matrix ' + id, () => run( harness() ) );
