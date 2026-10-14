'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
const unsafeWrites = [];
const element = { children: [], classes: new Set(), visible: false };
const textNode = value => ( { nodeType: 3, textContent: String( value ) } );
const query = {
    html( value ) {
        if ( undefined === value ) {
            return element.children.map( node => node.textContent || '<br />' ).join( '' );
        }
        if ( '' !== value ) {
            unsafeWrites.push( value );
        }
        element.children = '' === value ? [] : [ textNode( value ) ];
        return this;
    },
    text( value ) { element.children = [ textNode( value ) ]; return this; },
    empty() { element.children = []; return this; },
    contents() { return element.children; },
    append( ...nodes ) {
        for ( const node of nodes ) {
            if ( 'string' === typeof node ) {
                unsafeWrites.push( node );
            }
            element.children.push( node );
        }
        return this;
    },
    removeClass( names ) { names.split( ' ' ).forEach( name => element.classes.delete( name ) ); return this; },
    addClass( names ) { names.split( ' ' ).forEach( name => element.classes.add( name ) ); return this; },
    show() { element.visible = true; return this; },
    fadeOut() { element.visible = false; return this; }
};
const context = {
    jQuery: selector => { assert.equal( selector, '#feedback' ); return query; },
    document: { createElement: tag => ( { nodeType: 1, tagName: tag.toUpperCase() } ), createTextNode: textNode },
    scrollElementTop() {}
};
vm.createContext( context );
for ( const name of [ 'feedback', 'mainwp_showhide_message' ] ) {
    const start = source.indexOf( 'globalThis.' + name + ' = function ' );
    assert.ok( start >= 0, name + ' must be loadable from mainwp.js.' );
    const end = source.indexOf( '\n};', start ) + '\n};'.length;
    vm.runInContext( source.slice( start, end ), context );
}
const malicious = '<img src=x onerror="alert(1)"><script>alert(2)</script> & "text"';
for ( const name of process.argv[2] ? [ process.argv[2] ] : [ 'feedback', 'mainwp_showhide_message' ] ) {
    for ( const append of [ false, true ] ) {
        for ( const previous of [ '', 'Earlier message' ] ) {
            element.children = previous ? [ textNode( previous ) ] : [];
            element.classes = new Set( [ 'yellow', 'green' ] );
            unsafeWrites.length = 0;
            context[ name ]( 'feedback', malicious, 'red', append );
            assert.deepEqual( unsafeWrites, [], name + ' must never send message strings to an HTML execution sink.' );
            const expected = append && previous ? [ textNode( previous ), { nodeType: 1, tagName: 'BR' }, textNode( malicious ) ] : [ textNode( malicious ) ];
            assert.deepEqual( element.children, expected, name + ' must preserve message text and append separators.' );
            assert.deepEqual( [ ...element.classes ], [ 'red' ] );
            assert.equal( element.visible, true );
        }
    }
}
context.mainwp_showhide_message( 'feedback', '', 'red', false );
assert.deepEqual( element.children, [] );
assert.equal( element.visible, false );
console.log( 'OK (9 feedback rendering cases)' );
