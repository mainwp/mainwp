/* Run with: node --test tests/js/command-palette.test.js */
const assert = require( 'node:assert/strict' );
const { test } = require( 'node:test' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/js/mainwp-command-palette.js' ), 'utf8' );
const mainwpSource = fs.readFileSync( path.join( __dirname, '../../assets/js/mainwp.js' ), 'utf8' );
const sharedSync = mainwpSource.slice( mainwpSource.indexOf( 'globalThis.mainwp_sync_sites_data = function' ), mainwpSource.indexOf( 'mainwpVars.websitesToUpdate = [];' ) );

function fixture( supportsLoader = true, mainwpPage = true, withPalette = false ) {
    const timers = new Map();
    const requests = [];
    const states = [];
    const effects = [];
    const pendingEffects = [];
    const elements = new Map();
    const assigned = [];
    const opened = [];
    const spoken = [];
    const registered = [];
    const observers = [];
    const syncCalls = [];
    const syncQueues = [];
    const popups = [];
    const savedStatuses = [];
    let recentNames = [];
    let reloaded = 0;
    let closed = 0;
    let hookIndex = 0;
    let nextTimer = 0;
    let loader;
    let loaderRegistrations = 0;

    function element( tag ) {
        const node = {
            tag, children: [], listeners: {}, attributes: {},
            dataset: {}, className: '', style: {},
            appendChild( child ) { this.children.push( child ); child.parentNode = this; },
            append( ...children ) { for ( const child of children ) { if ( typeof child === 'object' ) this.appendChild( child ); else this.children.push( child ); } },
            prepend( child ) { this.children.unshift( child ); child.parentNode = this; },
            replaceChildren() { this.children = []; },
            setAttribute( key, value ) { this.attributes[ key ] = value; },
            getAttribute( key ) { return this.attributes[ key ] ?? null; },
            removeAttribute( key ) { delete this.attributes[ key ]; },
            addEventListener( key, callback ) { this.listeners[ key ] = callback; },
            removeEventListener( key, callback ) { if ( this.listeners[ key ] === callback ) delete this.listeners[ key ]; },
            contains( child ) { return child === this || this.children.some( ( item ) => typeof item === 'object' && item.contains( child ) ); },
            focus() { document.activeElement = this; },
            matches( selector ) {
                if ( selector.startsWith( '.' ) ) {
                    const className = selector.slice( 1 ).split( '[' )[ 0 ];
                    if ( ! this.classList.contains( className ) ) return false;
                    if ( ! selector.includes( '[' ) ) return true;
                }
                if ( selector === 'button' ) return this.tag === 'button';
                return Array.from( selector.matchAll( /\[([^=\]]+)(?:="([^"]+)")?\]/g ) ).every( ( [ , key, value ] ) => {
                    const actual = key === 'data-action' ? this.dataset.action : this.getAttribute( key );
                    return actual !== null && actual !== undefined && ( value === undefined || actual === value );
                } );
            },
            querySelectorAll( selector ) {
                return this.children.filter( ( child ) => typeof child === 'object' ).flatMap( ( child ) => [ ...( child.matches( selector ) ? [ child ] : [] ), ...child.querySelectorAll( selector ) ] );
            },
            querySelector( selector ) { return this.querySelectorAll( selector )[ 0 ] || null; },
            closest( selector ) { for ( let item = this; item; item = item.parentNode ) { if ( item.matches( selector ) ) return item; } return null; },
            get lastElementChild() { return this.children.filter( ( child ) => typeof child === 'object' ).at( -1 ); },
            remove() { elements.delete( this.id ); },
            set id( value ) { this._id = value; elements.set( value, this ); },
            get id() { return this._id; },
        };
        node.classList = {
            contains: ( name ) => node.className.split( ' ' ).includes( name ),
            add: ( name ) => { node.className = Array.from( new Set( [ ...node.className.split( ' ' ).filter( Boolean ), name ] ) ).join( ' ' ); },
            remove: ( name ) => { node.className = node.className.split( ' ' ).filter( ( value ) => value !== name ).join( ' ' ); },
        };
        return node;
    }
    elements.set( 'wpbody-content', element( 'div' ) );
    elements.set( 'mainwp-content-wrap', element( 'div' ) );

    const dispatcher = {
        registerCommand( command ) { registered.push( command ); },
        unregisterCommand() {},
        close() { closed++; },
    };
    if ( supportsLoader ) {
        dispatcher.registerCommandLoader = ( config ) => { loader = config; loaderRegistrations++; };
    }
    const window = {
        URLSearchParams, AbortController,
        location: { pathname: '/wp-admin/admin.php', search: '?page=managesites&dashboard=28', assign: ( url ) => assigned.push( url ) },
        mainwpVars: { bulkTaskRunning: false },
        open: ( ...args ) => opened.push( args ),
        addEventListener() {},
        setTimeout( callback, ms ) { timers.set( ++nextTimer, { callback, ms } ); return nextTimer; },
        clearTimeout( id ) { timers.delete( id ); },
        MutationObserver: class {
            constructor( callback ) { this.callback = callback; observers.push( this ); }
            observe( target ) { this.target = target; }
            disconnect() { this.target = null; }
        },
        fetch( url, options ) {
            return new Promise( ( resolve, reject ) => requests.push( { url, options, resolve, reject } ) );
        },
        mainwpCommandPalette: {
            commands: [ { name: 'existing-menu', label: 'Settings', url: '/settings' } ],
            siteSearch: {
                ajaxUrl: '/ajax', searchNonce: 'search-nonce',
                strings: { searchError: 'Retry search', syncingAction: 'Syncing…', searchLabel:'Search sites, clients and commands', actionsFor:'Actions for %s', siteAction:'%1$s for %2$s', selectSite:'Select site', actions:'Actions', search:'Search', enter:'Enter', keyboard:'Enter for %s' },
            },
        },
        wp: {
            data: {
                dispatch: ( store ) => store === 'core/preferences' ? { set: ( scope, key, value ) => { recentNames = value; } } : dispatcher,
                select: () => ( { get: () => recentNames } ),
                useSelect: ( callback ) => callback( () => ( { get: () => recentNames } ) ),
            },
            a11y: { speak: ( message ) => spoken.push( message ) },
            i18n: { sprintf: ( template, ...values ) => template.replace( /%(?:(\d+)\$)?s/g, ( match, index ) => values[ index ? Number( index ) - 1 : 0 ] ) },
            element: {
                useState( initial ) {
                    const index = hookIndex++;
                    if ( !( index in states ) ) { states[ index ] = initial; }
                    return [ states[ index ], ( value ) => { states[ index ] = typeof value === 'function' ? value( states[ index ] ) : value; } ];
                },
                useEffect( callback, deps ) {
                    const index = hookIndex++;
                    const previous = effects[ index ];
                    if ( ! previous || deps.some( ( value, i ) => value !== previous.deps[ i ] ) ) {
                        if ( previous?.cleanup ) { previous.cleanup(); }
                        effects[ index ] = { deps };
                        pendingEffects.push( () => { effects[ index ].cleanup = callback(); } );
                    }
                },
            },
        },
    };
    const document = {
        readyState: 'complete', body: element( 'body' ),
        querySelector: ( selector ) => mainwpPage && selector === '.mainwp-content-wrap' ? elements.get( 'mainwp-content-wrap' ) : document.body.querySelector( selector ),
        getElementById: ( id ) => elements.get( id ), createElement: element,
    };
    document.body.className = mainwpPage ? 'mainwp-ui' : '';
    const syncContainer = element( 'div' );
    syncContainer.id = 'sync-sites-status';
    window.dashboardSync = { initialized: mainwpPage, container: syncContainer, rowMap: new Map(), statusMap: new Map() };
    if ( mainwpPage ) {
        const modal = element( 'div' );
        modal.id = 'mainwp-sync-sites-modal';
        modal.appendChild( syncContainer );
        document.body.appendChild( modal );
        const row = element( 'div' );
        row.className = 'item';
        const status = element( 'div' );
        status.className = 'sync-site-status';
        status.setAttribute( 'siteid', '28' );
        row.appendChild( status );
        const selection = element( 'input' );
        selection.className = 'dashboard_wp_id';
        selection.setAttribute( 'value', '28' );
        selection.setAttribute( 'error-status', '0' );
        row.appendChild( selection );
        syncContainer.appendChild( row );
        window.dashboardSync.rowMap.set( '28', row );
        window.dashboardSync.statusMap.set( '28', status );
    }
    function jQuery( selector ) {
        if ( selector === '.dashboard_wp_id[error-status=0]' ) {
            return { map: ( callback ) => document.body.querySelectorAll( '.dashboard_wp_id' ).filter( ( input ) => input.getAttribute( 'error-status' ) === '0' ).map( ( input, index ) => callback( index, input ) ) };
        }
        if ( selector === '#mainwp-sync-sites-modal' ) return { attr: () => 28 };
        return { val: () => selector.getAttribute( 'value' ) };
    }
    jQuery.grep = ( values, callback ) => values.filter( callback );
    jQuery.inArray = ( value, values ) => values.indexOf( value );
    jQuery.post = ( url, data ) => savedStatuses.push( data );
    vm.runInNewContext( sharedSync, {
        globalThis: window, document, jQuery, mainwpVars: window.mainwpVars,
        MainWP: { I18n: { t: ( value ) => value } }, ajaxurl: '/ajax',
        mainwpPopup: ( selector ) => { assert.equal( selector, '#mainwp-sync-sites-modal' ); return { init: ( config ) => popups.push( config ) }; },
        dashboard_update_site_hide: ( id ) => { window.dashboardSync.rowMap.get( id ).style.display = 'none'; },
        dashboard_update_site_status() {}, mainwp_secure_data: ( data ) => data,
        dashboard_update: ( ids, globalSync, action ) => { syncQueues.push( { ids: Array.from( ids ), globalSync, action } ); window.mainwpVars.bulkTaskRunning = true; },
        history: { pushState() {} }, mainwp_forceReload: () => reloaded++,
    } );
    const syncSites = window.mainwp_sync_sites_data;
    window.mainwp_sync_sites_data = ( ...args ) => { syncCalls.push( args ); return syncSites( ...args ); };
    let palette;
    let input;
    let root;
    const items = [];
    if ( withPalette ) {
        palette = element( 'div' );
        palette.className = 'commands-command-menu__container';
        root = element( 'div' );
        root.setAttribute( 'cmdk-root', '' );
        input = element( 'input' );
        input.setAttribute( 'cmdk-input', '' );
        input.value = '';
        root.appendChild( input );
        const list = element( 'div' );
        list.setAttribute( 'cmdk-list', '' );
        list.setAttribute( 'role', 'listbox' );
        for ( const id of [ 28, 11 ] ) {
            const item = element( 'div' );
            item.setAttribute( 'cmdk-item', '' );
            item.setAttribute( 'data-value', 'mainwp/site/' + id );
            item.setAttribute( 'aria-selected', id === 28 ? 'true' : 'false' );
            list.appendChild( item );
            items.push( item );
        }
        const client = element( 'div' );
        client.setAttribute( 'cmdk-item', '' );
        client.setAttribute( 'data-value', 'mainwp/client/1' );
        client.setAttribute( 'aria-selected', 'false' );
        list.appendChild( client );
        items.push( client );
        root.appendChild( list );
        palette.appendChild( root );
        document.body.appendChild( palette );
        input.focus();
    }
    vm.runInNewContext( source, { window, document } );

    return {
        requests, registered, assigned, opened, spoken, elements, palette, input,
        syncCalls, syncQueues, popups, savedStatuses, sync: window.dashboardSync,
        get reloaded() { return reloaded; },
        get closed() { return closed; },
        get activeElement() { return document.activeElement; },
        selectSite( id ) {
            for ( const item of items ) item.setAttribute( 'aria-selected', String( item.getAttribute( 'data-value' ) === 'mainwp/site/' + id ) );
            for ( const observer of observers ) if ( observer.target === root ) observer.callback();
        },
        selectClient( id ) {
            for ( const item of items ) item.setAttribute( 'aria-selected', String( item.getAttribute( 'data-value' ) === 'mainwp/client/' + id ) );
            for ( const observer of observers ) if ( observer.target === root ) observer.callback();
        },
        get loaderRegistrations() { return loaderRegistrations; },
        setHistory( names ) { recentNames = names; },
        get history() { return recentNames; },
        selectRecentSite( id ) {
            const item = items.find( ( node ) => node.getAttribute( 'data-value' ) === 'mainwp/site/' + id );
            item.setAttribute( 'data-value', 'recent-mainwp/site/' + id );
            for ( const node of items ) node.setAttribute( 'aria-selected', String( node === item ) );
            for ( const observer of observers ) if ( observer.target === root ) observer.callback();
        },
        render( search ) {
            if ( input ) input.value = search;
            hookIndex = 0;
            loader.hook( { search } );
            while ( pendingEffects.length ) { pendingEffects.shift()(); }
            hookIndex = 0;
            return loader.hook( { search } );
        },
        runTimers( ms ) {
            for ( const [ id, timer ] of Array.from( timers ) ) {
                if ( timer.ms === ms ) { timers.delete( id ); timer.callback(); }
            }
        },
        respond( index, data, success = true ) {
            requests[ index ].resolve( { json: async () => ( { success, data } ) } );
            return new Promise( ( resolve ) => setImmediate( resolve ) );
        },
    };
}

function siteCommands( siteId = 28, defaultAction = 'operations' ) {
    return [ {
        name: 'mainwp/site/' + siteId,
        label: 'Alder Home (example.com)',
        category: defaultAction === 'sync' ? 'command' : 'view',
        defaultAction, siteId, siteName: 'Alder Home',
        actions: Object.fromEntries( [ 'operations', 'admin', 'sync' ].map( ( action ) => [ action, { label: action, url: '/' + action } ] ) ),
        keywords: [ 'example.com' ],
    } ];
}

function clientCommand( id = 1 ) {
    return {
        name: 'mainwp/client/' + id,
        label: 'Alder & Co (Client)',
        keywords: [ 'Alder & Co', 'Client' ],
        category: 'view',
        url: '/wp-admin/admin.php?page=ManageClients&client_id=' + id,
    };
}

test( 'existing menu commands still work without the loader API', () => {
    const app = fixture( false );
    app.registered[ 0 ].callback();
    assert.deepEqual( app.assigned, [ '/settings' ] );
    assert.equal( app.loaderRegistrations, 0 );
} );

test( 'registers one loader and searches only after two characters and the debounce', () => {
    const app = fixture();
    app.runTimers( 0 );
    assert.equal( app.loaderRegistrations, 1 );
    assert.equal( app.render( '' ).commands.length, 0 );
    app.render( 'a' );
    app.runTimers( 250 );
    assert.equal( app.requests.length, 0 );
    app.render( 'al' );
    app.render( 'alder' );
    assert.equal( app.requests.length, 0 );
    app.runTimers( 250 );
    assert.equal( app.requests.length, 1 );
    assert.deepEqual( Object.fromEntries( app.requests[ 0 ].options.body ), {
        search: 'alder', security: 'search-nonce', action: 'mainwp_command_palette_search_sites',
    } );
    assert.equal( app.requests[ 0 ].options.credentials, 'same-origin' );
} );

test( 'discarded responses cannot replace results for a newer query', async () => {
    const app = fixture();
    app.render( 'alder' );
    app.runTimers( 250 );
    app.render( 'hotel' );
    app.runTimers( 250 );
    assert.equal( app.requests[ 0 ].options.signal.aborted, true );
    await app.respond( 1, siteCommands( 11 ) );
    assert.equal( app.render( 'hotel' ).commands[ 0 ].name, 'mainwp/site/11' );
    await app.respond( 0, siteCommands( 28 ) );
    assert.equal( app.render( 'hotel' ).commands[ 0 ].name, 'mainwp/site/11' );
    assert.equal( app.render( '' ).commands.length, 0 );
} );

test( 'normalized URL matches remain searchable and navigation uses the intended target', async () => {
    const app = fixture();
    const query = 'https://www.example.com/';
    app.render( query );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands() );
    const { commands } = app.render( query );
    assert.ok( commands[ 0 ].keywords.includes( query ) );
    let closed = 0;
    commands[ 0 ].callback( { close: () => closed++ } );
    const admin = fixture();
    admin.render( query );
    admin.runTimers( 250 );
    await admin.respond( 0, siteCommands( 28, 'admin' ) );
    admin.render( query ).commands[ 0 ].callback( { close: () => closed++ } );
    assert.deepEqual( app.assigned, [ '/operations' ] );
    assert.deepEqual( admin.opened, [ [ '/admin', '_blank', 'noopener,noreferrer' ] ] );
    assert.equal( closed, 2 );
} );

test( 'failed searches offer a retry and complete on the next successful response', async () => {
    const app = fixture();
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, 'Expired nonce', false );
    const failed = app.render( 'alder' );
    assert.equal( failed.isLoading, false );
    assert.equal( failed.commands[ 0 ].name, 'mainwp/site-search-retry' );
    failed.commands[ 0 ].callback();
    assert.equal( app.render( 'alder' ).isLoading, true );
    app.runTimers( 250 );
    await app.respond( 1, siteCommands() );
    assert.equal( app.render( 'alder' ).commands.length, 1 );
} );

test( 'sync directly uses the existing bulk popup, selection and refresh callback', async () => {
    const app = fixture();
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands( 28, 'sync' ) );
    const sync = app.render( 'alder' ).commands[ 0 ];
    sync.callback();
    sync.callback();
    assert.equal( app.requests.length, 1, 'No custom sync AJAX request' );
    assert.equal( app.syncCalls.length, 1, 'Existing task guard prevents duplicate syncs' );
    assert.equal( app.syncCalls[ 0 ].length, 1, 'No palette-specific parameters' );
    assert.deepEqual( Array.from( app.syncCalls[ 0 ][ 0 ] ), [ '28' ] );
    assert.deepEqual( app.syncQueues, [ { ids: [ '28' ], globalSync: false, action: undefined } ] );
    assert.equal( app.popups[ 0 ].title, 'Data Synchronization' );
    assert.equal( app.popups[ 0 ].progressMax, 1 );
    assert.equal( app.popups[ 0 ].statusText, 'synced' );
    assert.equal( app.savedStatuses[ 0 ].action, 'mainwp_status_saving' );
    app.popups[ 0 ].callback();
    assert.equal( app.reloaded, 1, 'Standard popup callback refreshes the page' );
    assert.equal( app.sync.rowMap.size, 1, 'Existing popup row is reused' );
} );

test( 'sync is omitted where the standard popup is unavailable', async () => {
    const app = fixture( true, false );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands() );
    const command = app.render( 'alder' ).commands[ 0 ];
    assert.equal( command.site.actions.sync, undefined );
    command.callback();
    assert.deepEqual( app.assigned, [ '/operations' ] );
    assert.equal( app.syncCalls.length, 0 );
} );

test( 'one result per site exposes actions outside the listbox and follows the selected ID', async () => {
    const app = fixture( true, true, true );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, [ ...siteCommands(), ...siteCommands( 11 ) ] );
    assert.equal( app.render( 'alder' ).commands.length, 2 );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    assert.equal( bar.hidden, false );
    assert.equal( bar.closest( '[role="listbox"]' ), null );
    app.selectSite( 11 );
    const admin = bar.querySelector( '[data-action="admin"]' );
    const sync = bar.querySelector( '[data-action="sync"]' );
    assert.equal( bar.querySelectorAll( 'button' ).length, 2 );
    admin.listeners.click();
    assert.deepEqual( app.opened, [ [ '/admin', '_blank', 'noopener,noreferrer' ] ] );
    sync.listeners.click();
    sync.listeners.click();
    assert.equal( app.requests.length, 1 );
    assert.deepEqual( app.syncQueues, [ { ids: [ '11' ], globalSync: false, action: undefined } ] );
    assert.equal( app.popups[ 0 ].progressMax, 1 );
    assert.equal( sync.getAttribute( 'aria-disabled' ), 'true' );
    assert.equal( app.sync.rowMap.get( '28' ).style.display, 'none' );
    assert.equal( app.sync.rowMap.get( '11' ).style.display, '' );
    assert.equal( app.sync.rowMap.get( '11' ).querySelectorAll( '.content' ).at( -1 ).textContent, 'Alder Home' );
    app.popups[ 0 ].callback();
    app.selectSite( 11 );
    assert.equal( sync.getAttribute( 'aria-disabled' ), 'false' );
} );

test( 'action focus freezes pointer selection and Escape returns to search without closing', async () => {
    const app = fixture( true, true, true );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands() );
    app.render( 'alder' );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    const admin = bar.querySelector( '[data-action="admin"]' );
    admin.focus();
    let stopped = 0;
    app.palette.listeners.pointermove( { target: app.palette.querySelector( '[cmdk-item]' ), stopPropagation() { stopped++; } } );
    assert.equal( stopped, 1 );
    let prevented = 0;
    app.palette.listeners.keydown( { target: admin, key:'Escape', preventDefault() { prevented++; }, stopPropagation() { stopped++; } } );
    assert.equal( app.activeElement, app.input );
    assert.equal( app.closed, 0 );
    assert.equal( prevented, 1 );
} );

test( 'new queries hide old actions immediately and restricted actions are omitted', async () => {
    const app = fixture( true, true, true );
    app.render( 'alder' );
    app.runTimers( 250 );
    const site = siteCommands()[ 0 ];
    delete site.actions.admin;
    await app.respond( 0, [ site ] );
    app.render( 'alder' );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    assert.equal( bar.querySelectorAll( 'button' ).length, 1 );
    assert.equal( bar.querySelector( '[data-action="admin"]' ), null );
    app.render( 'hotel' );
    assert.equal( bar.hidden, true );
    assert.equal( app.input.getAttribute( 'aria-describedby' ), null );
} );


test( 'popup labels are text and server-disallowed Sync stays absent on any page', async () => {
    const app = fixture();
    app.render( 'alder' );
    app.runTimers( 250 );
    const site = siteCommands( 11, 'sync' )[ 0 ];
    site.siteName = '<img src=x onerror=alert(1)>';
    await app.respond( 0, [ site ] );
    app.render( 'alder' ).commands[ 0 ].callback();
    const label = app.sync.rowMap.get( '11' ).querySelectorAll( '.content' ).at( -1 );
    assert.equal( label.textContent, site.siteName );
    assert.equal( label.children.length, 0 );
    const disconnected = fixture();
    disconnected.render( 'alder' );
    disconnected.runTimers( 250 );
    const unavailable = siteCommands();
    delete unavailable[ 0 ].actions.sync;
    await disconnected.respond( 0, unavailable );
    assert.equal( disconnected.render( 'alder' ).commands[ 0 ].site.actions.sync, undefined );
    const absent = fixture();
    absent.render( 'alder' );
    absent.runTimers( 250 );
    unavailable[ 0 ].siteId = 11;
    unavailable[ 0 ].name = 'mainwp/site/11';
    await absent.respond( 0, unavailable );
    assert.equal( absent.render( 'alder' ).commands[ 0 ].site.actions.sync, undefined );
} );

test( 'current server eligibility refreshes a stale disconnected page selection', async () => {
    const app = fixture();
    const row = app.sync.rowMap.get( '28' );
    row.querySelector( '.dashboard_wp_id' ).setAttribute( 'error-status', '1' );
    row.classList.add( 'disconnected-site' );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands( 28, 'sync' ) );
    app.render( 'alder' ).commands[ 0 ].callback();
    assert.deepEqual( app.syncQueues[ 0 ].ids, [ '28' ] );
    assert.equal( row.querySelector( '.dashboard_wp_id' ).getAttribute( 'error-status' ), '0' );
    assert.equal( row.classList.contains( 'disconnected-site' ), false );
} );

test( 'Recent resolves only entity names from the five native history slots', async () => {
    const app = fixture( true, true, true );
    app.setHistory( [ 'existing-menu', 'mainwp/client/1', 'mainwp/site/28', 'mainwp/site/0', 'other/site/3', 'mainwp/site/11' ] );
    app.render( '' );
    app.runTimers( 0 );
    const body = app.requests[ 0 ].options.body;
    assert.equal( body.get( 'search' ), null );
    assert.deepEqual( JSON.parse( body.get( 'recent' ) ), [ 'mainwp/client/1', 'mainwp/site/28' ] );
    await app.respond( 0, [ clientCommand(), ...siteCommands() ] );
    const result = app.render( '' );
    assert.deepEqual( Array.from( result.commands, ( command ) => command.name ), [ 'mainwp/client/1', 'mainwp/site/28' ] );
    app.selectRecentSite( 28 );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    assert.equal( bar.hidden, false );
    assert.equal( bar.querySelectorAll( 'button' ).length, 2 );
} );

test( 'a late Recent response cannot replace an active search', async () => {
    const app = fixture();
    app.setHistory( [ 'mainwp/site/28' ] );
    app.render( '' );
    app.runTimers( 0 );
    app.render( 'new client' );
    app.runTimers( 250 );
    assert.equal( app.requests[ 0 ].options.signal.aborted, true );
    await app.respond( 0, siteCommands() );
    await app.respond( 1, [ clientCommand( 2 ) ] );
    const result = app.render( 'new client' );
    assert.deepEqual( Array.from( result.commands, ( command ) => command.name ), [ 'mainwp/client/2' ] );
    app.setHistory( [ 'existing-menu' ] );
    assert.equal( app.render( '' ).commands.length, 0 );
    app.runTimers( 0 );
    assert.equal( app.requests.length, 2 );
} );

test( 'footer actions record native Recent history without duplicates', async () => {
    const app = fixture( true, true, true );
    app.setHistory( [ 'existing-menu', 'mainwp/site/28' ] );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, siteCommands() );
    app.render( 'alder' );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    bar.querySelector( '[data-action="admin"]' ).listeners.click();
    assert.deepEqual( Array.from( app.history ), [ 'mainwp/site/28', 'existing-menu' ] );
    assert.equal( app.opened.length, 1 );
    bar.querySelector( '[data-action="sync"]' ).listeners.click();
    assert.deepEqual( Array.from( app.history ), [ 'mainwp/site/28', 'existing-menu' ] );
    assert.equal( app.syncCalls.length, 1 );
} );

test( 'client results open their own page and have no site actions', async () => {
    const app = fixture( true, true, true );
    app.render( 'alder' );
    app.runTimers( 250 );
    await app.respond( 0, [ ...siteCommands(), clientCommand() ] );
    const { commands } = app.render( 'alder' );
    assert.equal( commands.length, 2 );
    const bar = app.palette.querySelector( '.mainwp-command-palette-site-actions' );
    assert.equal( bar.hidden, false );
    bar.querySelector( '[data-action="admin"]' ).focus();
    app.selectClient( 1 );
    assert.equal( bar.hidden, true );
    assert.equal( app.input.getAttribute( 'aria-describedby' ), null );
    assert.equal( app.activeElement, app.input );
    const client = commands.find( ( command ) => command.name === 'mainwp/client/1' );
    assert.equal( client.site, undefined );
    client.callback();
    assert.equal( app.closed, 1 );
    assert.deepEqual( app.assigned, [ '/wp-admin/admin.php?page=ManageClients&client_id=1' ] );
    assert.equal( app.syncCalls.length, 0 );
} );

test( 'client-only search works without a sync popup and duplicate names stay distinct', async () => {
    const app = fixture( true, false );
    app.render( 'Alder & Co' );
    app.runTimers( 250 );
    await app.respond( 0, [ clientCommand( 1 ), clientCommand( 2 ) ] );
    const { commands } = app.render( 'Alder & Co' );
    assert.equal( commands.length, 2 );
    assert.notEqual( commands[ 0 ].searchLabel, commands[ 1 ].searchLabel );
    assert.ok( commands[ 0 ].keywords.includes( 'Alder & Co' ) );
    let closed = 0;
    commands[ 1 ].callback( { close: () => closed++ } );
    assert.equal( closed, 1 );
    assert.deepEqual( app.assigned, [ '/wp-admin/admin.php?page=ManageClients&client_id=2' ] );
} );
