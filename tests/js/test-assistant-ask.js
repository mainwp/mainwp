'use strict';

const assert = require( 'node:assert/strict' );
const { test } = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( 'assets/js/mainwp-assistant-panel.js', 'utf8' );

function functionSource( name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    const end = source.indexOf( '\n    }', start ) + 6;
    assert.ok( start > 0 && end > start, name );
    return source.slice( start, end );
}

function methodSource( name ) {
    const start = source.indexOf( '        ' + name + ': function(' );
    assert.ok( start > 0, 'Panel must expose ' + name + '()' );
    return source.slice( start, source.indexOf( '\n        },', start ) + 11 );
}

function fixture( { consent = true, minimized = false, draft = '', mode = 'Docs' } = {} ) {
    const nodes = new Map();
    const timers = [];
    const requests = [];
    const document = { activeElement: null };
    class Element {
        constructor() { this.length = 1; this[ 0 ] = this; this.classes = new Set(); this.handlers = {}; this.value = ''; this.style = {}; }
        addClass( names ) { names.split( ' ' ).forEach( name => this.classes.add( name ) ); return this; }
        removeClass( names ) { names.split( ' ' ).forEach( name => this.classes.delete( name ) ); return this; }
        css( key, value ) { this.style[ key ] = value; return this; }
        show() { return this; }
        hide() { return this; }
        text() { return this; }
        val( value ) { if ( value === undefined ) return this.value; this.value = value; return this; }
        trigger( event ) { this.events = [ ...( this.events || [] ), event ]; if ( event === 'focus' ) this.focus(); return this; }
        focus() { document.activeElement = this; return this; }
        filter() { return this; }
        first() { return this; }
        find( selector ) {
            if ( selector === '.mainwp-assistant-screen' ) return $( '#all-screens' );
            return $( '#first-focusable' );
        }
        modal( config ) { if ( typeof config === 'object' ) this.modalConfig = config; return this; }
        off( event ) { delete this.handlers[ event ]; return this; }
        on( event, handler ) { this.handlers[ event ] = handler; return this; }
    }
    function $( selector ) {
        if ( ! nodes.has( selector ) ) nodes.set( selector, new Element() );
        return nodes.get( selector );
    }
    $.fn = {};
    $.post = ( url, data, callback ) => { requests.push( { data, callback } ); return { fail() {} }; };
    const input = $( '#mainwp-mintlify-input' ).val( draft );
    const state = { isOpen: minimized, panelState: minimized ? 'minimized' : 'normal', previousState: 'normal', currentScreen: 'messages', navigationHistory: [] };
    const context = {
        $, document, state, window: { console }, console,
        mainwpAssistantPanelL10n: { hasConsent: consent, canGrantConsent: true },
        pendingConsentAction: null, ajaxurl: '/ajax',
        $panel: $( '#mainwp-assistant-panel' ), $homeScreen: $( '#home' ), $chatScreen: $( '#chat' ), $title: $( '#title' ),
        MainWPMintlifyChat: { init() {}, mode },
        setTimeout( callback, ms ) { timers.push( { callback, at: now + ms } ); },
        hasLocalStorage: () => false, isMobileViewport: () => true, isValidGeometry: () => false,
        getL10n: ( key, fallback ) => fallback,
        clampGeometry() {}, applyGeometry() {}, lockBodyScroll() {}, updateMaximizeIcon() {}, savePanelState() {},
        updateActiveTab() {}, announceToScreenReader() {}, loadRecentConversation() {}, loadMessagesList() {}
    };
    let now = 0;
    vm.createContext( context );
    vm.runInContext( [ 'requireConsent', 'showConsentModal', 'restoreFromMinimized', 'showChatScreen' ].map( functionSource ).join( '\n' ) +
        '\nvar MainWPAssistantPanel = {\n' + [ 'open', 'showScreen', 'openChat', 'ask' ].map( methodSource ).join( '\n' ) + '\n};', context );
    function advance( ms ) {
        const until = now + ms;
        while ( timers.some( timer => timer.at <= until ) ) {
            timers.sort( ( a, b ) => a.at - b.at );
            const timer = timers.shift();
            now = timer.at;
            timer.callback();
        }
        now = until;
    }
    return { context, input, state, requests, document, advance, panel: context.MainWPAssistantPanel,
        grantConsent() {
            $( '#mainwp-assistant-consent-accept' ).handlers[ 'click.consent' ]();
            assert.equal( requests.length, 1 );
            assert.equal( requests[ 0 ].data.action, 'mainwp_assistant_grant_consent' );
            requests[ 0 ].callback( { success: true } );
        }
    };
}

test( 'ask fills and focuses after screen focus without sending or switching mode', () => {
    for ( const mode of [ 'Docs', 'Sites' ] ) {
        const app = fixture( { mode } );
        app.panel.ask( 'How do I update a site?' );
        app.advance( 50 );
        assert.notEqual( app.document.activeElement, app.input, 'Screen focus runs first' );
        app.advance( 50 );
        assert.equal( app.input.val(), 'How do I update a site?' );
        assert.equal( app.document.activeElement, app.input );
        assert.deepEqual( app.input.events, [ 'input.mintlify', 'focus' ] );
        assert.equal( app.context.MainWPMintlifyChat.mode, mode );
        assert.equal( app.requests.length, 0, 'Prefill must not send' );
    }
} );

test( 'ask preserves a draft and appends the question on a new line', () => {
    const app = fixture( { draft: 'Existing draft' } );
    app.panel.ask( 'New question' );
    app.advance( 100 );
    assert.equal( app.input.val(), 'Existing draft\nNew question' );
} );

test( 'ask restores a minimized panel to chat and retains final input focus', () => {
    const app = fixture( { minimized: true } );
    app.panel.ask( 'Find my sites' );
    app.advance( 1000 );
    assert.equal( app.state.panelState, 'normal' );
    assert.equal( app.state.isOpen, true );
    assert.equal( app.state.currentScreen, 'chat' );
    assert.equal( app.context.$panel.style.display, 'flex' );
    assert.equal( app.input.val(), 'Find my sites' );
    assert.equal( app.document.activeElement, app.input );
} );

test( 'ask stashes its text until the existing consent flow replays it', () => {
    const app = fixture( { consent: false, minimized: true, draft: 'Draft before consent' } );
    app.panel.ask( 'Question needing consent' );
    app.advance( 1000 );
    assert.equal( app.input.val(), 'Draft before consent' );
    assert.equal( app.requests.length, 0 );
    assert.ok( app.context.pendingConsentAction, 'Consent must retain the pending question' );
    app.grantConsent();
    app.advance( 1000 );
    assert.equal( app.context.pendingConsentAction, null );
    assert.equal( app.state.currentScreen, 'chat' );
    assert.equal( app.state.panelState, 'normal' );
    assert.equal( app.input.val(), 'Draft before consent\nQuestion needing consent' );
    assert.equal( app.document.activeElement, app.input );
    assert.equal( app.requests.length, 1, 'Only the consent request is sent' );
} );

test( 'openChat shows the chat screen from closed and minimized panels without touching the draft', () => {
    for ( const minimized of [ false, true ] ) {
        const app = fixture( { minimized, draft: 'Draft' } );
        app.panel.openChat();
        app.advance( 1000 );
        assert.equal( app.state.isOpen, true );
        assert.equal( app.state.panelState, 'normal' );
        assert.equal( app.state.currentScreen, 'chat' );
        assert.equal( app.context.$panel.style.display, 'flex' );
        assert.equal( app.input.val(), 'Draft' );
        assert.equal( app.requests.length, 0 );
    }
} );

test( 'openChat waits for consent and then shows the chat screen', () => {
    const app = fixture( { consent: false } );
    app.panel.openChat();
    app.advance( 1000 );
    assert.notEqual( app.state.currentScreen, 'chat' );
    assert.ok( app.context.pendingConsentAction );
    app.grantConsent();
    app.advance( 1000 );
    assert.equal( app.context.pendingConsentAction, null );
    assert.equal( app.state.currentScreen, 'chat' );
} );
