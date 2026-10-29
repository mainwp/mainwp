'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

function phpToJs( code ) {
    return code.replace( /<\?php[\s\S]*?\?>/g, php => {
        const strings = [ ...php.matchAll( /(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g ) ];
        const index = php.includes( '_n(' ) && /, 2,/.test( php ) ? 1 : 0;
        return strings[ index ][2].replace( /\\'/g, "'" ).replace( /'/g, "\\'" );
    } );
}

function harness() {
    const created = [];
    const handlers = new Map();
    const rowNode = {};
    let shown = false;
    let childContent;
    const row = { node: () => rowNode, data: () => ( { skipped_count: 1, skipped_paths: [ 'host.php' ], uploads: [] } ) };
    row.child = content => { childContent = content; return { show() { shown = true; } }; };
    row.child.isShown = () => shown;
    row.child.hide = () => { shown = false; };
    const context = { backupTypeLabels: { full: 'Full' }, uploadLabels: {}, snapshotsTable: { row: () => row }, escAttr: value => String( value ) };
    context.$ = selector => {
        if ( typeof selector === 'object' && selector.state ) return selector;
        const state = { tag: selector, text: '', children: [], classes: new Set() };
        if ( typeof selector === 'string' && selector.startsWith( '<' ) ) {
            assert.match( selector, /^<(div|p|ul|li)>$/, 'Only constant element tags may be parsed as HTML.' );
            created.push( state );
        }
        return {
            state,
            text( value ) { assert.equal( typeof value, 'string' ); state.text = value; return this; },
            addClass( names ) { names.split( ' ' ).forEach( name => state.classes.add( name ) ); return this; },
            append( child ) { assert.equal( typeof child, 'object', 'Paths must never be HTML appends.' ); state.children.push( child.state ); return this; },
            attr( name, value ) { if ( value === undefined ) return rowNode[ name ]; rowNode[ name ] = value; return this; },
            closest() { return rowNode; },
            on( event, name, callback ) { handlers.set( name, callback ); return this; }
        };
    };
    vm.createContext( context );
    const start = source.indexOf( 'function skippedFilesNote(' );
    assert.ok( start >= 0, 'The list needs a skipped-file note.' );
    vm.runInContext( phpToJs( source.slice( start, source.indexOf( '\n            }', start ) + '\n            }'.length ) ), context );
    const typeStart = source.indexOf( '"data": "type"' );
    const renderStart = source.indexOf( 'function (', typeStart );
    const renderEnd = source.indexOf( '\n                        }', renderStart );
    vm.runInContext( 'var typeCell = ' + phpToJs( source.slice( renderStart, renderEnd ) ) + '\n};', context );
    for ( const name of [ '.mainwp-snapshot-uploads-toggle', '.mainwp-snapshot-skipped-toggle' ] ) {
        const clickStart = source.indexOf( "$( '#mainwp-restore-snapshots-table' ).on( 'click', '" + name + "'" );
        assert.ok( clickStart >= 0 );
        vm.runInContext( phpToJs( source.slice( clickStart, source.indexOf( '\n            } );', clickStart ) + '\n            } );'.length ) ), context );
    }
    const text = node => [ node.text, ...node.children.map( text ) ].join( ' ' ).trim();
    return { context, created, text, click: name => handlers.get( '.mainwp-snapshot-' + name + '-toggle' ).call( rowNode, { preventDefault() {} } ), shown: () => shown, content: () => childContent };
}

for ( const count of [ 1, 2 ] ) {
    test( 'skipped note and toggle use the count sentence for ' + count, () => {
        const h = harness();
        const note = h.context.skippedFilesNote( { skipped_count: count, skipped_paths: [ 'host.php' ] } );
        assert.ok( note.state.classes.has( 'yellow' ) );
        assert.match( h.text( note.state ), new RegExp( count + ( count === 1 ? ' file was' : ' files were' ) + ' not backed up\\.' ) );
        const toggle = h.context.typeCell( 'full', 'display', { skipped_count: count } );
        assert.match( toggle, /class="mainwp-snapshot-skipped-toggle"/ );
        assert.ok( toggle.includes( count + ( count === 1 ? ' file' : ' files' ) + ' not backed up' ) );
        assert.equal( h.context.typeCell( 'full', 'sort', { skipped_count: count } ), 'Full' );
    } );
}

test( 'twenty text-only samples retain XSS payloads as text', () => {
    const h = harness();
    const unsafe = '<img src=x onerror=alert(1)>';
    const note = h.context.skippedFilesNote( { skipped_count: 25, skipped_paths: [ unsafe, ...Array.from( { length: 24 }, ( _, i ) => 'path-' + i ) ] } );
    const items = h.created.filter( node => node.tag === '<li>' );
    assert.equal( items.length, 20 );
    assert.equal( items[0].text, unsafe );
    assert.match( h.text( note.state ), /Showing 20 of 25 files that were not backed up\./ );
} );

test( 'zero count has neither a note nor a toggle', () => {
    const h = harness();
    assert.equal( h.context.skippedFilesNote( { skipped_count: 0 } ), null );
    assert.equal( h.context.typeCell( 'full', 'display', { skipped_count: 0 } ), '<span class="ui small basic blue label"><i class="archive icon"></i>Full</span>' );
} );

test( 'uploads and skipped notes replace each other and repeat clicks hide', () => {
    const h = harness();
    h.click( 'uploads' );
    assert.equal( h.shown(), true );
    assert.equal( typeof h.content(), 'string' );
    h.click( 'skipped' );
    assert.equal( h.shown(), true );
    assert.equal( typeof h.content(), 'object' );
    h.click( 'skipped' );
    assert.equal( h.shown(), false );
    h.click( 'skipped' );
    h.click( 'uploads' );
    assert.equal( h.shown(), true );
    assert.equal( typeof h.content(), 'string' );
    h.click( 'uploads' );
    assert.equal( h.shown(), false );
} );
