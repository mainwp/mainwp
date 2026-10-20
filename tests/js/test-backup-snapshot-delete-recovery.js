'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

function harness() {
    const elements = new Map();
    const handlers = new Map();
    const requests = [];
    const reloads = [];
    const context = {
        currentSite: 12, siteDisconnected: false, snapshotDeleteBusy: false,
        snapshotDeleteTarget: 17, snapshotDeleteRequest: 0, snapshotsTable: null,
        deleteLabels: { failed: 'The delete request failed. Reload the page and try again.' },
        nonces: {}, ajaxurl: 'ajax', backupPollRetries: 0, backupFollowGen: 0, backupCancelGen: {}, backupPendingStarts: {},
        stopBackupTimers() {}, setBackupStatus() {}, showCancelBackup() {}, renderBackupStatus() {},
        setSnapshotDeleteBusy() {}, cancelApplies() { return false; },
        backupSinceTs: 0, backupProgressCache: null, backupStartTime: null,
        Date, window: { clearTimeout() {}, setTimeout() { return 1; }, setInterval() { return 1; } },
        loadSnapshots( siteId ) { reloads.push( siteId ); context.currentSite = siteId; },
        escAttr: String
    };
    context.$ = selector => {
        selector = String( selector );
        if ( ! elements.has( selector ) ) elements.set( selector, { items: [], props: {}, classes: new Set() } );
        const state = elements.get( selector );
        return {
            state,
            on( event, handler ) { handlers.set( selector, handler ); return this; },
            empty() { state.items = []; return this; },
            text( value ) { state.text = value; return this; },
            appendTo( target ) { target.state.items.push( state.text ); return this; },
            prop( key, value ) { if ( value === undefined ) return state.props[ key ]; state.props[ key ] = value; return this; },
            val() { return state.value; },
            hasClass( key ) { return state.classes.has( key ); },
            toggle( value ) { state.visible = value; return this; },
            addClass( key ) { state.classes.add( key ); return this; },
            removeClass( key ) { state.classes.delete( key ); return this; },
            toggleClass( key, value ) { if ( value ) state.classes.add( key ); else state.classes.delete( key ); return this; }
        };
    };
    context.$.post = ( url, fields, success ) => {
        const request = { fields, success };
        const chain = {
            fail( callback ) { request.failure = callback; return this; },
            always( callback ) { request.always = callback; return this; }
        };
        requests.push( request );
        return chain;
    };
    vm.createContext( context );
    const evaluate = code => vm.runInContext( code.replace( /<\?php[\s\S]*?\?>/g, 'Translated text' ), context );
    const load = name => {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0, name );
        evaluate( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) );
    };
    const event = selector => {
        const start = source.indexOf( "$( '" + selector + "' ).on( " );
        assert.ok( start >= 0, selector );
        evaluate( source.slice( start, source.indexOf( '\n            } );', start ) + '\n            } );'.length ) );
        return () => handlers.get( selector ).call( selector );
    };
    const box = selector => context.$( selector ).state;
    load( 'setBackupFollowControls' );
    return { context, requests, reloads, load, event, box };
}

for ( const failure of [ 'server', 'transport' ] ) {
    for ( const proceed of [ true, false ] ) {
        test( 'delete ' + failure + ' error refreshes consent and preserves the error, proceed=' + proceed, () => {
            const h = harness();
            [ 'setDeleteMessages', 'deletePreviewItem', 'showDeletePreview', 'requestDeletePreview' ].forEach( h.load );
            h.context.snapshotDeletePreview = {
                can_proceed: true,
                would_affect: { site_id: 12, snapshot_id: 17, snapshot_ids: [ 17 ], delete_cloud_copies: false }
            };
            h.event( '#mainwp-snapshot-delete-confirm' )();
            assert.equal( h.requests.length, 1 );
            const error = failure === 'server' ? 'Server refused deletion.' : h.context.deleteLabels.failed;
            if ( failure === 'server' ) h.requests[0].success( { error } );
            else h.requests[0].failure();
            assert.equal( h.requests.length, 2, 'A failed confirmation must request another preview.' );
            assert.equal( h.requests[1].fields.action, 'mainwp_backup_snapshot_delete_preview' );
            assert.equal( h.requests[1].fields.delete_cloud_copies, 0, 'The cloud choice survives.' );
            assert.ok( h.box( '#mainwp-snapshot-delete-result' ).items.includes( error ), 'The error remains visible during the request.' );
            h.requests[1].success( {
                would_affect: { site_id: 12, snapshot_id: 17, snapshot_ids: [ 17 ], delete_cloud_copies: false },
                can_proceed: proceed, warnings: [], refusals: proceed ? [] : [ { message: 'Busy.' } ]
            } );
            assert.ok( h.box( '#mainwp-snapshot-delete-warnings' ).items.includes( error ), 'Rendering fresh refusals must not lose the confirm error.' );
            assert.equal( h.box( '#mainwp-snapshot-delete-confirm' ).props.disabled, ! proceed );
        } );
    }
}

test( 'changing sites reloads only the newly selected site', () => {
    const h = harness();
    h.load( 'reenableBackupBtn' );
    h.box( '#mainwp-restore-site-selector' ).value = '24';
    h.event( '#mainwp-restore-site-selector' )();
    assert.deepEqual( h.reloads, [ 24 ] );
} );

for ( const status of [ 'completed', 'failed', 'error', 'transport' ] ) {
    test( 'terminal backup ' + status + ' reloads once', () => {
        const h = harness();
        h.load( 'reenableBackupBtn' );
        h.load( 'pollBackup' );
        h.context.backupPollRetries = 3;
        h.context.pollBackup( 12, 0 );
        if ( status === 'transport' ) h.requests[0].failure();
        else h.requests[0].success( status === 'error' ? { error: 'Stopped.' } : { status } );
        assert.deepEqual( h.reloads, [ 12 ] );
    } );
}

for ( const failure of [ 'server', 'transport' ] ) {
    test( 'backup initiate ' + failure + ' error reloads once', () => {
        const h = harness();
        h.load( 'reenableBackupBtn' );
        h.event( '#mainwp-native-backup-now-btn' )();
        if ( failure === 'server' ) h.requests[0].success( { error: 'Refused.' } );
        else h.requests[0].failure();
        assert.deepEqual( h.reloads, [ 12 ] );
    } );
}

test( 'confirmed backup cancellation reloads once', () => {
    const h = harness();
    h.context.window.MainWPBackupCancelResult = { describeCancelResult() { return { message: 'Cancelled.' }; } };
    h.load( 'reenableBackupBtn' );
    h.event( '#mainwp-native-backup-cancel-btn' )();
    h.requests[0].success( { result: 'cancelled' } );
    assert.deepEqual( h.reloads, [ 12 ] );
} );
