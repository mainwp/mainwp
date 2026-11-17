'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const panel = fs.readFileSync( 'assets/js/mainwp-assistant-panel.js', 'utf8' );
const chat = fs.readFileSync( 'assets/js/mainwp-mintlify-chat.js', 'utf8' );
const ui = fs.readFileSync( 'class/class-mainwp-ui.php', 'utf8' );
const system = fs.readFileSync( 'class/class-mainwp-system.php', 'utf8' );
function functionSource( name ) {
    const start = panel.indexOf( '    function ' + name + '(' );
    assert.ok( start > 0, name + ' must exist' );
    const end = panel.indexOf( '\n    }', start ) + 6;
    return panel.slice( start, end );
}
const menu = ui.slice( ui.indexOf( '<nav class="mainwp-assistant-tab-bar"' ), ui.indexOf( '</nav>', ui.indexOf( '<nav class="mainwp-assistant-tab-bar"' ) ) );
assert.match( menu, /data-screen="support"/ );
assert.match( menu, /\$support_copy\['menu'\]/ );
assert.match( panel, /screen === 'support'[\s\S]*?MainWPAssistantPanel.openSupport\(\)/ );
assert.match( panel, /screenName === 'support'[\s\S]*?mainwp-assistant-support-screen/ );
assert.match( panel, /screenName === 'support'[\s\S]*?titleText = supportText\( 'menu' \)/ );
assert.doesNotMatch( panel + system, /supportText\( 'title' \)|'title'\s*=> __\( 'Contact MainWP Support'/ );
assert.match( panel, /openSupport: function\(\)[\s\S]*?requireConsent\( 'support'/ );
assert.match( system, /'support_email'\s*=> wp_get_current_user\(\)->user_email/ );

const escape = value => String( value ).replace( /[&<>"']/g, ch => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' } )[ ch ] );
class Element {
    constructor( html = '' ) { this.html = html; this.children = []; this.attributes = {}; this.handlers = {}; this.value = ''; this.content = ''; }
    append( ...children ) { this.children.push( ...children ); return this; }
    empty() { this.children = []; this.content = ''; return this; }
    text( value ) { if ( value === undefined ) { return this.content; } this.content = value; return this; }
    val( value ) { if ( value === undefined ) { return this.value; } this.value = value; return this; }
    attr( key, value ) { if ( value === undefined ) { return this.attributes[ key ]; } this.attributes[ key ] = value; return this; }
    prop( key, value ) { return this.attr( key, value ); }
    addClass() { return this; }
    removeClass() { return this; }
    on( event, callback ) { this.handlers[ event ] = callback; return this; }
    trigger( event ) { if ( this.handlers[ event ] ) { this.handlers[ event ]( { preventDefault() {} } ); } return this; }
    rendered() { return escape( this.content ) + this.children.map( child => child.rendered() ).join( '' ); }
}
const screen = new Element();
const requests = [];
function $( value ) {
    if ( value === '#mainwp-assistant-support-screen' ) { return screen; }
    assert.ok( value.startsWith( '<' ), value );
    return new Element( value );
}
$.ajax = options => {
    const callbacks = {};
    requests.push( { options, callbacks } );
    const chain = {};
    for ( const name of [ 'done', 'fail', 'always' ] ) {
        chain[ name ] = callback => { callbacks[ name ] = callback; return chain; };
    }
    return chain;
};
const copy = {
    menu: 'Contact support', email: 'Email', note: 'Your note', includes: 'We will send:',
    transcript: 'Questions and answers', history_off: 'History is off', versions: 'Dashboard, WordPress and PHP versions',
    send: 'Send', cancel: 'Cancel', success: 'Thanks. MainWP Support will reply by email to %s.',
    failure: "We couldn't send this. You can reach MainWP Support at", contact_label: 'mainwp.com/contact', note_required: 'Please add a note'
};
const maliciousEmail = '"><img src=x onerror=alert(1)>@example.org';
let conversation = { conversationId: 42, note: 'Latest <question>', historyDisabled: false };
const navigation = [];
const context = {
    $, ajaxurl: '/admin-ajax.php', document: { createTextNode: text => ( { rendered: () => escape( text ) } ) },
    mainwpMintlifyChatL10n: { support: copy, support_email: maliciousEmail },
    MainWPMintlifyChat: { getSupportContext: () => conversation },
    MainWPAssistantPanel: { showScreen: ( ...args ) => navigation.push( args ) },
    supportPending: false, supportReturnScreen: 'chat',
    mainwp_secure_data: data => ( { ...data, security: 'nonce' } )
};
vm.createContext( context );
vm.runInContext( [ 'supportText', 'renderSupportResult', 'renderSupportForm' ].map( functionSource ).join( '\n' ), context );
function walk( node, pattern ) {
    if ( pattern.test( node.html || '' ) ) { return node; }
    for ( const child of node.children || [] ) { const result = walk( child, pattern ); if ( result ) { return result; } }
}
function form() {
    context.renderSupportForm();
    return { form: walk( screen, /<form/ ), email: walk( screen, /id="mainwp-support-email"/ ), note: walk( screen, /<textarea/ ), send: walk( screen, /type="submit"/ ), cancel: walk( screen, /type="button"/ ) };
}
let fields = form();
assert.equal( fields.email.val(), maliciousEmail );
assert.equal( walk( screen, /<h3/ ), undefined, 'The panel header already names the screen' );
assert.ok( walk( walk( screen, /class="ui fluid input"/ ), /id="mainwp-support-email"/ ), 'Themes colour the email field through its .ui.input wrapper' );
assert.equal( fields.note.val(), 'Latest <question>' );
assert.equal( fields.note.prop( 'required' ), false );
assert.match( screen.rendered(), /Questions and answers/ );
fields.form.trigger( 'submit' );
assert.equal( fields.send.prop( 'disabled' ), true, 'Send must disable before transport completion' );
fields.form.trigger( 'submit' );
assert.equal( requests.length, 1, 'Pending submissions must not overlap' );
assert.deepEqual( requests[ 0 ].options.data, { action: 'mainwp_assistant_escalate', email: maliciousEmail, note: 'Latest <question>', conversation_id: 42, security: 'nonce' } );
assert.equal( requests[ 0 ].options.timeout, 25000 );
requests[ 0 ].callbacks.done( { success: true, data: { email: '<script>untrusted response</script>' } } );
requests[ 0 ].callbacks.always();
assert.equal( fields.send.prop( 'disabled' ), false );
assert.ok( screen.rendered().includes( escape( maliciousEmail ) ), 'Confirmation must escape the submitted email' );
assert.doesNotMatch( screen.rendered(), /<img|<script/ );
assert.equal( walk( screen, /<form/ ), undefined, 'Success must replace the form' );

for ( const response of [ null, { success: false }, { success: 'true' } ] ) {
    fields = form();
    fields.form.trigger( 'submit' );
    const request = requests.at( -1 );
    request.callbacks.done( response );
    request.callbacks.always();
    assert.match( screen.rendered(), /couldn&#039;t send/ );
    assert.equal( walk( screen, /<a/ ).attr( 'href' ), 'https://mainwp.com/contact/' );
    assert.doesNotMatch( screen.rendered(), /<img|<script/ );
}
fields = form();
fields.form.trigger( 'submit' );
requests.at( -1 ).callbacks.fail();
requests.at( -1 ).callbacks.always();
assert.match( screen.rendered(), /mainwp.com\/contact/ );
assert.doesNotMatch( screen.rendered(), /<img|<script/ );

conversation = { conversationId: null, note: 'Last unsaved question', historyDisabled: true };
fields = form();
assert.equal( fields.note.prop( 'required' ), true );
assert.equal( fields.note.val(), 'Last unsaved question' );
assert.doesNotMatch( screen.rendered(), /Questions and answers/ );
assert.match( screen.rendered(), /History is off/ );
fields.note.val( '  ' );
const before = requests.length;
fields.form.trigger( 'submit' );
assert.equal( requests.length, before );
assert.match( screen.rendered(), /Please add a note/ );
fields.note.val( 'Help' );
fields.form.trigger( 'submit' );
assert.equal( requests.at( -1 ).options.data.conversation_id, 0 );
assert.equal( Object.hasOwn( requests.at( -1 ).options.data, 'transcript' ), false );
requests.at( -1 ).callbacks.done( { success: true } );
requests.at( -1 ).callbacks.always();
fields = form();
fields.cancel.trigger( 'click' );
assert.deepEqual( navigation, [ [ 'chat', false ] ] );
conversation = { conversationId: null, note: '', historyDisabled: false };
fields = form();
assert.equal( fields.note.prop( 'required' ), true, 'A new conversation also needs a note' );
assert.doesNotMatch( screen.rendered(), /Questions and answers|History is off/ );

const getter = chat.match( /getSupportContext: (function\(\) \{[\s\S]*?\n        \})/ );
assert.ok( getter, 'Chat must expose its current conversation and last question' );
const chatContext = { isHistoryDisabled: false, currentConversationId: 99, lastUserMessage: 'Most recent question' };
vm.createContext( chatContext );
vm.runInContext( 'var getContext = ' + getter[ 1 ], chatContext );
assert.equal( chatContext.getContext().conversationId, 99 );
assert.equal( chatContext.getContext().note, 'Most recent question' );
chatContext.isHistoryDisabled = true;
assert.equal( chatContext.getContext().conversationId, null );
assert.equal( chatContext.getContext().note, 'Most recent question', 'Opt-out must preserve the last question for support' );
console.log( 'PASS support escalation: menu, prefill, history disclosure, pending guard, exact request, escaped results, fallback, cancel' );
assert.match( panel, /screenName === 'video' \|\| screenName === 'support' \? 'home'/, 'The support screen must not be restored empty after a page load' );
