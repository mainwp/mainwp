'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const { execFileSync } = require( 'node:child_process' );
const readSource = file => process.env.ASSISTANT_BASE_REF ? execFileSync( 'git', [ 'show', process.env.ASSISTANT_BASE_REF + ':' + file ], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 } ) : fs.readFileSync( file, 'utf8' );
const chat = readSource( 'assets/js/mainwp-mintlify-chat.js' );
const panel = readSource( 'assets/js/mainwp-assistant-panel.js' );
function fn( source, name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    assert.ok( start >= 0, name );
    return source.slice( start, source.indexOf( '\n    }', start ) + 6 );
}
function harness( preference = 'agent' ) {
    const requests = [], bubbles = [], timers = [];
    const node = new Proxy( { length: 0, prop: () => false }, { get: ( target, key ) => key in target ? target[key] : () => node } );
    const $ = value => { if ( typeof value === 'string' && value.startsWith( '<div class="mainwp-mintlify-message' ) ) { bubbles.push( value ); } return node; };
    function request( options ) {
        const callbacks = { options };
        const chain = { done( callback ) { callbacks.done = callback; return chain; }, fail( callback ) { callbacks.fail = callback; return chain; }, always() { return chain; } };
        requests.push( callbacks );
        return chain;
    }
    $.ajax = request;
    $.post = ( url, data ) => request( { data } );
    const context = {
        $, jQuery: $, $messages: node, $input: node, ajaxurl: '/ajax', mainwp_secure_data: value => value,
        localStorage: { getItem: key => key === 'mainwp_assistant_mode_preference' ? preference : null, removeItem() {} },
        currentMode: 'agent', requestMode: 'agent', userModeOverride: null, currentProvider: null,
        availableProviderName: null, currentConversationId: null, isHistoryDisabled: false, isBatchRendering: false,
        messageHistory: [], sessionFingerprint: 'fixture', lastUserMessage: null, requestDocsHandoffBypass: false,
        document: { dispatchEvent() {} }, window: {}, CustomEvent: function() {}, AbortController,
        setTimeout: callback => timers.push( callback ), clearTimeout() {}, requestAnimationFrame: callback => callback(),
        l10n: ( key, fallback ) => fallback, escapeHtml: value => value, parseMarkdown: value => value,
        formatTimestamp: () => '', normalizeUsageInfo: () => null, renderExecutedAbilityChips: () => '',
        renderSources: () => 'citations', parseUsageFromResponse: () => null, normalizeStoredSources: value => value || [],
        buildErrorDeepLink: () => '',
    };
    for ( const name of [ 'clearDocsHandoffOffer', 'setInputState', 'resolveActiveConfirmCard', 'resetSessionUsage', 'appendUserMessage', 'appendSystemMessage', 'showRegenerateLink', 'hideRegenerateLinks', 'scrollToBottom', 'setCurrentConversation', 'setAgentPillUnavailable', 'maybeRenderWelcomeConnectCard', 'updateBudgetNotice', 'restoreHistoryControls', 'attemptConversationRestore', 'handleHistoryDisabled', 'updateInfoBar', 'attachAnswerFeedback', 'setSendButtonStop', 'startThinkingIndicator' ] ) { context[name] = () => {}; }
    context.syncPillToggle = mode => { context.pill = mode; };
    vm.createContext( context );
    for ( const name of [ 'updateModeIndicator', 'applyInitialMode', 'checkProviderAvailability', 'loadConversation', 'appendAssistantMessage', 'appendErrorMessage', 'sendToAPI' ] ) { if ( chat.includes( 'function ' + name + '(' ) ) { vm.runInContext( fn( chat, name ), context ); } }
    return { context, requests, bubbles, timers };
}
test( 'loaded and selected modes survive the production availability callback matrix', () => {
    for ( const result of [ 'available', 'unavailable', 'unsuccessful', 'network' ] ) {
    for ( const order of [ 'load-first', 'check-first' ] ) {
        {
            const { context: c, requests, timers } = harness();
            c.checkProviderAvailability();
            c.loadConversation( 12 );
            const load = () => requests[1].done( { success: true, data: { mode: 'ask', messages: [] } } );
            const check = () => {
                if ( result === 'network' ) {
                    requests[0].fail( {}, 'error' );
                    while ( timers.length ) { timers.shift()(); requests.at( -1 ).fail( {}, 'error' ); }
                } else { requests[0].done( result === 'unsuccessful' ? { success: false } : { success: true, data: { available: result === 'available', provider_name: 'Google' } } ); }
            };
            if ( order === 'load-first' ) { load(); check(); } else { check(); load(); }
            assert.equal( c.currentMode, 'ask' );
            assert.equal( c.pill, 'ask' );
            assert.equal( c.requestMode, 'ask' );
            c.sendToAPI( 'Question', false );
            assert.equal( requests.at( -1 ).options.data.mode, 'ask' );
        }
    }
}
{
    const { context: c, requests } = harness();
    c.currentMode = c.requestMode = 'ask';
    c.checkProviderAvailability();
    requests[0].done( { success: true, data: { available: true, provider_name: 'Google' } } );
    assert.equal( c.currentMode, 'agent' );
    assert.equal( c.pill, 'agent' );
    assert.equal( c.requestMode, 'agent' );
    c.sendToAPI( 'Question', false );
    assert.equal( requests.at( -1 ).options.data.mode, 'agent' );
}
{
    const { context: c, requests } = harness();
    c.userModeOverride = 'agent';
    c.checkProviderAvailability();
    requests[0].done( { success: true, data: { available: false } } );
    assert.equal( c.currentMode, 'ask' );
    assert.equal( c.pill, 'ask' );
    assert.equal( c.requestMode, 'ask' );
    c.sendToAPI( 'Question', false );
    assert.equal( requests.at( -1 ).options.data.mode, 'ask' );
}
{
    const { context: c, requests } = harness();
    c.checkProviderAvailability();
    c.loadConversation( 12 );
    requests[1].done( { success: true, data: { mode: 'agent', messages: [] } } );
    requests[0].done( { success: true, data: { available: false } } );
    assert.equal( c.pill, 'ask' );
    assert.equal( c.requestMode, 'ask' );
    c.sendToAPI( 'Question', false );
    assert.equal( requests.at( -1 ).options.data.mode, 'ask' );
}
{
    const { context: c, requests } = harness();
    c.checkProviderAvailability();
    c.loadConversation( 12 );
    requests[0].done( { success: true, data: { available: false } } );
    requests[1].done( { success: true, data: { mode: 'agent', provider_name: 'Google', messages: [] } } );
    assert.equal( c.availableProviderName, false );
    assert.equal( c.pill, 'ask' );
    assert.equal( c.requestMode, 'ask' );
    c.sendToAPI( 'Question', false );
    assert.equal( requests.at( -1 ).options.data.mode, 'ask' );
}
    for ( const order of [ 'load-first', 'check-first' ] ) {
        const { context: c, requests } = harness( 'ask' );
        c.checkProviderAvailability();
        c.loadConversation( 12 );
        const load = () => requests[1].done( { success: true, data: { mode: 'agent', provider_name: 'Google', messages: [] } } );
        const check = () => requests[0].done( { success: true, data: { available: true, provider_name: 'Google' } } );
        if ( order === 'load-first' ) { load(); check(); } else { check(); load(); }
        assert.equal( c.currentMode, 'agent' );
        assert.equal( c.pill, 'agent' );
        assert.equal( c.requestMode, 'agent' );
        assert.equal( c.userModeOverride, 'agent' );
        c.sendToAPI( 'Question', false );
        assert.equal( requests.at( -1 ).options.data.mode, 'agent' );
    }
} );
test( 'replayed badges follow stored routes and the legacy conversation fallback', () => {
    for ( const [ route, sources, mode, expected ] of [ [ 'agent', [ {} ], 'ask', 'Sites' ], [ 'ask', [], 'agent', 'Docs' ], [ undefined, [], 'agent', 'Sites' ] ] ) {
    {
        const { context: c, requests, bubbles } = harness();
        c.currentMode = mode;
        c.loadConversation( 12, { silent: true } );
        requests[0].done( { success: true, data: { mode: 'agent', provider_name: 'Google', messages: [ { role: 'assistant', content: 'Answer', route, sources } ] } } );
        assert.match( bubbles[0], new RegExp( 'mainwp-assistant-mode-label">' + expected ) );
    }
}
} );
test( 'error bubble has no inline icon', () => {
    const { context: c, bubbles } = harness();
    c.appendErrorMessage( 'Failed' );
    assert.doesNotMatch( bubbles[0], /<i\b[^>]*class="[^"]*icon/ );
} );
test( 'keyboard guards ignore hidden dimmers and respect visible modals', () => {
    for ( const visible of [ false, true ] ) {
    {
        let handler, closed = false;
        const selection = { length: 1, filter: () => ( { length: visible ? 1 : 0 } ) };
        const context = { state: { isOpen: true, panelState: 'normal' }, document: {}, MainWPAssistantPanel: { close: () => { closed = true; } }, $: value => value === context.document ? { on: ( event, callback ) => { handler = callback; } } : selection };
        const start = panel.indexOf( "        $( document ).on( 'keydown.assistantPanel'" );
        assert.ok( start >= 0, 'keydown handler anchor not found' );
        vm.runInNewContext( panel.slice( start, panel.indexOf( '\n        } );', start ) + 13 ), context );
        handler( { key: 'Escape' } );
        assert.equal( closed, ! visible );
        let trapped = false;
        const first = { 0: {}, focus() { trapped = true; } };
        const last = { 0: {} };
        context.document.activeElement = last[0];
        context.getFocusableElements = () => ( { length: 2, first: () => first, last: () => last } );
        vm.runInNewContext( fn( panel, 'handleFocusTrap' ), context );
        context.handleFocusTrap( { key: 'Tab', preventDefault() {} } );
        assert.equal( trapped, ! visible );
    }
}
} );
