'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );
const start = source.indexOf( 'function downloadButton( snap )' );
assert.ok( start >= 0, 'The snapshot row must render a Download action.' );
const end = source.indexOf( '\n            }', start ) + '\n            }'.length;
const context = {
    siteDisconnected: false,
    downloadLabels: { action: 'Download', incremental: 'Restore this file chain from the Dashboard.', missing: 'The archive is not on this Dashboard.' },
    escAttr: value => String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
};
vm.createContext( context );
vm.runInContext( source.slice( start, end ), context );
for ( const type of [ 'full', 'db', 'files' ] ) {
    const html = context.downloadButton( { id: 17, type, source: 'local' } );
    assert.match( html, /class="ui mini basic button mainwp-download-snapshot"/ );
    assert.match( html, /data-snapshot="17"/ );
    assert.match( html, />Download<\/a>$/ );
}
for ( const snap of [
    { id: 17, type: 'incremental', source: 'local' },
    { id: 17, type: 'full', source: 'remote' },
    { id: 17, type: 'db', source: 'unavailable' }
] ) {
    const html = context.downloadButton( snap );
    assert.match( html, /mainwp-download-snapshot disabled/ );
    assert.match( html, /title="[^"\n]+"/ );
    assert.match( html, /aria-disabled="true"/ );
}
context.siteDisconnected = true;
assert.match( context.downloadButton( { id: 17, type: 'full', source: 'local' } ), /mainwp-download-snapshot disabled/ );
const rowStart = source.indexOf( '"createdRow": function ( row, snap )' );
const rowEnd = source.indexOf( '\n                },', rowStart );
context.$ = row => ( {
    addClass: name => row.classes.push( name ),
    attr: ( name, value ) => { row[ name ] = value; }
} );
vm.runInContext( 'var createRow = ' + source.slice( rowStart + '"createdRow": '.length, rowEnd ) + '\n                };', context );
const filesRow = { classes: [] };
context.createRow( filesRow, { restorable: false, type: 'files', source: 'local', restore_reason: 'Update the Child to restore.' } );
assert.ok( ! filesRow.classes.includes( 'disabled' ), 'A local files archive stays downloadable when the Child cannot restore it.' );
assert.equal( filesRow.title, 'Update the Child to restore.' );
console.log( 'OK (8 snapshot Download row cases)' );
