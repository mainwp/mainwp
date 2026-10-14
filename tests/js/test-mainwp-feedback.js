'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const { test } = require( 'node:test' );
const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
const groupsSource = fs.readFileSync( require.resolve( '../../assets/js/mainwp-groups.js' ), 'utf8' );
const element = { children: [], classes: new Set(), visible: false };
class Node {
    constructor( type, value ) {
        this.nodeType = type;
        if ( 3 === type ) {
            this.textContent = String( value );
        } else {
            this.tagName = value.toUpperCase();
            this.children = [];
        }
    }
    appendChild( node ) {
        assert.ok( node instanceof Node );
        this.children.push( node );
        return node;
    }
}
const textNode = value => new Node( 3, value );
const query = {
    empty() { element.children = []; return this; },
    contents() { return element.children; },
    append( ...nodes ) {
        for ( const node of nodes ) {
            const contents = node instanceof jQuery ? node.nodes : [ node ];
            for ( const child of contents ) {
                assert.ok( child instanceof Node, 'Feedback must insert nodes, never HTML strings.' );
                element.children.push( child );
            }
        }
        return this;
    },
    removeClass( names ) { names.split( ' ' ).forEach( name => element.classes.delete( name ) ); return this; },
    addClass( names ) { names.split( ' ' ).forEach( name => element.classes.add( name ) ); return this; },
    show() { element.visible = true; return this; },
    fadeOut() { element.visible = false; return this; }
};
function jQuery( selector, attributes ) {
    if ( '#feedback' === selector || '#mainwp-message-zone' === selector ) {
        return query;
    }
    if ( !( this instanceof jQuery ) ) {
        return new jQuery( selector, attributes );
    }
    if ( selector instanceof Node ) {
        this.nodes = [ selector ];
    } else if ( Array.isArray( selector ) ) {
        assert.ok( selector.every( node => node instanceof Node ) );
        this.nodes = selector;
    } else {
        assert.ok( [ '<i>', '<a>' ].includes( selector ) );
        const node = new Node( 1, selector.slice( 1, -1 ) );
        Object.assign( node, attributes );
        if ( attributes.class ) {
            node.className = attributes.class;
        }
        this.nodes = [ node ];
    }
    this.nodes.forEach( ( node, index ) => { this[index] = node; } );
}
jQuery.prototype.add = function ( node ) {
    assert.ok( node instanceof Node );
    this.nodes.push( node );
    return this;
};
const context = {
    jQuery,
    Node,
    document: { createElement: tag => new Node( 1, tag ), createTextNode: textNode },
    MainWP: { I18n: { t: value => value } },
    scrollElementTop() {}
};
vm.createContext( context );
for ( const name of [ 'feedback', 'mainwp_showhide_message', 'mainwp_feedback_linked_message', 'mainwp_get_reconnect_error' ] ) {
    const start = source.indexOf( 'globalThis.' + name + ' = function ' );
    assert.ok( start >= 0, name + ' must be loadable from mainwp.js.' );
    const end = source.indexOf( '\n};', start ) + '\n};'.length;
    vm.runInContext( source.slice( start, end ), context );
}
const names = process.argv[2] ? [ process.argv[2] ] : [ 'feedback', 'mainwp_showhide_message' ];
function checkRendering( makeContent, checkContent ) {
    for ( const name of names ) {
        for ( const append of [ false, true ] ) {
            for ( const previous of [ '', 'Earlier message' ] ) {
                element.children = previous ? [ textNode( previous ) ] : [];
                element.classes = new Set( [ 'yellow', 'green' ] );
                element.visible = false;
                const content = makeContent();
                context[ name ]( 'feedback', content, 'red', append );
                const offset = append && previous ? 2 : 0;
                if ( offset ) {
                    assert.equal( element.children[0].textContent, previous );
                    assert.equal( element.children[1].tagName, 'BR' );
                }
                checkContent( element.children.slice( offset ), content );
                assert.deepEqual( [ ...element.classes ], [ 'red' ] );
                assert.equal( element.visible, true );
            }
        }
    }
}
test( 'hostile strings render as text', () => {
    const malicious = '<img src=x onerror=alert(1)>';
    checkRendering( () => malicious, nodes => {
        assert.deepEqual( nodes, [ textNode( malicious ) ] );
    } );
} );
test( 'spinner callers insert an icon and message text', () => {
    const caller = source.match( /^\s*feedback\('mainwp-message-zone', (.*), ''\);$/m );
    assert.ok( caller, 'The site-removal spinner caller must be loadable.' );
    checkRendering( () => vm.runInContext( caller[1], context ), nodes => {
        assert.equal( nodes.length, 2 );
        assert.equal( nodes[0].tagName, 'I' );
        assert.equal( nodes[0].className, 'notched circle loading icon' );
        assert.deepEqual( nodes[1], textNode( ' Removing the site. Please wait...' ) );
    } );
} );
test( 'error arrays insert text lines with BR separators', () => {
    const errors = [ '<img src=x onerror=alert(1)>', 'a & b', '<b>last error</b>' ];
    checkRendering( () => errors, nodes => {
        assert.deepEqual( nodes, [ textNode( errors[0] ), new Node( 1, 'br' ), textNode( errors[1] ), new Node( 1, 'br' ), textNode( errors[2] ) ] );
    } );
    const linked = context.mainwp_feedback_linked_message( 'Click %1a & <b>b</b>%2 for help.', [ { href: 'https://docs.mainwp.com/' } ] );
    checkRendering( () => [ errors[0], linked, errors[2] ], nodes => {
        assert.equal( nodes.length, 7 );
        assert.deepEqual( nodes[0], textNode( errors[0] ) );
        assert.equal( nodes[1].tagName, 'BR' );
        assert.deepEqual( nodes[2], textNode( 'Click ' ) );
        assert.equal( nodes[3].tagName, 'A' );
        assert.equal( nodes[3].href, 'https://docs.mainwp.com/' );
        assert.deepEqual( nodes[3].children, [ textNode( 'a & <b>b</b>' ) ] );
        assert.deepEqual( nodes[4], textNode( ' for help.' ) );
        assert.equal( nodes[5].tagName, 'BR' );
        assert.deepEqual( nodes[6], textNode( errors[2] ) );
    } );
} );
test( 'converted pre-escaped callers render ampersands once', () => {
    const caller = groupsSource.match( /^\s*feedback\('mainwp-message-zone', (.*), 'ui red message'\);$/m );
    assert.ok( caller, 'The tag-deletion error caller must be loadable.' );
    context.response = 'ERROR a & b';
    checkRendering( () => vm.runInContext( caller[1], context ), nodes => {
        assert.deepEqual( nodes, [ textNode( 'a & b' ) ] );
    } );
} );
test( 'jQuery and DOM node arguments preserve node identity', () => {
    for ( const wrap of [ node => node, node => jQuery( node ) ] ) {
        const node = new Node( 1, 'span' );
        node.textContent = 'a & b';
        node.listener = () => {};
        checkRendering( () => wrap( node ), nodes => {
            assert.equal( nodes.length, 1 );
            assert.equal( nodes[0], node );
        } );
    }
    const reconnect = context.mainwp_get_reconnect_error( 'reconnect_failed', '7&next=<img>' );
    checkRendering( () => reconnect, nodes => {
        assert.equal( nodes[1], reconnect.nodes[1] );
        assert.equal( nodes[1].href, 'admin.php?page=managesites&id=7%26next%3D%3Cimg%3E' );
        assert.deepEqual( nodes[1].children, [ textNode( 'Site Settings page' ) ] );
    } );
    context.mainwp_showhide_message( 'feedback', '', 'red', false );
    assert.deepEqual( element.children, [] );
    assert.equal( element.visible, false );
} );
