'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const source = fs.readFileSync( process.env.SRC || require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

const PRIVATE_ROOT = 'mainwp_backup_private_root_unavailable';
const DETAIL = 'Site: child.example\nPrivate backup directory "/srv/private" on the Dashboard server is unavailable: could not create directory.\n\nConfigure MAINWP_BACKUP_PRIVATE_DIR in the Dashboard wp-config.php.';

function phpToJs( code ) {
    return code.replace( /<\?php[\s\S]*?\?>/g, php => {
        const constant = php.match( /MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE/ );
        if ( constant ) {
            return PRIVATE_ROOT;
        }
        const match = php.match( /'((?:\\.|[^'\\])*)'/ );
        return match[1];
    } );
}

function slice( from, to ) {
    const start = source.indexOf( from );
    const end = source.indexOf( to, start );
    assert.ok( start >= 0 && end > start, 'Source anchor missing: ' + from );
    return source.slice( start, end );
}

// Real setBackupStatus and its summary helpers against a DOM stub that keeps each node's text.
function dialog( context = {} ) {
    const nodes = new Map();
    const steps = [];
    Object.assign( context, {
        backupStatusDismissed: false, backupMonitorResult: null, backupMonitorDismissed: false, backupInProgress: false,
        window: { ...context.window, mainwpBackupProgressSteps: ( list, phase, pct, status ) => steps.push( status ) },
    } );
    context.$ = selector => {
        if ( ! nodes.has( selector ) ) {
            const node = {
                value: '', visible: false, classes: new Set(),
                text( value ) { this.value = value; return this; },
                show() { this.visible = true; return this; },
                hide() { this.visible = false; return this; },
                attr() { return this; },
                prop() { return this; },
                toggle( value ) { this.visible = value; return this; },
                addClass( key ) { if ( key ) this.classes.add( key ); return this; },
                removeClass() { this.classes.clear(); return this; },
                find( name ) { return context.$( selector + ' ' + name ); },
                progress() { return this; },
                modal( arg ) { return arg === 'is active' ? !! context.modalOpen : this; },
            };
            nodes.set( selector, node );
        }
        return nodes.get( selector );
    };
    vm.createContext( context );
    vm.runInContext( phpToJs( slice( 'function updateBackupMonitor()', "$( '#mainwp-native-backup-progress-modal' ).modal( {" ) ), context );
    vm.runInContext( phpToJs( slice( 'var backupFailureSummaries', 'function setBackupInProgress(' ) ), context );
    return {
        context, steps,
        label: () => nodes.get( '#mainwp-backup-phase-label' ).value,
        panel: () => nodes.get( '#mainwp-native-backup-now-status .mainwp-backup-status-content' ).value,
        panelShown: () => nodes.get( '#mainwp-native-backup-now-status' ).visible,
        banner: () => nodes.get( '#mainwp-backup-monitor-label' ).value,
        link: () => context.$( '#mainwp-backup-view-progress' ),
    };
}

// Real poll success callback with a recording setBackupStatus.
function poll() {
    const calls = [];
    const request = source.indexOf( "action: 'mainwp_native_backup_progress'" );
    const start = source.indexOf( 'function ( data ) {', request );
    const end = source.indexOf( "}, 'json' ).fail", start );
    assert.ok( request >= 0 && start > request && end > start );
    const context = {
        siteId: 7, currentSite: 7, gen: 1, backupFollowGen: 1, backupPollRetries: 0, backupPendingStarts: {},
        stopBackupTimers() {}, reenableBackupBtn() {}, showCancelBackup() {}, loadSnapshots() {},
        setBackupStatus: ( ...args ) => calls.push( args ),
    };
    vm.createContext( context );
    vm.runInContext( phpToJs( slice( 'var backupFailureSummaries', 'function setBackupStatus(' ) ), context );
    vm.runInContext( 'var onPoll = ' + phpToJs( source.slice( start, end ) ) + '};', context );
    return { context, calls };
}

test( 'a private-root failure shows the short summary in the label and the full text once in the panel', () => {
    const view = dialog();
    view.context.setBackupStatus( DETAIL, true, '', false, view.context.backupFailureSummary( PRIVATE_ROOT ) );
    assert.equal( view.label(), 'Backup failed on the Dashboard server.' );
    assert.equal( view.panel(), DETAIL );
    assert.ok( view.panelShown() );
    assert.notEqual( view.label(), view.panel() );
    assert.deepEqual( view.steps, [ 'failed' ] );
} );

test( 'unknown and missing codes fall back to the generic failure summary', () => {
    const view = dialog();
    for ( const code of [ 'mainwp_backup_timeout', undefined, '', '__proto__', 'constructor' ] ) {
        assert.equal( view.context.backupFailureSummary( code ), 'Backup failed.' );
    }
} );

test( 'callers without a summary keep the message in both places as before', () => {
    const view = dialog();
    view.context.setBackupStatus( 'Backup completed successfully.', false, 'green' );
    assert.equal( view.label(), 'Backup completed successfully.' );
    assert.equal( view.panel(), 'Backup completed successfully.' );
    view.context.setBackupStatus( 'Could not cancel the backup. Try again.', true );
    assert.equal( view.label(), 'Could not cancel the backup. Try again.' );
} );

test( 'every poll error uses a short summary, including status-less refusals', () => {
    const run = poll();
    run.context.onPoll( { status: 'failed', error: DETAIL, error_code: PRIVATE_ROOT } );
    run.context.onPoll( { status: 'failed', error: 'The on-demand backup did not produce a snapshot.' } );
    run.context.onPoll( { error: 'No on-demand backup is running for this site.' } );
    run.context.onPoll( { error: 'A restore is in progress for this site. Try again once it finishes.' } );
    run.context.onPoll( { error: DETAIL, error_code: PRIVATE_ROOT } );
    run.context.onPoll( { status: 'failed', error_code: PRIVATE_ROOT } );
    assert.deepEqual( run.calls, [
        [ DETAIL, true, '', false, 'Backup failed on the Dashboard server.' ],
        [ 'The on-demand backup did not produce a snapshot.', true, '', false, 'Backup failed.' ],
        [ 'No on-demand backup is running for this site.', true, '', false, 'Backup could not start.' ],
        [ 'A restore is in progress for this site. Try again once it finishes.', true, '', false, 'Backup could not start.' ],
        [ DETAIL, true, '', false, 'Backup failed on the Dashboard server.' ],
        [ 'Backup failed.', true, '', false, 'Backup failed on the Dashboard server.' ],
    ] );
} );

test( 'after a failure with the dialog closed the banner links to the full error', () => {
    const view = dialog();
    view.context.backupInProgress = true;
    view.context.updateBackupMonitor();
    assert.equal( view.link().visible, true );
    assert.equal( view.link().value, 'View progress' );

    view.context.backupInProgress = false;
    view.context.setBackupStatus( DETAIL, true, '', false, view.context.backupFailureSummary( PRIVATE_ROOT ) );
    assert.equal( view.banner(), 'Backup failed on the Dashboard server.' );
    assert.equal( view.link().visible, true, 'The full error must stay reachable from the banner.' );
    assert.equal( view.link().value, 'View details' );
    assert.equal( view.panel(), DETAIL, 'Reopening the dialog shows the full error.' );

    view.context.modalOpen = true;
    view.context.updateBackupMonitor();
    assert.equal( view.link().visible, false, 'No link while the dialog is open.' );
    assert.equal( view.panel(), DETAIL );
} );

test( 'the monitor banner shows the short label while the panel keeps the full error', () => {
    const view = dialog();
    view.context.setBackupStatus( DETAIL, true, '', false, view.context.backupFailureSummary( PRIVATE_ROOT ) );
    assert.equal( view.banner(), 'Backup failed on the Dashboard server.' );
    assert.equal( view.panel(), DETAIL );
    view.context.setBackupStatus( 'Backup completed successfully.', false, 'green' );
    assert.equal( view.banner(), 'Backup completed successfully.' );
} );

function errorCallback( action, bindings = {} ) {
    const calls = [];
    const request = source.indexOf( "action: '" + action + "'" );
    const start = source.indexOf( 'function ( response ) {', request );
    const end = source.indexOf( "}, 'json' ).fail", start );
    assert.ok( request >= 0 && start > request && end > start );
    const context = {
        siteId: 7, currentSite: 7, clickEpoch: 1, backupPendingKickoffs: { 7: 1 }, backupCancelledEpoch: {},
        backupClickEpoch: { 7: 1 }, backupCancelPending: { 7: 2 }, cancelToken: 2, epochAtCancel: 1,
        backupFollowStops: 0, stopsAtCancel: 0, backupPendingStarts: {},
        stopBackupTimers() {}, reenableBackupBtn() {}, showCancelBackup() {}, loadSnapshots() {},
        cancelApplies() { return false; },
        setBackupStatus: ( ...args ) => calls.push( args ),
        $: () => ( { prop() { return this; }, removeClass() { return this; } } ),
        window: { MainWPBackupCancelResult: { describeCancelResult: () => ( {} ) } },
        ...bindings,
    };
    vm.createContext( context );
    vm.runInContext( 'var onResponse = ' + phpToJs( source.slice( start, end ) ) + '};', context );
    return { context, calls };
}

for ( const [ action, label ] of [
    [ 'mainwp_native_backup_initiate', 'Backup could not start.' ],
    [ 'mainwp_native_backup_cancel', 'Could not cancel the backup.' ],
] ) {
    test( action + ' keeps the long error out of the progress label', () => {
        const run = errorCallback( action );
        run.context.onResponse( { error: DETAIL } );
        assert.deepEqual( run.calls, [ [ DETAIL, true, '', false, label ] ] );
    } );
}

test( 'cleanup success does not offer a backup progress dialog', () => {
    const view = dialog();
    view.context.setBackupStatus( 'Cleanup finished: 2 removed.', false, 'green' );
    assert.equal( view.link().visible, false );
} );

test( 'cleanup failure shows its reason in the banner with the details link hidden', () => {
    const view = dialog();
    const run = errorCallback( 'mainwp_snapshots_cleanup' );
    run.context.onResponse( { error: DETAIL } );
    view.context.setBackupStatus( ...run.calls[0] );
    assert.equal( view.banner(), DETAIL, 'Cleanup failure reason must remain readable.' );
    assert.equal( view.link().visible, false );
} );

for ( const message of [ 'Cleaning up backups...', 'Cancelling backup...' ] ) {
    test( message + ' preserves the prior failure details', () => {
        const view = dialog();
        view.context.setBackupStatus( DETAIL, true, '', false, 'Backup could not start.' );
        const call = source.split( '\n' ).find( line => line.includes( "'" + message + "'" ) && line.includes( 'setBackupStatus(' ) );
        assert.ok( call, 'The real in-flight status call must exist.' );
        vm.runInContext( phpToJs( call.trim() ), view.context );
        assert.equal( view.panel(), DETAIL );
        assert.equal( view.banner(), 'Backup could not start.' );
        assert.equal( view.link().visible, true );
    } );
}

test( 'a refused cancel keeps its full reason in the banner after the real progress ticker runs', () => {
    const run = errorCallback( 'mainwp_native_backup_cancel' );
    const view = dialog( run.context );
    view.context.backupInProgress = true;
    view.context.onResponse( { error: DETAIL } );
    view.context.backupProgressCache = { label: 'Archiving files', phase: 'archiving_files', percent: 25 };
    vm.runInContext( phpToJs( slice( 'function renderBackupStatus()', 'function stopBackupTimers()' ) ), view.context );
    view.context.renderBackupStatus();
    assert.equal( view.banner(), DETAIL );
    assert.equal( view.link().value, 'View progress' );
    assert.equal( view.link().visible, true );
    assert.equal( view.label(), 'Archiving files' );
} );

for ( const result of [ 'busy', 'not_running', 'no_active_on_site' ] ) {
    test( result + ' cancel reply clears the prior failure banner and replaces its panel', () => {
        const run = errorCallback( 'mainwp_native_backup_cancel' );
        const view = dialog( run.context );
        const message = 'Cancellation reply: ' + result;
        view.context.window.MainWPBackupCancelResult.describeCancelResult = () => ( { message, keepPolling: true } );
        view.context.setBackupStatus( DETAIL, true, '', false, 'Backup could not start.' );
        view.context.onResponse( { result } );
        assert.equal( view.panel(), message );
        assert.equal( view.context.backupMonitorResult, null );
        assert.equal( view.link().visible, false );
    } );
}

for ( const data of [ { status: 'completed' }, { status: 'failed' }, { status: 'failed', error: DETAIL } ] ) {
    test( 'terminal poll clears running state before list reload: ' + JSON.stringify( data ), () => {
        const run = poll();
        const view = dialog( run.context );
        view.context.backupInProgress = true;
        view.context.onPoll( data );
        assert.equal( view.context.backupInProgress, false );
        assert.notEqual( view.link().value, 'View progress' );
        assert.equal( view.link().visible, !! data.error );
    } );
}

test( 'an in-flight cancellation error keeps the progress link when the dialog is open', () => {
    const view = dialog();
    view.context.backupInProgress = true;
    view.context.modalOpen = true;
    view.context.setBackupStatus( DETAIL, true, '', false, 'Could not cancel the backup.' );
    assert.equal( view.link().visible, true );
    assert.equal( view.link().value, 'View progress' );
} );

for ( const response of [ { error: DETAIL }, { settled: true, message: 'Backup finished.' } ] ) {
    test( 'terminal kickoff clears running state: ' + JSON.stringify( response ), () => {
        const run = errorCallback( 'mainwp_native_backup_initiate' );
        const view = dialog( run.context );
        view.context.backupInProgress = true;
        view.context.onResponse( response );
        assert.equal( view.context.backupInProgress, false );
        assert.notEqual( view.link().value, 'View progress' );
    } );
}

test( 'cancelled reply clears running state before painting', () => {
    const run = errorCallback( 'mainwp_native_backup_cancel' );
    const view = dialog( run.context );
    view.context.window.MainWPBackupCancelResult.describeCancelResult = () => ( { message: 'Backup cancelled.', keepPolling: false } );
    view.context.backupInProgress = true;
    view.context.onResponse( { result: 'cancelled' } );
    assert.equal( view.context.backupInProgress, false );
    assert.equal( view.link().visible, false );
} );

test( 'kickoff transport failure clears running state before painting', () => {
    const run = errorCallback( 'mainwp_native_backup_initiate' );
    const view = dialog( run.context );
    const request = source.indexOf( "action: 'mainwp_native_backup_initiate'" );
    const start = source.indexOf( "}, 'json' ).fail( function () {", request );
    const end = source.indexOf( '\n                } );', start );
    vm.runInContext( 'var onFail = function () {' + phpToJs( source.slice( start + "}, 'json' ).fail( function () {".length, end ) ) + '};', view.context );
    view.context.backupInProgress = true;
    view.context.onFail();
    assert.equal( view.context.backupInProgress, false );
    assert.equal( view.link().visible, false );
} );
