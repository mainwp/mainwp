'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp-ui.js' ), 'utf8' );

function slice( startText, endText ) {
    const start = source.indexOf( startText );
    const end = source.indexOf( endText, start );
    assert.ok( start >= 0 && end > start, startText );
    return source.slice( start, end );
}

const pickerScript = slice( 'let mainwp_ss_select_disconnected = function', 'let mainwp_newpost_updateCategories = function' );

const LISTS = {
    site: '.mainwp-select-sites-list',
    staging: '#mainwp-select-staging-sites-list',
    group: '.mainwp-select-groups-list',
    client: '#mainwp-select-clients-list',
};

// The Select Sites box: four lists of checkboxes and the hidden select_by / select_sites_tab inputs.
function picker( { keepSelection, selectBy = 'site', checked = {} } ) {
    const wrapper = { keepSelection, inputs: { select_by: selectBy, select_sites_tab: selectBy }, lists: {} };
    for ( const [ tab, selector ] of Object.entries( LISTS ) ) {
        const count = 'site' === tab ? 3 : 2;
        const boxes = [];
        for ( let i = 0; i < count; i++ ) {
            boxes.push( { checked: i < ( checked[ tab ] || 0 ), selector } );
        }
        wrapper.lists[ selector ] = boxes;
    }

    const handlers = [];
    const document = {};
    const counter = { calls: 0 };

    const wrap = function ( node ) {
        return {
            length: 1,
            is: selector => '[data-keep-tab-selection]' === selector && node.keepSelection,
            find( selector ) {
                const input = /^input\[name="(\w+)"\]$/.exec( selector );
                if ( input ) {
                    return {
                        val( value ) {
                            if ( 0 === arguments.length ) return node.inputs[ input[ 1 ] ];
                            node.inputs[ input[ 1 ] ] = value;
                            return this;
                        },
                    };
                }
                const boxes = node.lists[ selector.replace( / INPUT:checkbox$/, '' ) ];
                assert.ok( boxes, 'unexpected selector ' + selector );
                return {
                    prop( name, value ) {
                        assert.equal( name, 'checked' );
                        boxes.forEach( box => { box.checked = value; } );
                        return this;
                    },
                };
            },
        };
    };

    const jQuery = function ( node ) {
        if ( node === document ) {
            return { on: ( event, selector, callback ) => { handlers.push( { event, selector, callback } ); } };
        }
        if ( node.selectBy ) {
            return {
                attr: name => ( 'select-by' === name ? node.selectBy : undefined ),
                closest: selector => ( '.mainwp_select_sites_wrapper' === selector ? wrap( wrapper ) : { length: 0 } ),
            };
        }
        return {
            closest( selector ) {
                if ( '.mainwp_select_sites_wrapper' === selector ) return wrap( wrapper );
                return { length: selector === node.selector ? 1 : 0 };
            },
        };
    };

    const context = {
        jQuery,
        document,
        globalThis: {},
        console: { log() {} },
        mainwp_update_selection_counter: () => { counter.calls++; },
    };
    context.globalThis = context;
    vm.createContext( context );
    vm.runInContext( pickerScript, context );

    const changeHandlers = handlers.filter( handler => 'change' === handler.event );
    return {
        wrapper,
        counter,
        changeHandlers,
        show: tab => context.mainwp_sites_selection_onvisible_callback( { selectBy: tab } ),
        boxes: tab => wrapper.lists[ LISTS[ tab ] ],
        checkedCount: tab => wrapper.lists[ LISTS[ tab ] ].filter( box => box.checked ).length,
        click( tab, index ) {
            const box = wrapper.lists[ LISTS[ tab ] ][ index ];
            box.checked = ! box.checked;
            changeHandlers.forEach( handler => handler.callback.call( box ) );
        },
    };
}

test( 'the backup task editor keeps the saved sites while the user browses to Tags and back', () => {
    const box = picker( { keepSelection: true, checked: { site: 3 } } );
    box.show( 'group' );
    assert.equal( box.checkedCount( 'site' ), 3 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
    assert.equal( box.wrapper.inputs.select_sites_tab, 'group' );
    box.show( 'site' );
    assert.equal( box.checkedCount( 'site' ), 3 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
    assert.equal( box.wrapper.inputs.select_sites_tab, 'site' );
    assert.ok( box.counter.calls >= 2 );
} );

test( 'browsing to Staging and back also keeps the saved sites', () => {
    const box = picker( { keepSelection: true, checked: { site: 3 } } );
    box.show( 'staging' );
    box.show( 'site' );
    assert.equal( box.checkedCount( 'site' ), 3 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
} );

test( 'a task saved with tags keeps its tags while the user browses to Sites', () => {
    const box = picker( { keepSelection: true, selectBy: 'group', checked: { group: 1 } } );
    box.show( 'site' );
    assert.equal( box.checkedCount( 'group' ), 1 );
    assert.equal( box.wrapper.inputs.select_by, 'group' );
} );

test( 'checking a tag replaces the selected sites and targets tags', () => {
    const box = picker( { keepSelection: true, checked: { site: 3 } } );
    box.show( 'group' );
    box.click( 'group', 0 );
    assert.equal( box.checkedCount( 'site' ), 0 );
    assert.equal( box.checkedCount( 'group' ), 1 );
    assert.equal( box.wrapper.inputs.select_by, 'group' );
} );

test( 'checking a staging site replaces the live sites and tags', () => {
    const box = picker( { keepSelection: true, selectBy: 'group', checked: { group: 1 } } );
    box.show( 'staging' );
    box.click( 'staging', 1 );
    assert.equal( box.checkedCount( 'group' ), 0 );
    assert.equal( box.checkedCount( 'staging' ), 1 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
} );

test( 'checking a site after choosing a tag goes back to targeting sites', () => {
    const box = picker( { keepSelection: true, selectBy: 'group', checked: { group: 2 } } );
    box.show( 'site' );
    box.click( 'site', 2 );
    assert.equal( box.checkedCount( 'group' ), 0 );
    assert.equal( box.checkedCount( 'site' ), 1 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
} );

test( 'unchecking an item leaves the other tabs alone', () => {
    const box = picker( { keepSelection: true, checked: { site: 3 } } );
    box.show( 'group' );
    box.click( 'group', 0 );
    box.show( 'site' );
    box.click( 'site', 0 );
    box.click( 'site', 0 );
    assert.equal( box.checkedCount( 'group' ), 0 );
    assert.equal( box.checkedCount( 'site' ), 0 );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
} );

test( 'other pages still clear the other tabs as soon as a tab opens', () => {
    const box = picker( { keepSelection: false, checked: { site: 3 } } );
    box.show( 'group' );
    assert.equal( box.checkedCount( 'site' ), 0 );
    assert.equal( box.wrapper.inputs.select_by, 'group' );
    box.show( 'staging' );
    assert.equal( box.wrapper.inputs.select_by, 'site' );
    assert.equal( box.wrapper.inputs.select_sites_tab, 'staging' );
} );

test( 'checkbox changes on other pages are not handled', () => {
    const box = picker( { keepSelection: false } );
    assert.equal( box.changeHandlers.length, 1 );
    for ( const part of box.changeHandlers[ 0 ].selector.split( ',' ) ) {
        assert.match( part.trim(), /^\.mainwp_select_sites_wrapper\[data-keep-tab-selection\] / );
    }
} );
