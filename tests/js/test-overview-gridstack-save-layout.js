'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const mainSource = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );

function slice( source, startText, endText ) {
    const start = source.indexOf( startText );
    const end = source.indexOf( endText, start );
    assert.ok( start >= 0 && end > start, startText );
    return source.slice( start, end );
}

const saveLayout = slice( mainSource, 'let mainwp_overview_gridstack_save_layout =', '\nglobalThis.mainwp_init_ui_calendar =' );

function load() {
    const posts = [];
    const context = {
        jQuery: { post: ( url, data ) => posts.push( data ) },
        ajaxurl: 'admin-ajax.php',
        page_sortablewidgets: 'toplevel_page_mainwp_tab',
        page_widget: 'mainwp_tab',
        mainwp_secure_data: data => data,
    };
    vm.runInNewContext( saveLayout + '\nglobalThis.save = mainwp_overview_gridstack_save_layout;', context );
    return { save: context.save, posts };
}

function grid( column, nodes, columnOpts = { columnMax: 12, breakpoints: [ { w: 768, c: 1 } ] } ) {
    return {
        opts: { column, columnOpts },
        getColumn() {
            return this.opts.column;
        },
        engine: { nodes: nodes.map( ( [ id, x, y, w, h ] ) => ( { el: { id }, x, y, w, h } ) ) },
    };
}

test( 'saves the layout when the grid shows all columns', () => {
    const { save, posts } = load();
    save( 0, grid( 12, [ [ 'widget-a', 0, 0, 6, 20 ], [ 'widget-b', 6, 0, 6, 20 ] ] ) );

    assert.equal( posts.length, 1 );
    assert.equal( posts[ 0 ].action, 'mainwp_widgets_order' );
    assert.deepEqual( JSON.parse( posts[ 0 ].order ), [ { x: 0, y: 0, w: 6, h: 20 }, { x: 6, y: 0, w: 6, h: 20 } ] );
    assert.deepEqual( JSON.parse( posts[ 0 ].wgids ), [ 'widget-a', 'widget-b' ] );
} );

test( 'does not save the stacked one-column layout used on narrow screens', () => {
    const { save, posts } = load();
    save( 0, grid( 1, [ [ 'widget-a', 0, 0, 1, 20 ], [ 'widget-b', 0, 20, 1, 20 ] ] ) );

    assert.equal( posts.length, 0 );
} );

test( 'treats 12 columns as the full grid when columnMax is not set', () => {
    const { save, posts } = load();
    save( 0, grid( 1, [ [ 'widget-a', 0, 0, 1, 20 ] ], {} ) );
    save( 0, grid( 12, [ [ 'widget-a', 0, 0, 12, 20 ] ], {} ) );

    assert.equal( posts.length, 1 );
    assert.deepEqual( JSON.parse( posts[ 0 ].order ), [ { x: 0, y: 0, w: 12, h: 20 } ] );
} );
