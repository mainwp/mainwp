'use strict';
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const create = require( '../../assets/js/mainwp-backup-bulk-delete.js' );
const overlaps = [];
test.afterEach( () => assert.deepEqual( overlaps.splice( 0 ), [], 'Requests must be sequential.' ) );

function fixture( replies = {} ) {
    const calls = [], removed = [];
    let inFlight = 0;
    const controller = create( {
        request: async ( action, data ) => {
            if ( inFlight++ !== 0 ) overlaps.push( [ action, data ] );
            calls.push( [ action, data ] );
            await Promise.resolve();
            inFlight--;
            if ( typeof replies[ data.snapshot_id ] === 'function' ) return replies[ data.snapshot_id ]( action, data );
            if ( replies[ data.snapshot_id ] instanceof Error ) throw replies[ data.snapshot_id ];
            if ( action === 'preview' ) return replies[ data.snapshot_id ] || {
                can_proceed: true, warnings: [], refusals: [],
                would_affect: { site_id: 7, snapshot_id: data.snapshot_id, snapshot_ids: [ data.snapshot_id ], delete_cloud_copies: true }
            };
            return { status: 'complete', deleted_ids: [ data.snapshot_id ], remaining_ids: [], errors: [] };
        },
        remove: ids => removed.push( ...ids ),
        changed() {}, labels: { failed: 'Request failed.', refused: 'Refused.', chain: 'Select the full chain.', changed: 'Review again.', skipped: 'Already deleted.', deleted: 'Deleted', failedItem: 'Failed', skippedItem: 'Skipped' }
    } );
    controller.reset( 7 );
    return { controller, calls, removed };
}

test( 'empty selection makes no request', async () => {
    const f = fixture();
    assert.deepEqual( await f.controller.preview(), [] );
    assert.equal( f.calls.length, 0 );
} );
test( 'selection persists independently of table pages and can be cleared', () => {
    const { controller: c } = fixture();
    c.select( 11, true ); c.select( 12, true ); c.select( 11, false );
    assert.deepEqual( c.ids(), [ 12 ] );
    c.reset( 8 ); assert.deepEqual( c.ids(), [] );
} );
test( 'mixed preview refusals do not prevent safe items being deleted', async () => {
    const f = fixture( { 12: { can_proceed: false, refusals: [ { message: 'Safety backup is needed.' } ], warnings: [], would_affect: { snapshot_ids: [ 12 ] } } } );
    f.controller.select( 11, true ); f.controller.select( 12, true );
    await f.controller.preview();
    const results = await f.controller.confirm();
    assert.deepEqual( f.removed, [ 11 ] );
    assert.deepEqual( results.map( r => r.status ), [ 'deleted', 'failed' ] );
    assert.equal( results[1].message, 'Safety backup is needed.' );
    assert.deepEqual( f.controller.ids(), [ 12 ] );
    assert.equal( f.calls.filter( c => c[0] === 'delete' ).length, 1 );
} );
test( 'unselected descendants refuse deletion', async () => {
    const f = fixture( { 11: { can_proceed: true, warnings: [], refusals: [], would_affect: { snapshot_ids: [ 11, 12 ] } } } );
    f.controller.select( 11, true ); await f.controller.preview();
    assert.equal( ( await f.controller.confirm() )[0].message, 'Select the full chain.' );
    assert.equal( f.calls.length, 1 ); assert.deepEqual( f.removed, [] );
} );
test( 'network failures retain rows and are never retried', async () => {
    const f = fixture(); f.controller.select( 11, true ); await f.controller.preview();
    f.controller.options.request = async () => { throw new Error( 'offline' ); };
    const results = await f.controller.confirm();
    assert.equal( results[0].status, 'failed' ); assert.deepEqual( f.removed, [] );
    assert.deepEqual( f.controller.ids(), [ 11 ] );
} );
test( 'partial deletion removes only confirmed IDs and preserves the other selected row', async () => {
    const f = fixture( { 11: ( action ) => action === 'preview' ? {
        can_proceed: true, warnings: [], refusals: [],
        would_affect: { snapshot_ids: [ '11', '12' ], delete_cloud_copies: false }
    } : { status: 'partial', deleted_ids: [ 12 ], remaining_ids: [ 11 ], errors: [ { message: 'Cannot remove archive.' } ] } } );
    f.controller.select( 11, true ); f.controller.select( 12, true );
    await f.controller.preview(); const results = await f.controller.confirm();
    assert.deepEqual( f.removed, [ 12 ] ); assert.deepEqual( f.controller.ids(), [ 11 ] );
    assert.equal( results[0].status, 'failed' ); assert.equal( results[0].message, 'Cannot remove archive.' );
    assert.equal( results[1].status, 'skipped' );
    assert.deepEqual( f.calls.at( -1 )[1].expected_snapshot_ids, [ 11, 12 ] );
} );
test( 'changed membership requires fresh consent and never deletes added IDs', async () => {
    const f = fixture(); f.controller.select( 11, true ); await f.controller.preview();
    f.controller.options.request = async () => ( { status: 'changed', deleted_ids: [], remaining_ids: [ 11, 99 ], errors: [ { message: 'Backups changed.' } ] } );
    const result = await f.controller.confirm();
    assert.equal( result[0].status, 'failed' ); assert.deepEqual( f.removed, [] );
    assert.deepEqual( await f.controller.confirm(), [] );
} );
test( 'stale site replies cannot remove rows', async () => {
    const f = fixture(); f.controller.select( 11, true ); await f.controller.preview();
    f.controller.options.request = async () => {
        f.controller.reset( 8 ); return { status: 'complete', deleted_ids: [ 11 ] };
    };
    assert.deepEqual( await f.controller.confirm(), [] ); assert.deepEqual( f.removed, [] );
} );
test( 'a confirmed whole-chain deletion is not repeated for its selected descendants', async () => {
    const f = fixture( { 11: action => action === 'preview' ? {
        can_proceed: true, warnings: [], refusals: [], would_affect: { snapshot_ids: [ 11, 12 ] }
    } : { status: 'complete', deleted_ids: [ 12, 11 ], remaining_ids: [], errors: [] } } );
    f.controller.select( 11, true ); f.controller.select( 12, true ); await f.controller.preview();
    const results = await f.controller.confirm();
    assert.deepEqual( f.removed, [ 12, 11 ] );
    assert.deepEqual( results.map( r => r.status ), [ 'deleted', 'skipped' ] );
    assert.equal( f.calls.filter( c => c[0] === 'delete' ).length, 1 );
} );
test( 'a reload deferred by the confirmation dialog runs when the dialog closes', () => {
    const fs = require( 'node:fs' ), vm = require( 'node:vm' );
    const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
    const context = vm.createContext( { bulkDialogOpen: true, bulkReloadSite: 0, currentSite: 7 } );
    function load( name ) {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0, name + ' must exist.' );
        const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
        vm.runInContext( source.slice( start, end ).replace( /<\?php[\s\S]*?\?>/g, '' ), context );
    }
    load( 'loadSnapshots' );
    context.loadSnapshots( 7 );
    assert.equal( context.bulkReloadSite, 7, 'A completed background action must not lose its reload.' );
    const calls = [];
    context.loadSnapshots = site => calls.push( site );
    load( 'flushBulkReload' );
    const focus = [];
    context.$ = selector => ( {
        modal( options ) { context.modal = options; return this; },
        prop() { return this; }, closest() { return this; }, removeClass() { return this; },
        trigger( event ) { focus.push( [ selector, event ] ); return this; }
    } );
    const start = source.indexOf( "$( '#mainwp-backup-bulk-delete-modal' ).modal( {" );
    const end = source.indexOf( '\n            } );', start ) + '\n            } );'.length;
    vm.runInContext( source.slice( start, end ), context );
    context.modal.onHidden();
    assert.deepEqual( calls, [ 7 ] ); assert.equal( context.bulkReloadSite, 0 );
    assert.equal( context.bulkDialogOpen, false );
    assert.deepEqual( focus, [ [ '#mainwp-backup-bulk-apply', 'focus' ] ] );
    context.bulkReloadSite = 8;
    context.flushBulkReload();
    assert.deepEqual( calls, [ 7 ], 'A reload for another site must not change the selected site.' );
} );
test( 'same-site refresh keeps selection and an already-assigned site switch clears it', () => {
    const fs = require( 'node:fs' ), vm = require( 'node:vm' );
    const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
    const { controller } = fixture();
    controller.select( 11, true );
    const stop = new Error( 'selection-updated' );
    const context = vm.createContext( {
        bulkDialogOpen: false, bulkDelete: controller, currentSite: 7,
        updateBulkSelection() { throw stop; }
    } );
    const start = source.indexOf( 'function loadSnapshots(' );
    const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
    vm.runInContext( source.slice( start, end ).replace( /<\?php[\s\S]*?\?>/g, '' ), context );
    assert.throws( () => context.loadSnapshots( 7 ), error => error === stop );
    assert.deepEqual( controller.ids(), [ 11 ], 'A same-site background refresh must keep the selected backup.' );
    context.currentSite = 8;
    assert.throws( () => context.loadSnapshots( 8 ), error => error === stop );
    assert.deepEqual( controller.ids(), [], 'The site selector assigns currentSite before loading the new site.' );
} );

function pageHarness( values ) {
    const fs = require( 'node:fs' ), vm = require( 'node:vm' );
    const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
    const context = vm.createContext( values );
    return { source, context, load( name ) {
        const start = source.indexOf( 'function ' + name + '(' );
        assert.ok( start >= 0, name + ' must exist.' );
        const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
        vm.runInContext( source.slice( start, end ).replace( /<\?php[\s\S]*?\?>/g, '' ), context );
    }, run( code ) { vm.runInContext( code, context ); } };
}
test( 'every settled bulk attempt queues reconciliation on close, including failures', async () => {
    let handler;
    const chain = { on( event, fn ) { handler = fn; return this; }, prop() { return this; }, addClass() { return this; }, trigger() { return this; } };
    const h = pageHarness( { $: () => chain, currentSite: 7, bulkReloadSite: 0, bulkControls() {}, bulkDelete: { busy: () => false, confirm: async () => [ { status: 'failed' } ] } } );
    const start = h.source.indexOf( "$( '#mainwp-backup-bulk-confirm' ).on( 'click', async function () {" );
    const end = h.source.indexOf( '\n            } );', start ) + '\n            } );'.length;
    h.run( h.source.slice( start, end ) );
    await handler.call( { disabled: false } );
    assert.equal( h.context.bulkReloadSite, 7 );
    h.context.bulkReloadSite = 0;
    h.context.bulkDelete.confirm = async () => { h.context.currentSite = 8; return [ { status: 'deleted' } ]; };
    await handler.call( { disabled: false } );
    assert.equal( h.context.bulkReloadSite, 0, 'A stale operation must not queue another site.' );
} );
test( 'refreshed rows remove missing or pruned selections', () => {
    const { controller } = fixture();
    controller.select( 11, true ); controller.select( 12, true );
    controller.retain( [ 12, 13 ] );
    assert.deepEqual( controller.ids(), [ 12 ] );
    controller.retain( [] ); assert.deepEqual( controller.ids(), [] );
} );
test( 'unavailable archives remain selectable and unchanged counts are not announced again', () => {
    const elements = new Map(), textWrites = [];
    const $ = selector => {
        if ( ! elements.has( selector ) ) elements.set( selector, { text: '', props: {} } );
        const state = elements.get( selector );
        return { find() { return this; }, prop( key, value ) { state.props[key] = value; return this; }, text( value ) {
            if ( value === undefined ) return state.text;
            state.text = value; textWrites.push( value ); return this;
        } };
    };
    const h = pageHarness( { $, bulkUiLocked: false, bulkLabels: { selectedOne: '%d backup selected.', selectedMany: '%d backups selected.' },
        bulkDelete: { ids: () => [ 11 ] }, snapshotsTable: { rows: () => ( { every( fn ) { fn.call( { data: () => ( { id: 11, status: 'complete', source: 'unavailable' } ), node: () => '#row' } ); } } ) }
    } );
    h.load( 'updateBulkSelection' ); h.context.updateBulkSelection(); h.context.updateBulkSelection();
    assert.equal( elements.get( '#row' ).props.disabled, false, 'Single delete permits this row; bulk must permit it too.' );
    assert.deepEqual( textWrites, [ '1 backup selected.' ] );
} );
test( 'unlock derives backup controls from current state instead of old disabled flags', () => {
    const elements = new Map();
    function element( name ) { if ( ! elements.has( name ) ) elements.set( name, { tagName: 'BUTTON', disabled: name.includes( 'backup-now' ) || name.includes( 'cleanup' ) } ); return elements.get( name ); }
    const $ = selector => {
        const nodes = typeof selector === 'string' ? selector.split( ',' ).map( name => element( name.trim() ) ) : [ selector ];
        return { each( fn ) { nodes.forEach( node => fn.call( node ) ); return this; }, prop( key, value ) { nodes.forEach( node => { node[key] = value; } ); return this; }, toggleClass() { return this; }, closest() { return this; }, addClass() { return this; } };
    };
    const h = pageHarness( { $, bulkControlStates: [], bulkUiLocked: false, currentSite: 7, siteDisconnected: false, backupInProgress: true, updateBulkSelection() {} } );
    h.load( 'enableSnapshotControls' ); h.load( 'bulkControls' ); h.context.bulkControls( true );
    h.context.backupInProgress = false;
    h.context.bulkControls( false );
    assert.equal( element( '#mainwp-native-backup-now-btn' ).disabled, false );
    assert.equal( element( '#mainwp-snapshots-cleanup-btn' ).disabled, false );
} );
test( 'unexplained refusals use refusal wording rather than a lost-response message', async () => {
    const f = fixture( { 11: { can_proceed: false, refusals: [], warnings: [], would_affect: { snapshot_ids: [ 11 ] } } } );
    f.controller.select( 11, true );
    assert.equal( ( await f.controller.preview() )[0].error, 'Refused.' );
} );
test( 'server errors are shown once while unexplained refusals retain their fallback', () => {
    const h = pageHarness( { bulkLabels: { deleted: 'Deleted', skippedItem: 'Skipped', failedItem: 'Failed' }, deleteResultLines: details => details.errors.length ? [ 'Incomplete.', 'Busy.' ] : [ 'Incomplete.' ] } );
    h.load( 'bulkResultLines' );
    const detailed = h.context.bulkResultLines( [ { id: 11, status: 'failed', message: 'Busy.', details: { deleted_ids: [], errors: [ { message: 'Busy.' } ] } } ] );
    assert.deepEqual( Array.from( detailed ), [ '#11: Failed', 'Incomplete.', 'Busy.' ] );
    const unexplained = h.context.bulkResultLines( [ { id: 12, status: 'failed', message: 'Refused.', details: { deleted_ids: [], errors: [] } } ] );
    assert.deepEqual( Array.from( unexplained ), [ '#12: Failed: Refused.', 'Incomplete.' ] );
} );
test( 'preview names chain members and each actual cloud-copy policy before confirmation', () => {
    const h = pageHarness( { bulkLabels: { affected: 'Affected backups: %s.', cloudDelete: 'Cloud copies: queue deletion if present.', cloudKeep: 'Cloud copies: keep in storage.' }, snapshotIdList: ids => ids.map( id => '#' + id ).join( ', ' ) } );
    h.load( 'bulkPreviewLines' );
    const deleting = h.context.bulkPreviewLines( { id: 11, error: '', warnings: [], effect: { snapshot_ids: [ 11, 12 ], delete_cloud_copies: true } } );
    assert.deepEqual( Array.from( deleting ), [ '#11', 'Affected backups: #11, #12.', 'Cloud copies: queue deletion if present.' ] );
    const keeping = h.context.bulkPreviewLines( { id: 12, error: 'Refused.', warnings: [ 'Cloud copies no longer appear in MainWP.' ], effect: { snapshot_ids: [ 12 ], delete_cloud_copies: false } } );
    assert.deepEqual( Array.from( keeping ), [ '#12: Refused.', 'Affected backups: #12.', 'Cloud copies: keep in storage.', 'Cloud copies no longer appear in MainWP.' ] );
    assert.deepEqual( Array.from( h.context.bulkPreviewLines( { id: 13, error: 'Missing backup.', warnings: [] } ) ), [ '#13: Missing backup.' ] );
} );
