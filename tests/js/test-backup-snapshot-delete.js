'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const start = source.indexOf( 'function deleteButton( snap )' );
assert.ok( start >= 0, 'The row must render a Delete action.' );
const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
const context = { snapshotDeleteBusy: false, deleteLabels: { action: 'Delete', busy: 'Wait for the backup or restore to finish.' }, escAttr: value => String( value ).replace( /"/g, '&quot;' ) };
vm.createContext( context );
vm.runInContext( source.slice( start, end ), context );
assert.equal( context.deleteButton( { id: 17, status: 'pruned' } ), '' );
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /mainwp-delete-snapshot"/ );
context.snapshotDeleteBusy = true;
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /disabled/ );
assert.match( context.deleteButton( { id: 17, status: 'complete' } ), /aria-disabled="true"/ );
assert.equal( context.deleteButton( { id: 17, status: 'pruned' } ), '' );
console.log( 'OK (5 snapshot Delete row cases)' );
const previewStart = source.indexOf( 'function showDeletePreview( preview )' );
const previewEnd = source.indexOf( '\n            }', previewStart ) + '\n            }'.length;
const elements = new Map();
context.$ = selector => {
    if ( ! elements.has( selector ) ) elements.set( selector, { items: [], props: {} } );
    const state = elements.get( selector );
    return {
        state,
        empty() { state.items = []; return this; },
        text( value ) { state.text = value; return this; },
        appendTo( target ) { target.state.items.push( state.text ); return this; },
        prop( key, value ) { state.props[ key ] = value; return this; },
        toggle( value ) { state.visible = value; return this; },
        toggleClass( key, value ) { state[ key ] = value; return this; }
    };
};
vm.runInContext( source.slice( previewStart, previewEnd ), context );
const preview = ( cloud, proceed, warnings ) => ( {
    would_affect: { snapshot_ids: [ 17, 21, 24 ], delete_cloud_copies: cloud },
    can_proceed: proceed, warnings, refusals: proceed ? [] : [ { message: 'Busy' } ]
} );
context.showDeletePreview( preview( true, true, [ 'Updates will wait.' ] ) );
assert.deepEqual( elements.get( '#mainwp-snapshot-delete-ids' ).items, [ '#17', '#21', '#24' ] );
assert.equal( elements.get( '#mainwp-snapshot-delete-cloud' ).props.checked, true );
assert.equal( elements.get( '#mainwp-snapshot-delete-warnings' ).text, 'Updates will wait.' );
assert.equal( elements.get( '#mainwp-snapshot-delete-confirm' ).props.disabled, false );
context.showDeletePreview( preview( false, false, [ 'MainWP will stop tracking cloud copies.' ] ) );
assert.equal( elements.get( '#mainwp-snapshot-delete-cloud' ).props.checked, false );
assert.equal( elements.get( '#mainwp-snapshot-delete-result' ).text, 'Busy' );
assert.equal( elements.get( '#mainwp-snapshot-delete-confirm' ).props.disabled, true );
assert.equal( elements.get( '#mainwp-snapshot-delete-warnings' ).visible, true );
console.log( 'OK (8 snapshot Delete preview modal cases)' );
