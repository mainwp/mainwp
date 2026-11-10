'use strict';

// Execute the shipped handler with a small jQuery transport/element fixture.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( 'assets/js/mainwp-mintlify-chat.js', 'utf8' );
const start = source.indexOf( '    function attachAnswerFeedback(' );
const end = source.indexOf( '    /**', start );
assert.ok( start > 0 && end > start );

class Element {
    constructor() { this.attributes = {}; this.children = []; this.content = ''; }
    attr( key, value ) {
        if ( typeof key === 'object' ) { Object.assign( this.attributes, key ); return this; }
        if ( value === undefined ) { return this.attributes[ key ]; }
        this.attributes[ key ] = value;
        return this;
    }
    prop( key, value ) { return this.attr( key, value ); }
    on( event, callback ) { this[ event ] = callback; return this; }
    text( value ) { this.content = value; return this; }
    toggle( visible ) { this.visible = visible; return this; }
    append( child ) { this.children.push( child ); return this; }
    find( selector ) {
        if ( selector === 'a' ) { return this.link || ( this.link = new Element() ); }
        if ( selector === '.mainwp-assistant-cost-line' ) { return this; }
        assert.equal( selector, 'button' );
        const buttons = this.children.filter( child => child.click );
        return {
            prop( key, value ) { buttons.forEach( button => button.prop( key, value ) ); },
            each( callback ) { buttons.forEach( button => callback.call( button ) ); }
        };
    }
}
const calls = [];
function $( value ) { return value instanceof Element ? value : new Element(); }
$.ajax = options => {
    const callbacks = {};
    const request = { options, callbacks };
    calls.push( request );
    const chain = {};
    [ 'done', 'fail', 'always' ].forEach( name => { chain[ name ] = callback => { callbacks[ name ] = callback; return chain; }; } );
    return chain;
};
const context = { mainwpMintlifyChatL10n: { support: { link: 'Still stuck? Contact MainWP Support' } }, MainWPAssistantPanel: { openSupport() { context.supportOpened = true; } }, $, ajaxurl: '/admin-ajax.php', l10n: ( key, fallback ) => fallback, mainwp_secure_data: data => ( { ...data, security: 'fixture-nonce' } ) };
vm.createContext( context );
vm.runInContext( source.slice( start, end ), context );
function render( feedback ) {
    const bubble = new Element();
    context.attachAnswerFeedback( bubble, feedback );
    return bubble;
}
function complete( rating, failure = false ) {
    const { callbacks } = calls.at( -1 );
    if ( failure ) { callbacks.fail(); } else { callbacks.done( { success: true, data: { rating } } ); }
    callbacks.always();
}

for ( const invalid of [ undefined, null, {}, { message_id: '73', conversation_id: 42 }, { message_id: 73, conversation_id: -1 } ] ) {
    assert.equal( render( invalid ).children.length, 0, 'Unavailable answers must have no controls' );
}
const bubble = render( { conversation_id: 42, message_id: 73, rating: null } );
const [ up, down, status ] = bubble.children[ 0 ].children;
assert.equal( bubble.children[ 1 ].visible, false, 'No support link before a thumbs-down' );
assert.equal( up.attr( 'aria-pressed' ), 'false' );
up.click();
assert.equal( up.attr( 'disabled' ), true );
down.click();
assert.equal( calls.length, 1, 'Pending requests must not overlap' );
assert.deepEqual( calls[ 0 ].options.data, { action: 'mainwp_assistant_feedback', conversation_id: 42, message_id: 73, rating: 'positive', security: 'fixture-nonce' } );
complete( 'positive' );
assert.equal( up.attr( 'aria-pressed' ), 'true' );
assert.equal( up.attr( 'disabled' ), false );
assert.match( status.content, /saved/ );
assert.equal( bubble.children[ 1 ].visible, false, 'Thumbs-up must not show support' );
down.click();
complete( 'negative' );
assert.equal( down.attr( 'aria-pressed' ), 'true' );
assert.equal( bubble.children[ 1 ].visible, true, 'Thumbs-down must offer support' );
bubble.children[ 1 ].link.click( { preventDefault() {} } );
assert.equal( context.supportOpened, true );
assert.equal( up.attr( 'aria-pressed' ), 'false' );
up.click();
complete( 'positive', true );
assert.equal( down.attr( 'aria-pressed' ), 'true', 'Transport failure must preserve the previous rating' );
assert.match( status.content, /Could not save/ );
up.click();
complete( 'negative' );
assert.equal( down.attr( 'aria-pressed' ), 'true', 'Mismatched acknowledgement must preserve the previous rating' );
assert.match( status.content, /Could not save/ );
down.click();
assert.equal( calls.at( -1 ).options.data.rating, '' );
complete( null );
assert.equal( down.attr( 'aria-pressed' ), 'false' );
assert.match( status.content, /removed/ );
assert.equal( bubble.children[ 1 ].visible, false, 'Clearing thumbs-down must hide support' );
up.click();
complete( 'positive' );
assert.equal( bubble.children[ 1 ].visible, false, 'Changing to thumbs-up must hide support' );
const negative = render( { conversation_id: 42, message_id: 73, rating: 'negative' } );
assert.equal( negative.children[ 1 ].visible, true, 'Restored thumbs-down must offer support' );
negative.children[ 0 ].children[ 0 ].click();
complete( 'positive' );
assert.equal( negative.children[ 1 ].visible, false, 'A successful thumbs-up must remove an existing support link' );
const restored = render( { conversation_id: 99, message_id: 88, rating: 'positive' } );
assert.equal( restored.children[ 0 ].children[ 0 ].attr( 'aria-pressed' ), 'true' );
restored.children[ 0 ].children[ 0 ].click();
assert.equal( calls.at( -1 ).options.data.conversation_id, 99, 'Use the answer conversation, not the currently selected thread' );
assert.equal( calls.at( -1 ).options.data.message_id, 88 );
complete( null );
assert.ok( source.includes( 'response.data.truncated_fields, response.data.feedback );' ), 'New answers must pass feedback metadata to the renderer' );
assert.ok( source.includes( 'msg.created_at, false, undefined, msg.feedback, msg.route === \'agent\' || msg.route === \'ask\' ? msg.route : response.data.mode );' ), 'History must pass feedback metadata to the renderer' );
const recoveryCall = source.match( /appendAssistantMessage\( answer\.content[^\n]+/ );
assert.ok( recoveryCall, 'Transport recovery must render the stored answer' );
const answer = {
    content: 'Recovered Docs answer', sources: [], executed_abilities: [], created_at: '2026-09-26 12:00:00',
    truncated: true, truncated_fields: [ 'content' ], feedback: { conversation_id: 99, message_id: 88, rating: 'positive' }
};
let recoveredArgs;
vm.runInNewContext( recoveryCall[ 0 ], {
    answer, normalizeStoredSources: sources => sources,
    appendAssistantMessage: ( ...args ) => { recoveredArgs = args; }
} );
assert.equal( recoveredArgs[ 4 ], answer.created_at );
assert.equal( recoveredArgs[ 5 ], true );
assert.equal( recoveredArgs[ 6 ], answer.truncated_fields );
assert.equal( recoveredArgs[ 7 ], answer.feedback );
const recovered = render( recoveredArgs[ 7 ] );
assert.equal( recovered.children[ 0 ].children[ 0 ].attr( 'aria-pressed' ), 'true', 'Recovered answers must restore feedback controls' );
console.log( 'PASS answer feedback: unavailable answers, exact target, positive/negative/clear, pending guard, error recovery, history restoration' );
