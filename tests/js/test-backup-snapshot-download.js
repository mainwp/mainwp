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
// Input matrix for downloadButton(): every type/source pair the list can send. Fomantic gives disabled
// buttons pointer-events: none, so a disabled button needs its reason on a wrapper (hover) and on the
// anchor itself (accessible description).
const enabled = '<a href="#" class="ui mini basic button mainwp-download-snapshot" data-snapshot="17">Download</a>';
const disabled = reason => '<span title="' + reason + '"><a href="#" class="ui mini basic button mainwp-download-snapshot disabled" data-snapshot="17" aria-disabled="true" title="' + reason + '">Download</a></span>';
const matrix = [
    [ 'full', 'local', '' ],
    [ 'db', 'local', '' ],
    [ 'files', 'local', '' ],
    [ 'incremental', 'local', 'incremental' ],
    [ 'incremental', 'remote', 'incremental' ],
    [ 'incremental', 'unavailable', 'incremental' ],
    [ 'full', 'remote', 'missing' ],
    [ 'full', 'remote_chunked', 'missing' ],
    [ 'db', 'unavailable', 'missing' ],
    [ 'files', 'remote', 'missing' ]
];
for ( const [ type, source, reason ] of matrix ) {
    const expected = '' === reason ? enabled : disabled( context.downloadLabels[ reason ] );
    assert.equal( context.downloadButton( { id: 17, type, source } ), expected, type + '/' + source );
}
// The archive is on this Dashboard, so a disconnected site can still be downloaded.
context.siteDisconnected = true;
assert.equal( context.downloadButton( { id: 17, type: 'full', source: 'local' } ), enabled );
context.siteDisconnected = false;
// Both title attributes carry the escaped reason.
context.downloadLabels.missing = 'Not "here" <b>';
assert.equal( context.downloadButton( { id: 17, type: 'full', source: 'remote' } ), disabled( 'Not &quot;here&quot; &lt;b&gt;' ) );
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
console.log( 'OK (' + ( matrix.length + 3 ) + ' snapshot Download row cases)' );
