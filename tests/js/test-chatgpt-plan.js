'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const wizard = fs.readFileSync( 'assets/js/mainwp-assistant-onboarding.js', 'utf8' );
const shared = wizard.slice( wizard.lastIndexOf( '( function( $, root ) {' ) );
const chat = fs.readFileSync( 'assets/js/mainwp-mintlify-chat.js', 'utf8' );
const php = fs.readFileSync( 'class/class-mainwp-system.php', 'utf8' );

function fn( source, name ) {
    const start = source.indexOf( '    function ' + name + '(' );
    assert.ok( start >= 0, name );
    return source.slice( start, source.indexOf( '\n    }', start ) + 6 );
}

class Element {
    constructor( html = '' ) {
        this.markup = html; this.nodes = []; this.attributes = {}; this.handlers = {}; this.length = 1; this.content = '';
    }
    append( ...nodes ) { this.nodes.push( ...nodes ); return this; }
    empty() { this.nodes = []; this.content = ''; return this; }
    text( value ) { if ( value === undefined ) { return this.content; } this.content = String( value ); return this; }
    attr( key, value ) { if ( value === undefined ) { return this.attributes[key]; } this.attributes[key] = value; return this; }
    prop( key, value ) { return arguments.length === 1 ? this.attr( key ) : this.attr( key, value ); }
    data( key, value ) { return arguments.length === 1 ? this.attr( key ) : this.attr( key, value ); }
    val( value ) { if ( value === undefined ) { return this.value; } this.value = value; return this; }
    on( event, callback ) { this.handlers[event] = callback; return this; }
    click() { this.handlers.click( { preventDefault() {} } ); }
    appendTo() { return this; }
    modal( value ) { this.modalState = value; return this; }
    dropdown() { this.dropdownInitialized = true; return this; }
    remove() { this.removed = true; return this; }
    trigger() { return this; }
    toggleClass( name, on ) { this.classes ||= {}; this.classes[name] = on; return this; }
    find( selector ) {
        this.fields ||= new Map();
        if ( ! this.fields.has( selector ) ) { this.fields.set( selector, new Element() ); }
        return this.fields.get( selector );
    }
    walk() { return [ this, ...this.nodes.flatMap( node => node.walk() ) ]; }
    html() { throw Error( 'Server text must not be rendered as HTML' ); }
}

function harness( overrides = {}, state = {} ) {
    const calls = [], requests = [], created = [], roots = new Map();
    const strings = {};
    for ( const match of php.matchAll( /'([a-z_]+)'\s*=> __\( (['"])(.*?)\2, 'mainwp' \)/g ) ) { strings[match[1]] = match[3]; }
    const config = { can_connect: true, can_manage_settings: true, connectors_url: '/connectors', strings, errors: strings, ...overrides };
    const $ = value => {
        if ( typeof value === 'function' ) { return; }
        if ( value instanceof Element ) { return value; }
        if ( typeof value === 'string' && value.startsWith( '<' ) ) { const element = new Element( value ); created.push( element ); return element; }
        if ( roots.has( value ) ) { return roots.get( value ); }
        const empty = new Element(); empty.length = 0; return empty;
    };
    $.extend = Object.assign;
    $.ajax = options => {
        const callbacks = {}, chain = {};
        for ( const name of [ 'done', 'fail', 'always' ] ) { chain[name] = cb => { callbacks[name] = cb; return chain; }; }
        requests.push( { options, callbacks } );
        return chain;
    };
    const root = {
        mainwpAssistantSettings: { chatgpt: config }, ajaxurl: '/ajax',
        mainwp_secure_data: data => ( { ...data, security: data.action + '-nonce' } ),
        MainWPAssistantOnboarding: { isAvailable: () => true, open: ( ...args ) => calls.push( args ) },
        MainWPAssistantChatGPT: { open: fresh => calls.push( fresh ), openChoices: source => calls.push( source ) }
    };
    const c = {
        $, root, status: state, document: {}, URL,
        mainwpMintlifyChatL10n: { chatgpt: config }, window: root,
        chatgptPlan: state, providerReasonCode: 'no_provider', wizardAvailable: false,
        wizardReady: () => false,
        l10n: ( key, fallback ) => fallback,
        escapeHtml: value => String( value ).replace( /[<>&"]/g, '' ),
        busy: false, pageTitle: null, MODAL: '#modal'
    };
    vm.createContext( c );
    for ( const name of [ 'config', 'text', 'errorText', 'request', 'button', 'connectButton', 'manageLink', 'choices', 'signinSteps', 'copyKeys', 'accountLine' ] ) { vm.runInContext( fn( shared, name ), c ); }
    for ( const name of [ 'connectionChoicesReady', 'planText', 'planCanConnect', 'buildErrorDeepLink', 'connectPrompt', 'buildConnectCard' ] ) { vm.runInContext( fn( chat, name ), c ); }
    c.openConnect = fresh => calls.push( fresh );
    return { c, config, calls, requests, roots, created };
}

function contents( element ) { return element.walk().map( node => node.content ).filter( Boolean ); }

test( 'production connect choices keep the required order and API routes', () => {
    const { c, calls, config } = harness();
    const choices = c.choices( 'welcome', true );
    assert.deepEqual( choices.nodes.map( node => node.nodes[0].content ), [ 'Use your ChatGPT plan', 'Use an API key' ] );
    assert.ok( ! contents( choices ).some( value => /Claude/.test( value ) ) );
    assert.ok( contents( choices ).includes( 'Uses the ChatGPT plan you already pay for. Each person on this Dashboard connects their own.' ) );
    const all = choices.walk();
    const signIn = all.find( node => node.content === 'Continue with ChatGPT' );
    signIn.click();
    assert.deepEqual( calls, [ false ] );
    assert.equal( signIn.attr( 'data-oai-siwc-button' ), '' );
    assert.equal( all.find( node => node.content === 'Use OpenAI or Anthropic instead' ).attr( 'href' ), config.connectors_url );
    c.$ = Object.assign( value => value === '#mainwp-assistant-connection-modal' ? { modal() {} } : new Element( value ), c.$ );
    all.find( node => node.content === 'Connect free AI' ).click();
    assert.deepEqual( calls[1], [ 'welcome', true ] );
} );

test( 'the panel uses the shared choices and does not require settings rights', () => {
    const { c } = harness( { can_manage_settings: false } );
    assert.equal( c.connectPrompt(), 'card' );
    c.providerReasonCode = 'chatgpt_plan_reconnect';
    assert.equal( c.connectPrompt(), 'card' );
    const choices = c.choices( 'welcome', true );
    assert.equal( choices.nodes.length, 1 );
    assert.ok( ! contents( choices ).includes( 'Use an API key' ) );
    assert.ok( contents( choices ).includes( 'Continue with ChatGPT' ) );
    assert.ok( ! contents( choices ).includes( 'Connect free AI' ) );
    assert.ok( ! contents( choices ).includes( 'Use OpenAI or Anthropic instead' ) );
    c.window.MainWPAssistantChatGPT.choices = c.choices;
    const card = c.buildConnectCard( 'welcome', false );
    assert.deepEqual( contents( card ), contents( choices ) );
} );

test( 'AI-disabled dashboards never offer a ChatGPT sign-in', () => {
    const { c } = harness( { can_connect: false } );
    assert.ok( ! contents( c.choices( 'settings', false ) ).includes( 'Continue with ChatGPT' ) );
    assert.equal( c.buildErrorDeepLink( 'chatgpt_plan_reconnect' ), '' );
} );

test( 'the plan indicator requires a connected plan, AI support and Sites mode', () => {
    const { c, config, roots } = harness( { can_connect: false }, { connected: true } );
    const indicator = new Element(); roots.set( '#mainwp-assistant-plan-indicator', indicator );
    c.currentMode = 'agent';
    vm.runInContext( fn( chat, 'updatePlanIndicator' ), c );
    c.updatePlanIndicator();
    assert.equal( indicator.prop( 'hidden' ), true );
    assert.deepEqual( contents( indicator ), [] );
    config.can_connect = true;
    c.updatePlanIndicator();
    assert.equal( indicator.prop( 'hidden' ), false );
    assert.ok( contents( indicator ).includes( 'Using ChatGPT plan' ) );
    c.currentMode = 'ask';
    c.updatePlanIndicator();
    assert.equal( indicator.prop( 'hidden' ), true, 'Docs answers never use the plan.' );
    c.currentMode = 'agent';
    c.chatgptPlan.connected = false;
    c.updatePlanIndicator();
    assert.equal( indicator.prop( 'hidden' ), true );
} );

test( 'plan updates remove standalone connect messages and keep Docs handoff offers', () => {
    const { c, roots } = harness();
    const document = new Element(); roots.set( c.document, document );
    document.off = () => document;
    const standalone = new Element( '<div class="mainwp-mintlify-message"></div>' );
    const handoff = new Element( '<div class="mainwp-mintlify-message mainwp-assistant-handoff-offer"></div>' );
    for ( const message of [ standalone, handoff ] ) {
        const card = new Element( '<div class="mainwp-assistant-connect-card"></div>' );
        card.parentMessage = message;
        message.append( card );
    }
    const collection = nodes => ( {
        closest: selector => collection( nodes.map( node => {
            assert.equal( selector, '.mainwp-mintlify-message' );
            return node.parentMessage;
        } ) ),
        not: selector => collection( nodes.filter( node => ! node.markup.includes( selector.slice( 1 ) ) ) ),
        remove: () => nodes.forEach( node => node.remove() )
    } );
    c.$messages = { find: selector => {
        assert.equal( selector, '.mainwp-assistant-connect-card' );
        return collection( [ ...standalone.nodes, ...handoff.nodes ] );
    } };
    let indicators = 0, checks = 0;
    c.updatePlanIndicator = () => { indicators++; };
    c.checkProviderAvailability = () => { checks++; };
    const start = chat.indexOf( "        $( document ).off( 'mainwp-assistant-chatgpt-updated.mintlify' )" );
    assert.ok( start >= 0 );
    vm.runInContext( chat.slice( start, chat.indexOf( '\n        } );', start ) + 13 ), c );
    const updated = document.handlers['mainwp-assistant-chatgpt-updated.mintlify'];
    updated( {}, { connected: true }, false );
    assert.ok( ! standalone.removed );
    updated( {}, { connected: true }, true );
    assert.equal( standalone.removed, true );
    assert.ok( ! handoff.removed );
    assert.equal( handoff.nodes.length, 1 );
    assert.equal( c.chatgptPlan.connected, true );
    assert.equal( indicators, 2 );
    assert.equal( checks, 1 );
} );

test( 'plan limit uses Manage usage as primary action; other plan errors never go to API keys', () => {
    const { c, calls } = harness();
    const link = c.buildErrorDeepLink( 'chatgpt_plan_limit' );
    assert.equal( link.content, 'Manage usage' );
    assert.match( link.markup, /green button/ );
    assert.equal( link.attr( 'href' ), 'https://chatgpt.com/settings/usage' );
    for ( const code of [ 'chatgpt_plan_unavailable', 'chatgpt_plan_not_eligible', 'chatgpt_plan_rejected_request' ] ) {
        assert.equal( c.buildErrorDeepLink( code ), '', code );
    }
    let reconnect = c.buildErrorDeepLink( 'chatgpt_plan_reconnect' );
    assert.equal( reconnect.content, 'Continue with ChatGPT' );
    reconnect.click();
    assert.deepEqual( calls, [ false ] );
    c.chatgptPlan.status = 'plan_disabled';
    reconnect = c.buildErrorDeepLink( 'chatgpt_plan_reconnect' );
    assert.equal( reconnect.content, 'Turn on ChatGPT plan use' );
} );

test( 'reconnect state outranks wizard_available, including renewed consent', () => {
    const { c } = harness( {}, { status: 'plan_disabled' } );
    c.wizardAvailable = true;
    c.providerReasonCode = 'chatgpt_plan_reconnect';
    assert.equal( c.connectPrompt(), 'card' );
    const choice = c.choices( 'welcome', true );
    assert.ok( contents( choice ).includes( 'ChatGPT plan use is turned off for MainWP.' ) );
    assert.ok( contents( choice ).includes( 'Turn on ChatGPT plan use' ) );
    c.status.status = 'needs_reconnect';
    assert.ok( contents( c.choices( 'settings', false ) ).includes( 'Your ChatGPT connection stopped working. Connect again to keep using your plan.' ) );
} );

test( 'every plan request uses its own secure nonce; callback fields are sent unchanged', () => {
    const { c, requests } = harness();
    const callback = 'http://127.0.0.1:1456/auth/callback?code=one%2Btwo&state=match';
    for ( const action of [ 'start', 'complete', 'disconnect', 'status', 'set_model', 'welcome_seen' ] ) {
        c.request( action, { callback } );
        const data = requests.at( -1 ).options.data;
        assert.equal( data.action, 'mainwp_assistant_chatgpt_' + action );
        assert.equal( data.security, data.action + '-nonce' );
        assert.equal( data.callback, callback );
    }
    assert.doesNotMatch( shared, /setInterval|setTimeout/ );
    assert.match( fn( chat, 'init' ), /refreshOnOpen\(\)/ );
} );

test( 'connected email, models and notices reach text setters', () => {
    const payload = '<img src=x onerror=alert(1)>';
    const { c, roots } = harness( {}, { connected: true, email: payload, model: 'one', models: [ { slug: 'one', display_name: payload } ] } );
    const card = new Element(); roots.set( '#mainwp-assistant-chatgpt-card', card );
    vm.runInContext( fn( shared, 'renderSettings' ), c );
    c.renderSettings();
    const plan = card.nodes[0];
    const account = plan.nodes.find( node => node.markup && node.markup.includes( 'mainwp-assistant-plan-account' ) );
    assert.ok( ! account.nodes.some( node => node && node.content === payload ), 'the email stays hidden until asked for' );
    assert.ok( account.nodes.some( node => node && node.attributes && node.attributes['data-tooltip'] === payload ), 'hover shows the email' );
    const form = plan.nodes.find( node => node.markup.includes( 'ui form' ) );
    const select = form.find( '.field' ).nodes[0];
    assert.equal( select.nodes[0].content, payload );
    assert.equal( select.nodes[0].value, 'one' );
    assert.equal( card.find( '.ui.dropdown' ).dropdownInitialized, true );
    assert.match( fn( shared, 'complete' ), /noticeModal\( response\.data\.notice \)/ );
    assert.match( fn( shared, 'noticeModal' ), /\.text\( notice \)/ );
    for ( const name of [ 'welcome', 'mutate' ] ) {
        assert.match( fn( shared, name ), /\.text\( (?:response\.data|data)\.notice \)/ );
    }
} );

test( 'the settings card leaves the API key choice to the Google Gemini row', () => {
    const { c, roots } = harness();
    const card = new Element(); roots.set( '#mainwp-assistant-chatgpt-card', card );
    vm.runInContext( fn( shared, 'renderSettings' ), c );
    c.renderSettings();
    assert.ok( contents( card ).includes( 'Continue with ChatGPT' ) );
    // The error page the sign-in ends on is explained before anyone clicks.
    assert.ok( contents( card ).some( line => /error page/.test( line ) ), 'settings card lists the sign-in steps' );
    assert.ok( ! contents( c.choices( 'error_bubble', false ) ).some( line => /error page/.test( line ) ), 'other entry points keep the steps in the dialog' );
    for ( const missing of [ 'Use an API key', 'Connect free AI', 'Use OpenAI or Anthropic instead' ] ) {
        assert.ok( ! contents( card ).includes( missing ), missing );
    }
    assert.ok( contents( c.choices( 'error_bubble', false ) ).includes( 'Connect free AI' ) );
} );

test( 'start opens authorization in a separate tab and requests a new registration only when chosen', () => {
    const { c, roots, requests } = harness();
    const modal = new Element(); roots.set( '#modal', modal );
    modal.data( 'new-registration', true );
    const tab = { location: {}, close() { this.closed = true; } };
    c.root.open = () => tab;
    c.root.document = { title: 'Assistant Settings' };
    c.setBusy = value => { c.busy = value; };
    c.connectModal = () => modal;
    const published = [];
    c.publish = data => published.push( data );
    for ( const name of [ 'error', 'waitTitle', 'start' ] ) { vm.runInContext( fn( shared, name ), c ); }
    c.start();
    assert.equal( requests[0].options.data.new_registration, 1 );
    assert.equal( requests[0].options.data.security, 'mainwp_assistant_chatgpt_start-nonce' );
    c.start();
    assert.equal( requests.length, 1, 'A pending start cannot send another request' );
    requests[0].callbacks.done( { success: true, data: { authorize_url: 'https://auth.openai.com/api/accounts/authorize?state=fixture' } } );
    requests[0].callbacks.always();
    assert.equal( tab.opener, null );
    assert.equal( tab.location.href, 'https://auth.openai.com/api/accounts/authorize?state=fixture' );
    assert.equal( modal.find( '[data-step="paste"], [data-finish]' ).prop( 'hidden' ), false );
    assert.equal( modal.find( '[data-step="start"], [data-start]' ).prop( 'hidden' ), true );
    assert.equal( modal.find( '[data-restart]' ).prop( 'hidden' ), false );
    assert.equal( modal.find( '[data-reopen] a' ).attr( 'href' ), 'https://auth.openai.com/api/accounts/authorize?state=fixture' );
    assert.equal( modal.find( '[data-reopen]' ).prop( 'hidden' ), false );
    assert.equal( c.root.document.title, 'Paste the address · Assistant Settings' );
    assert.equal( published.length, 1 );
} );

test( 'a refused paste shows plain words and clears the callback; a successful paste opens the welcome', () => {
    const { c, roots, requests } = harness();
    const modal = new Element(); roots.set( '#modal', modal );
    c.connectModal = () => modal;
    c.setBusy = value => { c.busy = value; };
    c.publish = () => {};
    let welcome;
    c.welcome = data => { welcome = data; };
    c.noticeModal = () => assert.fail( 'Welcome carries the notice when it is pending' );
    for ( const name of [ 'error', 'offerRetry', 'complete' ] ) { vm.runInContext( fn( shared, name ), c ); }
    modal.find( 'input' ).val( 'bad-paste' );
    c.complete();
    assert.equal( requests[0].options.data.callback, 'bad-paste' );
    requests[0].callbacks.done( { success: false, data: { error_code: 'invalid_callback' } } );
    requests[0].callbacks.always();
    assert.equal( modal.find( 'input' ).val(), '' );
    assert.equal( modal.find( '[data-error]' ).text(), c.errorText( 'invalid_callback' ) );
    assert.ok( modal.find( '[data-error]' ).text().includes( 'paste the full address' ) );
    assert.equal( modal.find( '[data-start]' ).prop( 'hidden' ), false, 'The refusal says Continue with ChatGPT, so that button is offered' );
    assert.equal( modal.find( '[data-finish], [data-restart], [data-reopen]' ).prop( 'hidden' ), true );
    modal.find( 'input' ).val( 'http://127.0.0.1:1456/auth/callback?state=match&code=single-use' );
    c.complete();
    const data = { connected: true, welcome_pending: true, notice: '<b>Remove the old connection</b>' };
    requests[1].callbacks.done( { success: true, data } );
    requests[1].callbacks.always();
    assert.equal( welcome, data );
    assert.equal( modal.modalState, 'hide' );
    assert.equal( modal.find( 'input' ).val(), '' );
} );

test( 'a failed completion request names the Dashboard and clears the callback', () => {
    const { c, roots, requests } = harness();
    const modal = new Element(); roots.set( '#modal', modal );
    c.connectModal = () => modal;
    c.setBusy = value => { c.busy = value; };
    for ( const name of [ 'error', 'offerRetry', 'complete' ] ) { vm.runInContext( fn( shared, name ), c ); }
    modal.find( 'input' ).val( 'address-from-new-tab' );
    c.complete();
    requests[0].callbacks.fail();
    requests[0].callbacks.always();
    assert.equal( modal.find( 'input' ).val(), '' );
    assert.equal( modal.find( '[data-error]' ).text(), 'The Dashboard did not respond. Reload the page and try again.' );
    assert.equal( modal.find( '[data-error]' ).prop( 'hidden' ), false );
    assert.equal( modal.find( '[data-start]' ).prop( 'hidden' ), false );
    assert.equal( c.busy, false );
} );

test( 'status refresh shows the welcome only when AI support is available', () => {
    for ( const canConnect of [ false, true ] ) {
        const { c, requests } = harness( { can_connect: canConnect } );
        const published = [], welcomed = [];
        c.publish = data => published.push( data );
        c.welcome = data => welcomed.push( data );
        vm.runInContext( fn( shared, 'refreshOnOpen' ), c );
        c.refreshOnOpen();
        const data = { connected: true, welcome_pending: true };
        requests[0].callbacks.done( { success: true, data } );
        assert.deepEqual( published, [ data ] );
        assert.deepEqual( welcomed, canConnect ? [ data ] : [] );
    }
} );

test( 'status refresh failures preserve rendered settings and replace the initial loading text', () => {
    for ( const rendered of [ false, true ] ) {
        for ( const failure of [ 'done', 'fail' ] ) {
            const { c, roots, requests } = harness();
            const card = new Element(); roots.set( '#mainwp-assistant-chatgpt-card', card );
            const target = card.find( '[data-plan-error]' );
            target.length = rendered ? 1 : 0;
            if ( rendered ) { card.append( new Element( '<button>Disconnect</button>' ) ); }
            card.text( rendered ? '' : 'Loading...' );
            for ( const name of [ 'error', 'refreshOnOpen' ] ) { vm.runInContext( fn( shared, name ), c ); }
            c.refreshOnOpen();
            requests[0].callbacks[failure]( { success: false, data: { error_code: 'forbidden' } } );
            const message = failure === 'fail' ? 'The Dashboard did not respond. Reload the page and try again.' : c.errorText( 'forbidden' );
            assert.equal( ( rendered ? target : card ).text(), message );
            assert.equal( ( rendered ? target : card ).prop( 'hidden' ), false );
            if ( rendered ) {
                assert.equal( card.text(), '' );
                assert.equal( card.nodes.length, 1 );
            }
        }
    }
} );

test( 'welcome is shown once while pending and acknowledged only by Got it', () => {
    const { c, created, requests } = harness();
    c.welcomeOpen = false;
    c.publish = () => {};
    for ( const name of [ 'error', 'welcome' ] ) { vm.runInContext( fn( shared, name ), c ); }
    c.welcome( { welcome_pending: false } );
    assert.equal( created.length, 0 );
    const notice = '<img src=x onerror=alert(1)>';
    c.welcome( { welcome_pending: true, notice } );
    assert.equal( requests.length, 0, 'Showing the welcome must not mark it seen' );
    const modal = created[0];
    assert.equal( modal.find( '.header' ).text(), "You're using your ChatGPT plan" );
    assert.equal( modal.find( '[data-notice]' ).text(), notice );
    const before = created.length;
    c.welcome( { welcome_pending: true } );
    assert.equal( created.length, before );
    modal.find( '.actions' ).nodes[0].click();
    assert.equal( requests[0].options.data.action, 'mainwp_assistant_chatgpt_welcome_seen' );
    requests[0].callbacks.done( { success: true, data: { welcome_pending: false } } );
    assert.equal( modal.modalState, 'hide' );
} );

test( 'all known connection refusals have a readable message and unknown codes have a fallback', () => {
    const { c } = harness();
    for ( const code of [ 'invalid_callback', 'access_denied', 'authorization_failed', 'invalid_client', 'invalid_id_token', 'unknown_kid', 'invalid_jwks', 'invalid_token_response', 'invalid_grant', 'network_error', 'request_failed', 'connection_busy', 'storage_failed', 'plan_disabled', 'needs_reconnect', 'invalid_model', 'forbidden' ] ) {
        assert.ok( c.errorText( code ).length > 20, code );
        assert.notEqual( c.errorText( code ), c.errorText( 'new_oauth_code' ), code );
    }
    assert.ok( c.errorText( 'new_oauth_code' ).includes( 'Continue with ChatGPT again' ) );
} );

test( 'a stopped plan still offers Disconnect and a different account without showing model controls', () => {
    for ( const status of [ 'needs_reconnect', 'plan_disabled' ] ) {
        const { c, roots } = harness( {}, { connected: false, status, email: 'owner@example.com' } );
        const card = new Element(); roots.set( '#mainwp-assistant-chatgpt-card', card );
        vm.runInContext( fn( shared, 'renderSettings' ), c );
        c.renderSettings();
        const copy = contents( card );
        assert.ok( ! copy.includes( 'owner@example.com' ) );
        assert.ok( copy.includes( 'Show account' ) );
        assert.ok( copy.includes( 'Disconnect' ) );
        assert.ok( copy.includes( 'Connect a different ChatGPT account or workspace' ) );
        assert.ok( copy.includes( status === 'plan_disabled' ? 'Turn on ChatGPT plan use' : 'Continue with ChatGPT' ) );
        assert.ok( ! card.walk().some( node => node.markup.includes( '<select' ) ) );
    }
} );

test( 'a disconnected saved registration can be replaced rather than reused', () => {
    const { c, calls } = harness( {}, { status: 'disconnected', client_id: 'oaiapp_saved', email: 'previous@example.com' } );
    const choice = c.choices( 'settings', false );
    choice.walk().find( node => node.content === 'Connect a different ChatGPT account or workspace' ).click();
    assert.deepEqual( calls, [ true ] );
} );

test( 'Start over is a deliberate return to step one and preserves the registration choice', () => {
    const { c, roots, requests } = harness();
    let starts = 0;
    c.start = () => { starts++; };
    c.complete = () => {};
    for ( const name of [ 'connectModal', 'copyKeys', 'waitTitle', 'signinSteps', 'openConnect', 'pastedUrl', 'isCallback', 'isSigninPage', 'pasteFromClipboard', 'focusPaste' ] ) { vm.runInContext( fn( shared, name ), c ); }
    const modal = c.connectModal();
    assert.equal( modal.find( '.header' ).text(), 'Use your ChatGPT plan' );
    roots.set( '#modal', modal );
    modal.data( 'new-registration', true );
    modal.find( 'input' ).val( 'address-from-first-attempt' );
    const actions = modal.find( '.actions' ).nodes;
    const restart = actions.find( node => node.content === 'Start over' );
    assert.ok( restart.markup.startsWith( '<a ' ) );
    assert.equal( restart.attr( 'class' ), undefined, 'Restart remains a text link' );
    c.busy = true;
    restart.click();
    assert.equal( modal.find( 'input' ).val(), 'address-from-first-attempt' );
    c.busy = false;
    c.root.document = { title: 'Paste the address · Assistant Settings' };
    c.pageTitle = 'Assistant Settings';
    restart.click();
    assert.equal( c.root.document.title, 'Assistant Settings', 'Step one no longer asks for the address' );
    assert.equal( starts, 0, 'Restart does not silently replace the pending attempt' );
    assert.equal( requests.length, 0 );
    assert.equal( modal.find( 'input' ).val(), '' );
    assert.equal( modal.data( 'new-registration' ), true );
    assert.equal( modal.find( '[data-step="start"], [data-start]' ).prop( 'hidden' ), false );
    assert.equal( modal.find( '[data-step="paste"], [data-finish], [data-restart], [data-error], [data-notice]' ).prop( 'hidden' ), true );
    actions.find( node => node.content === 'Continue with ChatGPT' ).click();
    assert.equal( starts, 1, 'The user can explicitly start a fresh attempt from step one' );
} );

test( 'the no-provider wizard link names the three-choice connection flow', () => {
    const { c, calls } = harness();
    const choice = c.buildErrorDeepLink( 'no_provider' );
    assert.equal( choice.text(), 'Connect AI' );
    choice.click();
    assert.deepEqual( calls, [ 'error_bubble' ] );
    c.wizardReady = () => true;
    for ( const code of [ 'no_provider', 'no_usable_provider', 'provider_key_rejected' ] ) {
        const link = c.buildErrorDeepLink( code );
        assert.match( link, /mainwp-assistant-wizard-link/ );
        assert.match( link, />Connect AI<\/a>/ );
        assert.doesNotMatch( link, /Connect free AI/ );
    }
    assert.match( php, /'wizard_link_text'\s*=> esc_html__\( 'Connect AI', 'mainwp' \)/ );
} );

test( 'ineligible accounts cannot retry the same request, while transient failures can', () => {
    const { c } = harness();
    c.lastUserMessage = 'List sites';
    c.requestDocsHandoffBypass = false;
    c.isBatchRendering = false;
    c.scrollToBottom = () => {};
    const messages = [];
    c.$messages = { append: bubble => messages.push( bubble ) };
    c.buildErrorDeepLink = () => '';
    c.checkProviderAvailability = () => {};
    vm.runInContext( fn( chat, 'appendErrorMessage' ), c );
    for ( const code of [ 'chatgpt_plan_not_eligible', 'chatgpt_plan_limit', 'chatgpt_plan_reconnect' ] ) {
        c.appendErrorMessage( 'Plan error', code );
        assert.doesNotMatch( messages.at( -1 ).markup, /mainwp-assistant-error-retry/ );
    }
    for ( const code of [ 'chatgpt_plan_unavailable', 'provider_unreachable' ] ) {
        c.appendErrorMessage( 'Try later', code );
        assert.match( messages.at( -1 ).markup, /mainwp-assistant-error-retry/ );
    }
} );

test( 'empty and whitespace-only pastes never consume a server attempt', () => {
    const { c, roots, requests } = harness();
    const modal = new Element(); roots.set( '#modal', modal );
    c.connectModal = () => modal;
    c.setBusy = () => assert.fail( 'Empty input should not start an AJAX operation' );
    for ( const name of [ 'error', 'complete' ] ) { vm.runInContext( fn( shared, name ), c ); }
    for ( const callback of [ '', '  \t\n ' ] ) {
        modal.find( 'input' ).val( callback );
        c.complete();
        assert.equal( requests.length, 0 );
        assert.equal( modal.find( '[data-error]' ).text(), 'Paste the address from the error page first.' );
        assert.equal( modal.find( '[data-error]' ).prop( 'hidden' ), false );
    }
} );

test( 'Manage usage is primary only for a connected plan', () => {
    for ( const status of [ 'disconnected', 'needs_reconnect', 'plan_disabled', 'connected' ] ) {
        const { c } = harness( {}, { status, connected: status === 'connected' } );
        assert.match( c.manageLink( true ).attr( 'class' ), status === 'connected' ? /green button/ : /basic button/ );
        assert.match( c.buildErrorDeepLink( 'chatgpt_plan_limit' ).markup, /green button/ );
    }
} );

function pasteHarness( clipboard, built = false ) {
    const h = harness();
    const out = { ...h, completes: 0 };
    h.c.root.isSecureContext = true;
    h.c.root.navigator = clipboard === undefined ? {} : { clipboard };
    h.c.start = () => {};
    h.c.complete = () => { out.completes++; };
    const names = [ 'error', 'pastedUrl', 'isCallback', 'isSigninPage', 'pasteFromClipboard', 'focusPaste' ];
    if ( built ) {
        names.push( 'connectModal', 'copyKeys', 'waitTitle', 'signinSteps' );
    } else {
        out.modal = new Element(); h.roots.set( '#modal', out.modal );
        h.c.connectModal = () => out.modal;
    }
    for ( const name of names ) { vm.runInContext( fn( shared, name ), h.c ); }
    if ( built ) { out.modal = h.c.connectModal(); }
    return out;
}

const CALLBACK = 'http://127.0.0.1:1456/auth/callback?code=ac.single-use&state=fixture&client_id=oaiapp_fixture';

test( 'step one explains the error page before the user leaves for ChatGPT', () => {
    const { c } = harness();
    c.start = () => {};
    c.complete = () => {};
    for ( const name of [ 'connectModal', 'copyKeys', 'waitTitle', 'signinSteps', 'pastedUrl', 'isCallback', 'isSigninPage', 'pasteFromClipboard', 'focusPaste' ] ) { vm.runInContext( fn( shared, name ), c ); }
    const modal = c.connectModal();
    const steps = contents( modal.find( '[data-step="start"]' ) );
    assert.deepEqual( steps, [
        'Uses the ChatGPT plan you already pay for. Each person on this Dashboard connects their own.',
        'Sign in to ChatGPT in the new tab and approve MainWP.',
        "ChatGPT then sends that tab to an error page, such as “This site can't be reached”. That's expected, so keep that tab open.",
        "Click that page's address bar, press Ctrl+C to copy the full address, and paste it back into MainWP."
    ] );
    assert.match( modal.find( '[data-step="paste"] > p:first-child' ).text(), /Click its address bar and press Ctrl\+C to copy the full address\.$/ );
    assert.equal( modal.find( 'label' ).text(), 'Paste the address from the error page' );
    assert.equal( modal.find( '[data-reopen] a' ).text(), 'Closed the ChatGPT tab? Open it again' );
    assert.equal( modal.find( '[data-paste]' ).prop( 'hidden' ), true, 'No Paste button without clipboard access' );
    assert.equal( modal.find( '.ui.input' ).classes.action, false, 'Without the button the field keeps a full border' );
} );

test( 'only a loopback callback address counts as a ChatGPT return address', () => {
    const { c } = pasteHarness();
    for ( const value of [ CALLBACK, '  ' + CALLBACK + '\n', 'http://127.0.0.1:1456/auth/callback?error=access_denied&state=fixture' ] ) {
        assert.equal( c.isCallback( value ), true, value );
    }
    for ( const value of [
        '', 'not a url', 'https://auth.openai.com/api/accounts/authorize?state=fixture&code=x',
        'http://localhost:1456/auth/callback?state=fixture&code=x', 'http://127.0.0.1:1456/callback?state=fixture&code=x',
        'http://127.0.0.1:1456/auth/callback?code=x', 'https://127.0.0.1:1456/auth/callback?state=fixture&code=x'
    ] ) {
        assert.equal( c.isCallback( value ), false, value );
    }
} );

test( 'pasting a callback address into the field connects without a Finish click', () => {
    const p = pasteHarness( undefined, true );
    const input = p.modal.find( 'input' );
    const paste = value => {
        let prevented = false;
        input.handlers.paste( { originalEvent: { clipboardData: { getData: () => value } }, preventDefault() { prevented = true; } } );
        return prevented;
    };
    assert.equal( paste( 'something else' ), false, 'Other text pastes normally' );
    assert.equal( p.completes, 0 );
    assert.equal( paste( '  ' + CALLBACK + '\n' ), true );
    assert.equal( input.val(), CALLBACK );
    assert.equal( p.completes, 1 );
} );

test( 'the Paste button submits a clipboard callback and never copies other clipboard text', async () => {
    let clip = CALLBACK;
    const p = pasteHarness( { readText: async () => clip } );
    p.c.pasteFromClipboard();
    await new Promise( resolve => setImmediate( resolve ) );
    assert.equal( p.modal.find( 'input' ).val(), CALLBACK );
    assert.equal( p.completes, 1 );

    p.modal.find( 'input' ).val( '' );
    clip = 'private note from another app';
    p.c.pasteFromClipboard();
    await new Promise( resolve => setImmediate( resolve ) );
    assert.equal( p.modal.find( 'input' ).val(), '' );
    assert.equal( p.completes, 1 );
    assert.equal( p.modal.find( '[data-error]' ).text(), p.c.errorText( 'clipboard_no_callback' ) );
    assert.equal( p.modal.find( '[data-error]' ).prop( 'hidden' ), false );
} );

test( 'a refused clipboard read leaves the field for a normal paste', async () => {
    const p = pasteHarness( { readText: async () => { throw new Error( 'NotAllowedError' ); } } );
    p.c.pasteFromClipboard();
    await new Promise( resolve => setImmediate( resolve ) );
    assert.equal( p.completes, 0 );
    assert.equal( p.modal.find( '[data-error]' ).prop( 'hidden' ), undefined );
} );

test( 'the Paste button shows only where the clipboard can be read', () => {
    const p = pasteHarness( { readText: async () => '' }, true );
    assert.equal( p.modal.find( '[data-paste]' ).prop( 'hidden' ), false );
    assert.equal( p.modal.find( '[data-paste]' ).text(), 'Paste' );
    assert.equal( p.modal.find( '.ui.input' ).classes.action, true );
} );

test( 'returning to the Dashboard focuses an empty paste field only on the paste step', () => {
    const p = pasteHarness();
    const input = p.modal.find( 'input' );
    let focused = 0;
    input.trigger = event => { if ( event === 'focus' ) { focused++; } return input; };
    let visible = true;
    p.modal.is = () => visible;
    p.modal.find( '[data-step="paste"]' ).prop( 'hidden', false );
    p.c.focusPaste();
    assert.equal( focused, 1 );
    input.val( CALLBACK );
    p.c.focusPaste();
    assert.equal( focused, 1, 'A filled field is left alone' );
    input.val( '' );
    p.modal.find( '[data-step="paste"]' ).prop( 'hidden', true );
    p.c.focusPaste();
    visible = false;
    p.modal.find( '[data-step="paste"]' ).prop( 'hidden', false );
    p.c.focusPaste();
    assert.equal( focused, 1, 'Step one and a closed dialog are left alone' );
} );

test( 'copy instructions name the shortcut for the reader\'s keyboard', () => {
    const { c } = harness();
    vm.runInContext( fn( shared, 'copyKeys' ), c );
    for ( const [ navigator, keys ] of [
        [ undefined, 'Ctrl+C' ], [ { platform: 'Win32' }, 'Ctrl+C' ], [ { platform: 'Linux x86_64' }, 'Ctrl+C' ],
        [ { platform: 'MacIntel' }, '⌘C' ], [ { userAgentData: { platform: 'macOS' }, platform: '' }, '⌘C' ], [ { platform: 'iPad' }, '⌘C' ]
    ] ) {
        c.root.navigator = navigator;
        assert.equal( c.copyKeys(), keys, JSON.stringify( navigator ) );
    }
} );

test( 'a blocked tab leaves only the blocked-tab link, and closing the dialog restores the tab title', () => {
    const { c, roots, requests } = harness();
    c.start = () => {};
    c.complete = () => {};
    c.setBusy = value => { c.busy = value; };
    c.publish = () => {};
    c.root.open = () => null;
    c.root.document = { title: 'Assistant Settings' };
    for ( const name of [ 'connectModal', 'copyKeys', 'waitTitle', 'signinSteps', 'pastedUrl', 'isCallback', 'isSigninPage', 'pasteFromClipboard', 'focusPaste', 'error' ] ) { vm.runInContext( fn( shared, name ), c ); }
    const modal = c.connectModal();
    roots.set( '#modal', modal );
    vm.runInContext( fn( shared, 'start' ), c );
    c.start();
    requests[0].callbacks.done( { success: true, data: { authorize_url: 'https://auth.openai.com/api/accounts/authorize?state=fixture' } } );
    requests[0].callbacks.always();
    assert.equal( modal.find( '[data-reopen]' ).prop( 'hidden' ), true );
    assert.equal( modal.find( '[data-error]' ).prop( 'hidden' ), false );
    assert.equal( c.root.document.title, 'Paste the address · Assistant Settings' );
    c.waitTitle( true );
    assert.equal( c.root.document.title, 'Paste the address · Assistant Settings', 'The title is never prefixed twice' );
    modal.modalState.onHidden();
    assert.equal( c.root.document.title, 'Assistant Settings' );
} );

test( 'plan turns show tokens with the plan, not an estimated dollar cost', () => {
    const c = vm.createContext( { l10n: ( key, fallback ) => fallback } );
    [ 'parseUsageFromResponse', 'extractTotalTokens', 'extractKnownUsd', 'formatCostText', 'formatUsd' ].forEach( name => vm.runInContext( fn( chat, name ), c ) );
    const usage = { total_tokens: 1200 };
    const plan = c.parseUsageFromResponse( { usage, cost: { usd: 0, known: true, provider: 'mainwp-chatgpt-plan' } } );
    assert.equal( c.formatCostText( plan ), '1,200 tokens · ChatGPT plan' );
    const key = c.parseUsageFromResponse( { usage, cost: { usd: 0.0123, known: true, provider: 'openai' } } );
    assert.equal( c.formatCostText( key ), '1,200 tokens · est. $0.01' );
    assert.match( php, /'token_usage_plan'\s*=> esc_html__\( '%s tokens · ChatGPT plan'/ );
} );

test( 'the paste check accepts ordinary copy variations and names the sign-in page', () => {
    const c = vm.createContext( { URL } );
    [ 'pastedUrl', 'isCallback', 'isSigninPage' ].forEach( name => vm.runInContext( fn( wizard, name ), c ) );
    const url = 'http://127.0.0.1:1456/auth/callback?code=ac.1.AAAq0x&scope=openid+email&state=s-123&iss=https%3A%2F%2Fauth.openai.com&client_id=oaiapp_test';
    [
        url,
        url.slice( 'http://'.length ),
        '  \n' + url.slice( 0, 50 ) + '\n' + url.slice( 50 ) + ' ',
        '"' + url + '"',
        url.replace( '/auth/callback?', '/auth/callback/?' ),
    ].forEach( pasted => assert.equal( c.isCallback( pasted ), true, JSON.stringify( pasted ) ) );
    [ url.replace( '127.0.0.1', 'localhost' ), 'http://127.0.0.1:1455/auth/callback?state=s&code=c', 'http://example.com:1456/auth/callback?state=s&code=c', 'http://127.0.0.1:1456/auth/callback?code=c', 'hello' ]
        .forEach( pasted => assert.equal( c.isCallback( pasted ), false, pasted ) );
    assert.equal( c.isSigninPage( 'https://auth.openai.com/oauth/authorize?state=s' ), true );
    assert.equal( c.isSigninPage( url ), false );
    assert.match( php, /'signin_address'\s*=> __\(/ );
} );

test( 'only a disconnect reloads AI Connection, and never over a notice the user must read', () => {
    const run = ( rendered, action, response ) => {
        const { c, roots, requests } = harness( {}, { connected: true } );
        let reloads = 0;
        c.root.location = { reload: () => { reloads++; } };
        c.renderSettings = () => {};
        c.error = () => {};
        roots.set( '#mainwp-assistant-chatgpt-card', new Element() );
        if ( rendered ) {
            roots.set( '.mainwp-assistant-site-connection[data-plan-connected="1"]', new Element() );
        }
        for ( const name of [ 'publish', 'mutate' ] ) { vm.runInContext( fn( shared, name ), c ); }
        if ( 'start' === action ) {
            c.publish( response.data, true );
        } else {
            c.mutate( action );
            requests.at( -1 ).callbacks.done( response );
        }
        return reloads;
    };
    assert.equal( run( true, 'disconnect', { success: true, data: { connected: false, notice: '' } } ), 1 );
    assert.equal( run( true, 'disconnect', { success: true, data: { connected: false, notice: 'Remote revocation was not confirmed.' } } ), 0 );
    assert.equal( run( true, 'disconnect', { success: false, data: { connected: true, error_code: 'storage_failed' } } ), 0 );
    assert.equal( run( true, 'start', { success: true, data: { connected: false, authorize_url: 'https://auth.openai.com/' } } ), 0, 'starting a sign-in never reloads' );
    assert.equal( run( false, 'disconnect', { success: true, data: { connected: false, notice: '' } } ), 0, 'pages without the rows never reload' );
} );
