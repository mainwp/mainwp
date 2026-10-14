'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const load = name => {
    const start = source.indexOf( 'function ' + name + '(' );
    assert.ok( start >= 0, name + ' must exist in the snapshots page script.' );
    const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
    vm.runInContext( source.slice( start, end ), context );
};
const context = {
    snapshotDeleteBusy: false,
    snapshotsTable: null,
    deleteLabels: {
        action: 'Delete',
        busy: 'A backup or restore is running on this site. Wait for it to finish.',
        complete: 'Backups deleted.',
        incomplete: 'Deletion did not finish. Check which backups remain, then try again.',
        deleted: 'Deleted backups: %s.',
        remaining: 'Backups still present: %s.',
        queued: 'Cloud copies queued for deletion: %s.',
        queuedTo: '%1$s to %2$s'
    },
    destinationNames: {},
    backupTypeLabels: { full: 'Full', incremental: 'Incremental' },
    escAttr: value => String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
};
vm.createContext( context );
load( 'deleteButton' );
assert.equal( context.deleteButton( { id: 17, status: 'pruned' } ), '' );
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /mainwp-delete-snapshot"/ );
context.snapshotDeleteBusy = true;
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /disabled/ );
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /aria-disabled="true"/ );
assert.equal( context.deleteButton( { id: 17, status: 'pruned' } ), '' );
console.log( 'OK (5 snapshot Delete row cases)' );

[ 'snapshotIdList', 'cloudQueueLabel', 'deleteResultLines', 'setDeleteMessages', 'deletePreviewItem', 'showDeletePreview', 'showDeleteResponse' ].forEach( load );
assert.equal( context.snapshotIdList( [ 171, 172 ] ), '#171, #172' );
assert.equal( context.cloudQueueLabel( { snapshot_id: 171, destination_id: 3, destination_name: 'Backblaze' } ), '#171 to Backblaze' );
assert.equal( context.cloudQueueLabel( { snapshot_id: 171, destination_id: 9, destination_name: '' } ), '#171', 'A missing destination name falls back to the id.' );
assert.equal( context.cloudQueueLabel( { snapshot_id: 172, destination_id: 4, destination_name: 'Cost $& Co' } ), '#172 to Cost $& Co', 'Replacement patterns in a name stay literal.' );
assert.deepEqual( Array.from( context.deleteResultLines( {
    status: 'complete', deleted_ids: [ 171, 172 ], remaining_ids: [],
    cloud_deletes_queued: [ { snapshot_id: 171, destination_id: 3, destination_name: 'Backblaze' }, { snapshot_id: 172, destination_id: 9, destination_name: '' } ], errors: []
} ) ), [ 'Backups deleted.', 'Deleted backups: #171, #172.', 'Cloud copies queued for deletion: #171 to Backblaze, #172.' ] );
assert.deepEqual( Array.from( context.deleteResultLines( {
    status: 'refused', deleted_ids: [], remaining_ids: [ 171 ], cloud_deletes_queued: [], errors: [ { code: 'mainwp_operation_failed', message: 'Busy.' } ]
} ) ), [ 'Deletion did not finish. Check which backups remain, then try again.', 'Backups still present: #171.', 'Busy.' ], 'A refusal skips the empty segments.' );
console.log( 'OK (6 snapshot Delete result text cases)' );

const elements = new Map();
const fresh = html => ( { html, items: [], props: {}, classes: new Set() } );
context.$ = selector => {
    let state;
    if ( selector.startsWith( '<' ) ) {
        state = fresh( selector );
    } else {
        if ( ! elements.has( selector ) ) elements.set( selector, fresh( '' ) );
        state = elements.get( selector );
    }
    return {
        state,
        empty() { state.items = []; return this; },
        text( value ) { state.text = value; return this; },
        appendTo( target ) { target.state.items.push( undefined === state.text ? state.html : state.text ); return this; },
        prop( key, value ) { state.props[ key ] = value; return this; },
        toggle( value ) { state.visible = value; return this; },
        addClass( key ) { state.classes.add( key ); return this; },
        removeClass( key ) { state.classes.delete( key ); return this; },
        toggleClass( key, value ) { if ( value ) state.classes.add( key ); else state.classes.delete( key ); return this; }
    };
};
const box = selector => elements.get( selector );
context.snapshotsTable = { rows: () => ( { data: () => ( { toArray: () => [
    { id: 17, type: 'full', created_label: 'Oct 12, 2026 <3:00 pm>' },
    { id: 21, type: 'incremental', created_label: 'Oct 13, 2026 3:00 pm' }
] } ) } ) };
const preview = ( cloud, proceed, warnings ) => ( {
    would_affect: { snapshot_ids: [ 17, 21, 24 ], delete_cloud_copies: cloud },
    can_proceed: proceed, warnings, refusals: proceed ? [] : [ { message: 'Busy' } ]
} );
context.showDeletePreview( preview( true, true, [ 'Updates will wait.', 'Second warning.' ] ) );
const items = box( '#mainwp-snapshot-delete-ids' ).items;
assert.equal( items.length, 3 );
assert.match( items[0], /<strong>#17<\/strong> <span class="ui mini basic label">Full<\/span>/ );
assert.match( items[0], /Oct 12, 2026 &lt;3:00 pm&gt;/, 'The date is escaped.' );
assert.match( items[1], /Incremental/ );
assert.equal( items[2], '<div class="item"><strong>#24</strong></div>', 'A row missing from the table shows only its id.' );
assert.equal( box( '#mainwp-snapshot-delete-cloud' ).props.checked, true );
assert.deepEqual( box( '#mainwp-snapshot-delete-warnings' ).items, [ 'Updates will wait.', 'Second warning.' ] );
assert.equal( box( '#mainwp-snapshot-delete-confirm' ).props.disabled, false );
assert.equal( box( '#mainwp-snapshot-delete-result' ).visible, false );
context.showDeletePreview( preview( false, false, [ 'Cloud copies stay in your storage.' ] ) );
assert.equal( box( '#mainwp-snapshot-delete-cloud' ).props.checked, false );
assert.deepEqual( box( '#mainwp-snapshot-delete-result' ).items, [ 'Busy' ] );
assert.equal( box( '#mainwp-snapshot-delete-result' ).visible, true );
assert.equal( box( '#mainwp-snapshot-delete-confirm' ).props.disabled, true );
assert.equal( box( '#mainwp-snapshot-delete-warnings' ).visible, true );
console.log( 'OK (13 snapshot Delete preview modal cases)' );

context.showDeleteResponse( {
    status: 'partial', deleted_ids: [ 21 ], remaining_ids: [ 17 ], cloud_deletes_queued: [], errors: [ { message: 'Could not remove.' } ], preview: null
} );
assert.deepEqual( box( '#mainwp-snapshot-delete-result' ).items, [ 'Deletion did not finish. Check which backups remain, then try again.', 'Deleted backups: #21.', 'Backups still present: #17.', 'Could not remove.' ] );
assert.ok( box( '#mainwp-snapshot-delete-result' ).classes.has( 'red' ) );
const changed = preview( true, true, [ 'Updates will wait.' ] );
context.showDeleteResponse( {
    status: 'changed', deleted_ids: [], remaining_ids: [ 17, 21, 24 ], cloud_deletes_queued: [],
    errors: [ { code: 'mainwp_operation_failed', message: 'This site\'s backups changed since the preview. Check the new list, then delete again.' } ],
    preview: changed
} );
assert.deepEqual( box( '#mainwp-snapshot-delete-warnings' ).items, [ 'This site\'s backups changed since the preview. Check the new list, then delete again.', 'Updates will wait.' ] );
assert.equal( box( '#mainwp-snapshot-delete-warnings' ).visible, true );
assert.equal( box( '#mainwp-snapshot-delete-result' ).visible, false, 'A changed set is not shown as a result.' );
assert.ok( ! box( '#mainwp-snapshot-delete-result' ).classes.has( 'red' ) );
assert.equal( box( '#mainwp-snapshot-delete-confirm' ).props.disabled, false, 'The fresh preview can be confirmed.' );
assert.equal( context.snapshotDeletePreview, changed );
context.showDeleteResponse( { status: 'complete', deleted_ids: [ 17 ], remaining_ids: [], cloud_deletes_queued: [], errors: [], preview: null } );
assert.deepEqual( box( '#mainwp-snapshot-delete-result' ).items, [ 'Backups deleted.', 'Deleted backups: #17.' ] );
assert.ok( ! box( '#mainwp-snapshot-delete-result' ).classes.has( 'red' ) );
console.log( 'OK (10 snapshot Delete response placement cases)' );
