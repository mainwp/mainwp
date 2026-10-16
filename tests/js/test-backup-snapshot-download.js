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
// Every type the executor records, crossed with every source snapshot_source() returns.
const matrix = [
    [ 'full', 'local', '' ],
    [ 'full', 'remote', 'missing' ],
    [ 'full', 'remote_chunked', 'missing' ],
    [ 'full', 'unavailable', 'missing' ],
    [ 'db', 'local', '' ],
    [ 'db', 'remote', 'missing' ],
    [ 'db', 'remote_chunked', 'missing' ],
    [ 'db', 'unavailable', 'missing' ],
    [ 'files', 'local', '' ],
    [ 'files', 'remote', 'missing' ],
    [ 'files', 'remote_chunked', 'missing' ],
    [ 'files', 'unavailable', 'missing' ],
    [ 'incremental', 'local', 'incremental' ],
    [ 'incremental', 'remote', 'incremental' ],
    [ 'incremental', 'remote_chunked', 'incremental' ],
    [ 'incremental', 'unavailable', 'incremental' ]
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

const removalMapStart = source.indexOf( 'var removalLabels = {' );
assert.ok( removalMapStart >= 0, 'Removed snapshots need cause-specific labels.' );
const removalMapEnd = source.indexOf( '\n            };', removalMapStart ) + '\n            };'.length;
const removalMap = source.slice( removalMapStart, removalMapEnd );
const removalCases = [
    [ 'manual', 'Deleted manually' ],
    [ 'retention', 'Removed by retention' ],
    [ 'task_deleted', 'Deleted with its backup task' ],
    [ 'cleanup', 'Removed by Clean Up' ]
];
for ( const [ reason, label ] of removalCases ) {
    assert.ok( removalMap.includes( "'" + reason + "': '<?php echo esc_js( esc_html__( '" + label + "', 'mainwp' ) ); ?>'" ), reason + ' must be translated and escaped.' );
}
vm.runInContext( removalMap.replace( /<\?php echo esc_js\( esc_html__\( '([^']+)', 'mainwp' \) \); \?>/g, '$1' ), context );
context.sourceLabels = { unavailable: 'Removed by retention', local: 'This dashboard', remote: 'Cloud storage' };
const sourceCellStart = source.indexOf( 'var sourceCell = ' );
const sourceCellEnd = source.indexOf( "if ( 'display' === type )", sourceCellStart );
assert.ok( sourceCellStart >= 0 && sourceCellEnd > sourceCellStart );
vm.runInContext( 'var removalSourceCell = function (snap) { ' + source.slice( sourceCellStart, sourceCellEnd ) + 'return sourceCell; };', context );
for ( const [ reason, label ] of removalCases.concat( [ [ '', 'Removed by retention' ], [ 'x', 'Removed by retention' ], [ 'constructor', 'Removed by retention' ], [ '__proto__', 'Removed by retention' ], [ 'toString', 'Removed by retention' ], [ undefined, 'Removed by retention' ] ] ) ) {
    assert.equal( context.removalSourceCell( { source: 'unavailable', removal_reason: reason } ), label, String( reason ) );
}
assert.equal( context.removalSourceCell( { source: 'local', removal_reason: 'manual' } ), 'This dashboard' );
assert.equal( context.removalSourceCell( { source: 'remote', removal_reason: 'cleanup' } ), 'Cloud storage' );
console.log( 'OK (12 snapshot removal label cases)' );
