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
    canDownload: true,
    downloadLabels: { action: 'Download', incremental: 'Restore this file chain from the Dashboard.', missing: 'The archive is not on this Dashboard.' },
    escAttr: value => String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
};
vm.createContext( context );
vm.runInContext( source.slice( start, end ), context );
// Input matrix for downloadButton(): every type/source pair the list can send. Fomantic gives disabled
// buttons pointer-events: none, so a disabled button needs its reason on a wrapper (hover) and on the
// anchor itself (accessible description).
const enabled = '<a href="#" class="ui mini basic grey button mainwp-download-snapshot" data-snapshot="17"><i class="download icon"></i>Download</a>';
const disabled = reason => '<span title="' + reason + '"><a href="#" class="ui mini basic grey button mainwp-download-snapshot disabled" data-snapshot="17" aria-disabled="true" title="' + reason + '"><i class="download icon"></i>Download</a></span>';
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
context.canDownload = false;
for ( const [ type, source ] of matrix ) {
    assert.equal( context.downloadButton( { id: 17, type, source } ), '', 'No right: ' + type + '/' + source );
}
context.canDownload = true;
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
    attr: ( name, value ) => { row[ name ] = value; },
    css: () => {}
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
    [ 'manual', 'Deleted Manually' ],
    [ 'retention', 'Removed by Retention' ],
    [ 'task_deleted', 'Deleted with Its Backup Task' ],
    [ 'cleanup', 'Removed by Clean Up' ]
];
for ( const [ reason, label ] of removalCases ) {
    assert.ok( removalMap.includes( "'" + reason + "': '<?php echo esc_js( esc_html__( '" + label + "', 'mainwp' ) ); ?>'" ), reason + ' must be translated and escaped.' );
}
vm.runInContext( removalMap.replace( /<\?php echo esc_js\( esc_html__\( '([^']+)', 'mainwp' \) \); \?>/g, '$1' ), context );
assert.ok( source.includes( "'removed': '<?php echo esc_js( esc_html__( 'Removed', 'mainwp' ) ); ?>'" ), 'Rows pruned before causes were recorded need a translated neutral label.' );
context.sourceLabels = { unavailable: 'Removed by Retention', removed: 'Removed', local: 'This dashboard', remote: 'Cloud storage', damaged: 'Damaged Archive' };
const sourceCellStart = source.indexOf( 'var sourceCell = ' );
const sourceCellEnd = source.indexOf( "if ( 'display' === type )", sourceCellStart );
assert.ok( sourceCellStart >= 0 && sourceCellEnd > sourceCellStart );
vm.runInContext( 'var removalSourceCell = function (snap) { ' + source.slice( sourceCellStart, sourceCellEnd ) + 'return sourceCell; };', context );
for ( const [ reason, label ] of removalCases.concat( [ [ '', 'Removed' ], [ 'x', 'Removed' ], [ 'constructor', 'Removed' ], [ '__proto__', 'Removed' ], [ 'toString', 'Removed' ], [ undefined, 'Removed' ] ] ) ) {
    assert.equal( context.removalSourceCell( { source: 'unavailable', status: 'pruned', removal_reason: reason } ), label, String( reason ) );
}
// A complete backup whose files are missing was not pruned; its label is unchanged.
assert.equal( context.removalSourceCell( { source: 'unavailable', status: 'complete', removal_reason: '' } ), 'Removed by Retention' );
assert.equal( context.removalSourceCell( { source: 'local', status: 'pruned', removal_reason: 'manual' } ), 'This dashboard' );
assert.equal( context.removalSourceCell( { source: 'remote', status: 'complete', removal_reason: 'cleanup' } ), 'Cloud storage' );
console.log( 'OK (13 snapshot removal label cases)' );

const badgeStart = source.indexOf( 'var offsite = ' );
const badgeEnd = source.indexOf( 'if ( snap.uploads', badgeStart );
assert.ok( badgeStart >= 0 && badgeEnd > badgeStart );
context.offsiteLabels = { verified: [ 'green', 'Offsite Verified' ] };
vm.runInContext( 'var offsiteBadge = function (snap) { var sourceCell = "Removed"; ' + source.slice( badgeStart, badgeEnd ) + ' return sourceCell; };', context );
assert.equal( context.offsiteBadge( { status: 'pruned', offsite_state: 'verified' } ), 'Removed' );
assert.equal( context.offsiteBadge( { status: 'complete', offsite_state: 'verified' } ), 'Removed <span class="ui small basic green label">Offsite Verified</span>' );

const actionsStart = source.indexOf( '"className": "collapsing right aligned"' );
const actionsRenderStart = source.indexOf( '"render": ', actionsStart ) + '"render": '.length;
const actionsRenderEnd = source.indexOf( '\n                        }', actionsRenderStart ) + '\n                        }'.length;
assert.ok( actionsStart >= 0 && actionsRenderEnd > actionsRenderStart );
context.canRestore = true;
context.backupInProgress = false;
context.deleteButton = snap => '<a class="mainwp-delete-snapshot" data-snapshot="' + snap.id + '"></a>';
vm.runInContext( 'var renderActions = ' + source.slice( actionsRenderStart, actionsRenderEnd ).replace( /<\?php[\s\S]*?\?>/g, 'Restore' ) + ';', context );
const damagedReason = 'Archive <script>alert("damage")</script> cannot restore.';
const damagedActions = context.renderActions( null, 'display', { id: 17, type: 'full', status: 'complete', source: 'unavailable', restorable: false, damaged: true, restore_reason: damagedReason } );
assert.ok( damagedActions.includes( 'Archive &lt;script&gt;alert(&quot;damage&quot;)&lt;/script&gt; cannot restore.' ), 'Damage must be visible as escaped text.' );
assert.ok( ! damagedActions.includes( 'title=' ), 'The damage explanation must be visible without hovering.' );
assert.ok( ! damagedActions.includes( 'mainwp-restore-snapshot' ) );
assert.ok( damagedActions.includes( 'mainwp-delete-snapshot' ), 'A damaged backup can still be deleted.' );
assert.equal( context.renderActions( null, 'display', { status: 'pruned', source: 'unavailable', damaged: false } ), '', 'Other unavailable states retain their actions.' );
const cloudActions = context.renderActions( null, 'display', { id: 17, type: 'full', status: 'complete', source: 'remote', restorable: true, damaged: true, restore_reason: '' } );
assert.ok( cloudActions.includes( 'mainwp-restore-snapshot' ), 'Damage to local bytes does not block cloud restore.' );

const damagedRow = { classes: [] };
context.createRow( damagedRow, { restorable: false, type: 'full', source: 'unavailable', damaged: true, restore_reason: 'Damaged.' } );
assert.deepEqual( damagedRow.classes, [], 'A damaged row must not get the disabled class, which blocks its Delete button.' );
const missingRow = { classes: [] };
context.createRow( missingRow, { restorable: false, type: 'full', source: 'unavailable', damaged: false, restore_reason: 'Missing.' } );
assert.ok( missingRow.classes.includes( 'disabled' ), 'Other unavailable rows keep their existing treatment.' );
assert.equal( context.removalSourceCell( { source: 'unavailable', status: 'complete', damaged: true, removal_reason: '' } ), 'Damaged Archive', 'A damaged backup is not labeled as removed.' );
const filterStart = source.indexOf( '$.fn.dataTable.ext.search.push( function' );
const filterEnd = source.indexOf( '\n            } );', filterStart ) + '\n            } );'.length;
assert.ok( filterStart >= 0 && filterEnd > filterStart );
context.$ = { fn: { dataTable: { ext: { search: [] } } } };
context.showRemovedSnapshots = false;
vm.runInContext( source.slice( filterStart, filterEnd ), context );
const keep = snap => context.$.fn.dataTable.ext.search[0]( { nTable: { id: 'mainwp-restore-snapshots-table' } }, [], 0, snap );
assert.equal( keep( { source: 'unavailable', damaged: true } ), true, 'A damaged backup stays listed while removed backups are hidden.' );
assert.equal( keep( { source: 'unavailable', damaged: false } ), false );
assert.equal( keep( { source: 'local', damaged: false } ), true );
console.log( 'OK (damaged snapshot row cases)' );
