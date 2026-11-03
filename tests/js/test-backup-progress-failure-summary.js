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
function dialog() {
    const nodes = new Map();
    const steps = [];
    const context = {
        backupStatusDismissed: false, backupMonitorResult: null, backupMonitorDismissed: false,
        updateBackupMonitor() {},
        window: { mainwpBackupProgressSteps: ( list, phase, pct, status ) => steps.push( status ) },
    };
    context.$ = selector => {
        if ( ! nodes.has( selector ) ) {
            const node = {
                value: '', visible: false, classes: new Set(),
                text( value ) { this.value = value; return this; },
                show() { this.visible = true; return this; },
                toggle( value ) { this.visible = value; return this; },
                addClass( key ) { if ( key ) this.classes.add( key ); return this; },
                removeClass() { this.classes.clear(); return this; },
                find( name ) { return context.$( selector + ' ' + name ); },
                progress() { return this; },
            };
            nodes.set( selector, node );
        }
        return nodes.get( selector );
    };
    vm.createContext( context );
    vm.runInContext( phpToJs( slice( 'var backupFailureSummaries', 'function setBackupInProgress(' ) ), context );
    return {
        context, steps,
        label: () => nodes.get( '#mainwp-backup-phase-label' ).value,
        panel: () => nodes.get( '#mainwp-native-backup-now-status .mainwp-backup-status-content' ).value,
        panelShown: () => nodes.get( '#mainwp-native-backup-now-status' ).visible,
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

test( 'a failed poll passes the summary keyed by error_code; refusals without a status do not', () => {
    const run = poll();
    run.context.onPoll( { status: 'failed', error: DETAIL, error_code: PRIVATE_ROOT } );
    run.context.onPoll( { status: 'failed', error: 'The on-demand backup did not produce a snapshot.' } );
    run.context.onPoll( { error: 'No on-demand backup is running for this site.' } );
    assert.deepEqual( run.calls, [
        [ DETAIL, true, '', false, 'Backup failed on the Dashboard server.' ],
        [ 'The on-demand backup did not produce a snapshot.', true, '', false, 'Backup failed.' ],
        [ 'No on-demand backup is running for this site.', true, '', false, '' ],
    ] );
} );
