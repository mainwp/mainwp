'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const logic = require( '../../assets/js/mainwp-assistant-chat-logic.js' );
const source = fs.readFileSync( 'assets/js/mainwp-mintlify-chat.js', 'utf8' );

assert.equal( typeof logic.resolveDocsHandoffAction, 'function' );
for ( const data of [ undefined, null, {}, { handoff_offer: 'true', agent_available: true } ] ) {
    assert.deepEqual( logic.resolveDocsHandoffAction( data ), { offer: false, canContinue: false } );
}
assert.deepEqual( logic.resolveDocsHandoffAction( { handoff_offer: true, agent_available: true } ), { offer: true, canContinue: true } );
for ( const available of [ false, undefined, 'true', 1 ] ) {
    assert.deepEqual( logic.resolveDocsHandoffAction( { handoff_offer: true, agent_available: available } ), { offer: true, canContinue: false } );
}

function functionSource( name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    const end = source.indexOf( '\n    }', start ) + 6;
    assert.ok( start > 0 && end > start, name );
    return source.slice( start, end );
}
const api = functionSource( 'sendToAPI' );
const offer = functionSource( 'renderDocsHandoffOffer' );
for ( const name of [ 'sendMessage', 'reset', 'loadConversation', 'applyModeChange' ] ) {
    assert.match( functionSource( name ), /clearDocsHandoffOffer\(\)/, name + ' must invalidate pending offers' );
}
assert.ok( api.indexOf( 'response.data.handoff_offer === true' ) < api.indexOf( 'isAgentRefusal(' ) );
assert.ok( api.indexOf( 'response.data.handoff_offer === true' ) < api.indexOf( 'setCurrentConversation(' ), 'Offers must not change history state' );
assert.match( api, /renderDocsHandoffOffer\( userMessage, response.data \);\s*return;/ );
assert.match( api, /data\.mode === 'ask' && options && options\.docsHandoffBypass === true/ );
assert.equal( ( api.match( /data\.docs_handoff_bypass = 'true'/g ) || [] ).length, 1 );
assert.match( api, /requestDocsHandoffBypass = !! \( data\.mode === 'ask' && options && options\.docsHandoffBypass === true \)/ );
assert.match( api, /if \( requestDocsHandoffBypass \) \{\s*data\.docs_handoff_bypass = 'true'/ );
assert.match( source, /sendMessage\( retryMessage, false, undefined, \{ docsHandoffBypass: \$\( this \)\.attr\( 'data-docs-bypass' \) === '1' \} \)/ );
assert.match( offer, /sendMessage\( userMessage, false, 'ask', \{ docsHandoffBypass: true \} \)/ );
assert.match( functionSource( 'handleRegenerate' ), /sendMessage\( lastUserMessage, true, undefined, \{ docsHandoffBypass: true \} \)/ );
assert.equal( ( offer.match( /sendMessage\(/g ) || [] ).length, 1, 'Only Ask Docs may send' );
assert.match( offer, /reset\(\);\s*applyModeChange\( 'agent' \);\s*\$input\.val\( userMessage \)\.trigger\( 'input' \)\.focus\(\)/ );
assert.match( functionSource( 'reset' ), /setCurrentConversation\( null \)/ );
assert.match( functionSource( 'reset' ), /messageHistory = \[\]/ );

class Element {
    constructor( html = '' ) { this.html = html; this.nodes = []; this.attributes = {}; this.length = html ? 1 : 0; }
    append( child ) { child.parent = this; this.nodes.push( child ); return this; }
    text( value ) { if ( value === undefined ) { return this.label || ''; } this.label = value; return this; }
    clone() { return new Element( this.html ).text( this.label ); }
    on( event, callback ) { this[ event ] = callback; return this; }
    attr( key, value ) { this.attributes[ key ] = value; return this; }
    prop( key, value ) { this.attributes[ key ] = value; return this; }
    remove() { this.removed = true; return this; }
    focus() { this.focused = true; return this; }
    val( value ) { this.value = value; return this; }
    trigger( event ) { this.triggered = event; return this; }
    nextAll( selector ) {
        assert.equal( selector, '.mainwp-mintlify-assistant-message' );
        return { length: this.parent.nodes.slice( this.parent.nodes.indexOf( this ) + 1 ).filter( node => ! node.removed && node.html.includes( 'mainwp-mintlify-assistant-message' ) ).length };
    }
    find( selector ) {
        if ( selector === 'br' ) { return { replaceWith: () => ( { end: () => this } ) }; }
        if ( selector === '.message-content' ) { return this; }
        if ( selector === '.mainwp-mintlify-user-message' ) {
            return { last: () => this.nodes.filter( node => ! node.removed && node.html.includes( 'mainwp-mintlify-user-message' ) ).at( -1 ) || new Element() };
        }
        assert.equal( selector, 'button' );
        const buttons = [];
        function walk( element ) {
            if ( element.html && element.html.includes( '<button' ) ) { buttons.push( element ); }
            ( element.nodes || [] ).forEach( walk );
        }
        walk( this );
        return {
            prop( key, value ) { buttons.forEach( button => button.prop( key, value ) ); return this; },
            first() { return buttons[ 0 ]; },
            buttons
        };
    }
}
const calls = [];
const context = {
    $: html => new Element( html ),
    $activeDocsHandoffOffer: null,
    $docsHandoffUserBubble: null,
    docsHandoffMessage: null,
    $messages: new Element(),
    $input: new Element(),
    isSending: false,
    messageHistory: [],
    availableProviderName: false,
    MainWPAssistantChatLogic: logic,
    l10n: ( key, fallback ) => fallback,
    connectPrompt: () => 'legacy',
    wizardReady: () => false,
    scrollToBottom() {},
    reset: () => calls.push( 'reset' ),
    applyModeChange: mode => {
        assert.equal( context.availableProviderName, null, 'Fresh offer must clear stale provider unavailability before switching mode' );
        calls.push( mode );
    },
    sendMessage: ( ...args ) => calls.push( args )
};
vm.createContext( context );
vm.runInContext( functionSource( 'clearDocsHandoffOffer' ) + '\n' + offer, context );
function render( available = true, message = 'Update plugins on my sites' ) {
    context.renderDocsHandoffOffer( message, { handoff_offer: true, agent_available: available } );
    return context.$activeDocsHandoffOffer.find( 'button' ).buttons;
}
let buttons = render();
assert.equal( buttons.length, 3 );
assert.equal( buttons[ 0 ].focused, true );
assert.ok( buttons.every( button => button.html.includes( 'type="button"' ) ) );
buttons[ 0 ].click();
assert.deepEqual( calls, [ 'reset', 'agent' ] );
assert.equal( context.$input.value, 'Update plugins on my sites' );
assert.equal( context.$input.triggered, 'input' );
assert.equal( context.$input.focused, true );
assert.ok( buttons.every( button => button.attributes.disabled === true ) );
buttons[ 1 ].click();
assert.equal( calls.length, 2, 'Consumed offer cannot send' );
buttons = render();
buttons[ 1 ].click();
buttons[ 1 ].click();
assert.equal( calls.length, 3, 'Double click sends only once' );
assert.equal( JSON.stringify( calls[ 2 ] ), JSON.stringify( [ 'Update plugins on my sites', false, 'ask', { docsHandoffBypass: true } ] ) );
buttons = render();
context.clearDocsHandoffOffer();
buttons[ 0 ].click();
buttons[ 1 ].click();
buttons[ 2 ].click();
assert.equal( calls.length, 3, 'Stale handlers cannot send or change mode' );
const previousBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
const unsentBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
context.$messages.append( previousBubble ).append( unsentBubble );
const previousMessage = { role: 'assistant', content: 'Earlier answer' };
context.messageHistory = [ previousMessage, { role: 'user', content: 'Update plugins on my sites' } ];
context.$input = new Element();
buttons = render( false );
assert.deepEqual( buttons.map( button => button.label ), [ 'Ask Docs instead', 'Dismiss' ] );
assert.equal( buttons[ 0 ].focused, true );
buttons[ 1 ].click();
assert.equal( context.$activeDocsHandoffOffer, null );
assert.equal( calls.length, 3, 'Dismiss does not send or switch mode' );
assert.equal( unsentBubble.removed, true );
assert.equal( previousBubble.removed, undefined );
assert.deepEqual( context.messageHistory, [ previousMessage ] );
assert.equal( context.$input.value, 'Update plugins on my sites' );
assert.equal( context.$input.triggered, 'input' );
assert.equal( context.$input.focused, true );
context.$input.val( 'Edited draft' );
buttons[ 1 ].click();
assert.equal( previousBubble.removed, undefined, 'Repeated Dismiss must not remove earlier messages' );
assert.equal( context.$input.value, 'Edited draft', 'Repeated Dismiss must not replace the draft' );
for ( const history of [ [], [ { role: 'user', content: 'Different request' } ], [ { role: 'assistant', content: 'Update plugins on my sites' } ] ] ) {
    context.messageHistory = history.slice();
    context.$messages.append( new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' ) );
    buttons = render();
    context.isSending = true;
    buttons[ 2 ].click();
    assert.notEqual( context.$activeDocsHandoffOffer, null, 'Busy offers cannot be dismissed' );
    context.isSending = false;
    buttons[ 2 ].click();
    assert.deepEqual( context.messageHistory, history, 'Dismiss must preserve nonmatching history and support history opt-out' );
}
const keptBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
const keptMessage = { role: 'user', content: 'Update plugins on my sites' };
const existingBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
context.$messages.append( existingBubble ).append( new Element( '<div class="mainwp-mintlify-error-message"></div>' ) );
context.messageHistory = [ previousMessage, keptMessage ];
context.$input = new Element();
// The retry reuses the failed send's bubble and history entry without appending either.
buttons = render();
buttons[ 2 ].click();
assert.equal( existingBubble.removed, true, 'Dismiss after retry must remove the unsent bubble from the failed send' );
assert.deepEqual( context.messageHistory, [ previousMessage ], 'Dismiss after retry must remove the unsent trailing history entry' );
assert.equal( context.$input.value, 'Update plugins on my sites' );
assert.equal( context.$input.triggered, 'input' );
assert.equal( context.$input.focused, true );
context.$messages.append( keptBubble );
context.messageHistory = [ keptMessage ];
buttons = render();
buttons[ 1 ].click();
context.clearDocsHandoffOffer();
assert.equal( keptBubble.removed, undefined, 'Ask Docs must retain the displayed user bubble through the next send' );
assert.deepEqual( context.messageHistory, [ keptMessage ], 'Ask Docs must retain the provisional history entry' );
for ( const name of [ 'sendMessage', 'applyModeChange' ] ) {
    const abandonedBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
    const laterBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
    context.$messages.append( abandonedBubble );
    context.messageHistory = [ previousMessage, keptMessage ];
    render();
    context.$messages.append( laterBubble );
    const opening = functionSource( name ).match( /^    function [^{]+\{\s*clearDocsHandoffOffer\(\);/ );
    assert.ok( opening, name + ' must clear the abandoned offer before proceeding' );
    vm.runInContext( opening[ 0 ] + '\n    }\n' + name + '();', context );
    assert.equal( abandonedBubble.removed, true, name + ' must remove the captured user bubble' );
    assert.equal( laterBubble.removed, undefined, name + ' must not remove a later user bubble' );
    assert.deepEqual( context.messageHistory, [ previousMessage ], name + ' must remove the matching trailing history entry' );
}
const multilineMessage = 'Back up\nexample.com';
const multilineBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( multilineMessage );
context.$messages.append( multilineBubble );
context.messageHistory = [ { role: 'user', content: multilineMessage } ];
buttons = render( true, multilineMessage );
buttons[ 2 ].click();
assert.equal( multilineBubble.removed, true );
assert.equal( context.messageHistory.length, 0 );
assert.equal( context.$input.value, multilineMessage );
for ( const laterMessage of [ 'msg3', 'msg2' ] ) {
    const u2 = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'msg2' );
    const u3 = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( laterMessage );
    context.$messages = new Element();
    context.$messages.append( u2 )
        .append( new Element( '<div class="mainwp-mintlify-error-message"></div>' ) )
        .append( u3 )
        .append( new Element( '<div class="mainwp-mintlify-assistant-message"></div>' ).text( 'ans3' ) );
    const history = [ { role: 'user', content: 'msg2' }, { role: 'user', content: laterMessage }, { role: 'assistant', content: 'ans3' } ];
    context.messageHistory = history.slice();
    buttons = render( true, 'msg2' );
    assert.equal( context.$docsHandoffUserBubble, null, 'An answered later bubble must not be provisional, even with identical text' );
    assert.equal( context.docsHandoffMessage, null );
    buttons[ 2 ].click();
    assert.equal( u2.removed, undefined );
    assert.equal( u3.removed, undefined );
    assert.deepEqual( context.messageHistory, history );
    assert.equal( context.$input.value, 'msg2' );
}
Object.assign( context, {
    lastUserMessage: 'Update example.com',
    buildErrorDeepLink: () => '',
    escapeHtml: text => text,
    isBatchRendering: false
} );
vm.runInContext( functionSource( 'appendErrorMessage' ), context );
for ( const bypass of [ true, false ] ) {
    context.requestDocsHandoffBypass = bypass;
    context.appendErrorMessage( 'Connection failed' );
    assert.ok( context.$messages.nodes.at( -1 ).html.includes( 'data-docs-bypass="' + ( bypass ? '1' : '0' ) + '"' ), 'Retry must retain the failed request bypass choice' );
}
// Connecting a plan from the offer's choices adds Continue in Sites to the same offer.
vm.runInContext( functionSource( 'refreshDocsHandoffOffer' ), context );
assert.match( source, /setAgentPillUnavailable\( false \);\s*refreshDocsHandoffOffer\(\);/, 'A confirmed provider must refresh a waiting offer' );
const waitingBubble = new Element( '<div class="mainwp-mintlify-user-message"></div>' ).text( 'Update plugins on my sites' );
context.$messages = new Element();
context.$messages.append( waitingBubble );
context.messageHistory = [ { role: 'user', content: 'Update plugins on my sites' } ];
context.$input = new Element();
buttons = render( false );
assert.deepEqual( buttons.map( button => button.label ), [ 'Ask Docs instead', 'Dismiss' ] );
const waitingOffer = context.$activeDocsHandoffOffer;
context.refreshDocsHandoffOffer();
assert.notEqual( context.$activeDocsHandoffOffer, waitingOffer );
assert.equal( waitingOffer.removed, true );
buttons = context.$activeDocsHandoffOffer.find( 'button' ).buttons;
assert.deepEqual( buttons.map( button => button.label ), [ 'Continue in Sites', 'Ask Docs instead', 'Dismiss' ] );
assert.equal( waitingBubble.removed, undefined, 'The refresh keeps the request bubble' );
assert.deepEqual( context.messageHistory, [ { role: 'user', content: 'Update plugins on my sites' } ] );
assert.equal( context.$docsHandoffUserBubble, waitingBubble, 'Dismiss still removes the request after the refresh' );
const continuingOffer = context.$activeDocsHandoffOffer;
context.refreshDocsHandoffOffer();
assert.equal( context.$activeDocsHandoffOffer, continuingOffer, 'An offer that can continue is left alone' );
context.clearDocsHandoffOffer();
context.refreshDocsHandoffOffer();
assert.equal( context.$activeDocsHandoffOffer, null, 'A cleared offer is not brought back' );
console.log( 'PASS Docs handoff: decision, routing, fresh Sites composer, bypass, focus, stale and double clicks, refresh after connecting' );
