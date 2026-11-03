'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const uiSource = fs.readFileSync( require.resolve( '../../class/class-mainwp-ui.php' ), 'utf8' );

const wrapperStart = uiSource.indexOf( '<div id="mainwp-grid-stack-wrapper"' );
const scriptStart = uiSource.indexOf( '<script type="text/javascript">', wrapperStart );
const scriptEnd = uiSource.indexOf( '</script>', scriptStart );
const pageScript = uiSource
    .slice( scriptStart + '<script type="text/javascript">'.length, scriptEnd )
    .replace( /<\?php[\s\S]*?\?>/g, '0' );

function run( { gridStack, savedLayout = false } ) {
    const calls = [];
    let ready = null;
    const jQuery = selector => {
        if ( 'document' === selector ) {
            return { ready: fn => ( ready = fn ) };
        }
        return {
            length: '#mainwp-widgets-selected-layout[data-save-on-load="true"]' === selector && savedLayout ? 1 : 0,
            each: () => {},
            removeClass: name => calls.push( [ 'removeClass', selector, name ] ),
            dimmer: action => calls.push( [ 'dimmer', selector, action ] ),
        };
    };
    const context = {
        jQuery,
        document: 'document',
        mainwp_overview_gridstack_save_layout: () => calls.push( [ 'save' ] ),
    };
    if ( gridStack ) {
        context.GridStack = gridStack( calls );
    }
    vm.runInNewContext( pageScript, context );
    return { ready: () => ready( jQuery ), calls };
}

function workingGridStack( calls ) {
    return {
        init: () => {
            calls.push( [ 'init' ] );
            return { on: ( event ) => calls.push( [ 'on', event ] ) };
        },
    };
}

test( 'the grid wrapper starts with the loading class', () => {
    assert.match( uiSource, /<div id="mainwp-grid-stack-wrapper" class="grid-stack mainwp-grid-stack-loading">/ );
} );

test( 'the loading class hides the grid only when scripts run', () => {
    const css = fs.readFileSync( require.resolve( '../../assets/css/mainwp.css' ), 'utf8' );
    assert.match( css, /\.js #mainwp-grid-stack-wrapper\.mainwp-grid-stack-loading\s*\{\s*visibility:\s*hidden;\s*\}/ );
} );

test( 'reveals the grid and hides the dimmer after GridStack has laid it out', () => {
    const { ready, calls } = run( { gridStack: workingGridStack, savedLayout: true } );
    ready();

    assert.deepEqual( calls, [
        [ 'init' ],
        [ 'on', 'change' ],
        [ 'save' ],
        [ 'removeClass', '#mainwp-grid-stack-wrapper', 'mainwp-grid-stack-loading' ],
        [ 'dimmer', '#mainwp-widgets-placeholder', 'hide' ],
    ] );
} );

test( 'still reveals the widgets and hides the dimmer when GridStack did not load', () => {
    const { ready, calls } = run( { gridStack: null } );

    // The error still propagates; real jQuery reports it through jQuery.readyException after the reveal.
    assert.throws( ready, { name: 'ReferenceError', message: 'GridStack is not defined' } );
    assert.deepEqual( calls, [
        [ 'removeClass', '#mainwp-grid-stack-wrapper', 'mainwp-grid-stack-loading' ],
        [ 'dimmer', '#mainwp-widgets-placeholder', 'hide' ],
    ] );
} );

test( 'reveals the widgets when jQuery did not load', () => {
    const calls = [];
    const document = {
        getElementById: id => ( {
            classList: { remove: name => calls.push( [ 'classList.remove', id, name ] ) },
        } ),
    };

    assert.throws( () => vm.runInNewContext( pageScript, { document } ), { name: 'ReferenceError', message: 'jQuery is not defined' } );
    assert.deepEqual( calls, [
        [ 'classList.remove', 'mainwp-grid-stack-wrapper', 'mainwp-grid-stack-loading' ],
    ] );
} );
