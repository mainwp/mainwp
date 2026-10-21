'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );
const { document, DOMParser, decodeEntities, escapeHtml, loadSiteNameHelper } = require( './fixtures/site-name-dom' );
const updates = fs.readFileSync( require.resolve( '../../assets/js/mainwp-updates.js' ), 'utf8' );
const mainwp = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
const attack = '<img src=x onerror=alert(1)>';
const cases = [
    [ 'encoded stored entity', encodeURIComponent( '&lt;img src=x onerror=alert(1)&gt;' ), attack ],
    [ 'legacy stored entity', '&lt;img src=x onerror=alert(1)&gt;', attack ],
    [ 'literal tag', attack, attack ],
    [ 'encoded tag', '%3Cimg src=x onerror=alert(1)%3E', attack ],
    [ 'double encoded tag', '%253Cimg src=x onerror=alert(1)%253E', '%3Cimg src=x onerror=alert(1)%3E' ],
    [ 'entity display', 'A &lt; B', 'A < B' ],
    [ 'literal percent', '100% uptime', '100% uptime' ],
    [ 'quotes', 'O\'Brien "Q"', 'O\'Brien "Q"' ],
    [ 'attribute breakout', '"><img src=x onerror=alert(1)>', '"><img src=x onerror=alert(1)>' ]
];

function load( context, source, name ) {
    const marker = new RegExp( '(?:globalThis\\.|let |const )' + name + ' = function' );
    const start = source.search( marker );
    assert.ok( start >= 0, name );
    const end = source.indexOf( '\n}', start );
    vm.runInContext( source.slice( start, end + 2 ) + ';\nglobalThis.' + name + ' = ' + name + ';', context );
}

function harness( raw ) {
    const html = [];
    const confirms = [];
    const titles = [];
    let post;
    let ajax;
    const attrs = { site_id: '7', site_name: raw, 'history-type': 'plugin', plugin_slug: 'fixture', theme_slug: 'fixture', translation_slug: 'fixture' };
    const node = {
        length: 1,
        *[ Symbol.iterator ]() { yield this; },
        attr( key, value ) { if ( arguments.length === 2 ) { attrs[ key ] = value; return this; } return attrs[ key ]; },
        parent() { return this; }, closest() { return this; }, find() { return this; },
        val() { return 0; }, exists() { return true; }, remove() { return this; },
        off() { return this; }, on() { return this; }, accordion() { return this; },
        html( value ) { if ( arguments.length ) html.push( value ); return this; },
        append( value ) { html.push( value ); return this; },
        text() { return this; }
    };
    const jq = ( selector, options ) => {
        if ( selector === '<span>' ) {
            let text = '';
            return { text( value ) { text = value; return this; }, html() { return escapeHtml( text ); } };
        }
        if ( selector === '<div>' ) { html.push( options.html ); return node; }
        return node;
    };
    jq.each = ( values, fn ) => Object.entries( values ).forEach( ( [ key, value ] ) => fn( key, value ) );
    jq.post = ( url, data, callback ) => { post = callback; return { fail() { return this; }, always() { return this; } }; };
    jq.ajax = options => { ajax = options; };
    const context = {
        document, DOMParser, jQuery: jq, ajaxurl: '/ajax',
        MainWP: { I18n: { t: ( text, ...args ) => text.replace( /%(\d)/g, ( match, number ) => args[ number - 1 ] ) } },
        mainwpVars: { bulkTaskRunning: false, errorCount: 0 },
        limitUpdateAll: 0, continueUpdating: false,
        mainwp_links_visit_site_and_admin: () => '',
        mainwp_confirm: ( message, callback ) => { confirms.push( message ); context.confirmCallback = callback; },
        mainwpPopup: () => ( { clearList() {}, appendItemsList( value ) { html.push( value ); } } ),
        mainwp_secure_data: data => data,
        mainwp_updatesoverview_checkBackups: () => { context.updatesoverviewContinueAfterBackup?.(); },
        updatesoverview_update_popup_init: options => titles.push( options.title ),
        updatesoverview_wordpress_upgrade_all_update_site_status: ( id, value ) => html.push( value ),
        updatesoverview_wordpress_upgrade_all_update_done() {},
        dashboard_update_site_status: ( id, value ) => html.push( value ),
        mainwp_tool_disconnect_sites_done() {},
        render_html_regression_waiting_icon: () => '',
        render_tooltip_loading_icon: value => value,
        upgradeall_update_site_popup_multi_status() {},
        updatesoverview_global_upgrade_all_after_backup: () => () => {},
        updatesoverview_plugins_upgrade_all_update_done() {},
        updatesoverview_plugins_upgrade_all_update_site_status: ( id, value ) => html.push( value ),
        pluginChangesLoadData: { slug: 'fixture' }, themeChangesLoadData: {},
        get_icon_history_event: () => 'check', get_color_changes_event: () => 'green'
    };
    for ( const type of [ 'wordpress', 'plugins', 'themes', 'translations' ] ) {
        context[ 'updatesoverview_' + type + '_get_global_upgrade_all' ] = () => [ node ];
        context[ 'updatesoverview_' + type + '_get_upgrade_all' ] = () => [ node ];
        context[ 'updatesoverview_' + type + '_upgrade_all_int' ] = () => {};
    }
    vm.createContext( context );
    loadSiteNameHelper( vm, mainwp, context );
    for ( const name of [ 'mainwp_escape_html', 'mainwp_decode_slug', 'mainwp_tooltip_text' ] ) load( context, updates, name );
    load( context, updates, 'updatesmanage_link_to_site' );
    return { context, html, confirms, titles, node, respond: value => post( value ), ajax: () => ajax };
}

function assertSafe( html ) {
    assert.doesNotMatch( html, /<img\b/i, html );
    assert.doesNotMatch( html, /["']\s+onerror\s*=/i, html );
}

const confirmRows = [
    [ 'WP core', 'updatesoverview_wordpress_global_upgrade_all' ],
    [ 'plugins', 'updatesoverview_plugins_global_upgrade_all' ],
    [ 'themes', 'updatesoverview_themes_global_upgrade_all' ],
    [ 'translations', 'updatesoverview_translations_global_upgrade_all' ],
    [ 'one plugin', 'updatesoverview_plugins_upgrade_all' ],
    [ 'one theme', 'updatesoverview_themes_upgrade_all' ],
    [ 'one translation', 'updatesoverview_translations_upgrade_all' ],
    [ 'global run', 'updatesoverview_global_upgrade_all_start' ]
];

for ( const [ label, raw, display ] of cases ) {
    test( 'helper: ' + label, () => {
        const h = harness( raw );
        const html = h.context.mainwp_site_name_html( raw );
        assert.equal( html, escapeHtml( display ) );
        assertSafe( html );
    } );
    test( 'site link: ' + label, () => {
        const h = harness( raw );
        const html = h.context.updatesmanage_link_to_site( raw, '7" onclick="alert(2)' );
        assert.equal( html, '<a href="admin.php?page=managesites&dashboard=7">' + escapeHtml( display ) + '</a>' );
        assertSafe( html );
    } );
    for ( const [ row, name ] of confirmRows ) {
        test( row + ' confirm: ' + label, () => {
            const h = harness( raw );
            load( h.context, updates, name );
            if ( row === 'global run' ) h.context[ name ]( 'all', { 7: raw }, () => {} );
            else h.context[ name ]( 'fixture', 'Fixture' );
            assert.equal( h.confirms.length, 1 );
            assert.ok( h.confirms[ 0 ].includes( escapeHtml( display ) ), h.confirms[ 0 ] );
            assertSafe( h.confirms[ 0 ] );
        } );
    }
    for ( const [ row, name ] of [ ...confirmRows.slice( 0, -1 ), [ 'global run', 'updatesoverview_global_run_upgrade_all' ], [ 'item list', 'updatesoverview_upgrade_plugintheme_list_popup' ] ] ) {
        test( row + ' popup site link: ' + label, () => {
            const h = harness( raw );
            h.context.continueUpdating = true;
            load( h.context, updates, name );
            if ( row === 'global run' ) {
                load( h.context, updates, 'updatesoverview_global_upgrade_all_start' );
                h.context[ name ]( 'all' );
                h.context.confirmCallback();
            } else if ( row === 'item list' ) h.context[ name ]( 'plugin', 7, raw, [ 'fixture' ] );
            else h.context[ name ]( 'fixture', 'Fixture' );
            const link = h.html.find( value => value.includes( '<a href=' ) );
            assert.ok( link?.includes( '>' + escapeHtml( display ) + '</a>' ), link );
            assertSafe( link );
        } );
    }
    for ( const type of [ 'plugins', 'themes', 'translations' ] ) {
        test( type + ' Child title and confirm: ' + label, () => {
            const h = harness( 'Site' );
            const name = 'updatesoverview_' + type + '_upgrade_all';
            load( h.context, updates, name );
            h.context[ name ]( 'fixture', raw );
            const decoded = (() => { try { return decodeURIComponent( raw ); } catch { return raw; } })().replaceAll( '+', ' ' );
            assert.ok( h.confirms[ 0 ].includes( escapeHtml( decoded ) ), h.confirms[ 0 ] );
            assertSafe( h.confirms[ 0 ] );
            h.context.confirmCallback();
            assert.equal( h.titles[ 0 ], 'Updating ' + escapeHtml( decoded ) );
            assertSafe( h.titles[ 0 ] );
        } );
    }
    for ( const name of [ 'updatesoverview_plugins_ignore_detail', 'updatesoverview_plugins_ignore_all' ] ) {
        test( name + ': ' + label, () => {
            const h = harness( raw );
            load( h.context, updates, name );
            if ( name.endsWith( '_detail' ) ) h.context[ name ]( 'fixture', raw, 7, h.node );
            else h.context[ name ]( 'fixture', raw, h.node );
            const decoded = (() => { try { return decodeURIComponent( raw ); } catch { return raw; } })();
            assert.ok( h.confirms[ 0 ].includes( escapeHtml( decoded ) ), h.confirms[ 0 ] );
            assert.ok( h.confirms[ 0 ].includes( escapeHtml( display ) ), h.confirms[ 0 ] );
            assertSafe( h.confirms[ 0 ] );
        } );
    }
    test( 'disconnect Child error: ' + label, () => {
        const h = harness( raw );
        load( h.context, mainwp, 'mainwp_tool_disconnect_sites_next_int' );
        h.context.mainwp_tool_disconnect_sites_next_int( 7, {}, 0 );
        h.ajax().success( { error: raw } );
        assert.equal( h.html[ 0 ], escapeHtml( raw ) + '<i class="exclamation red icon"></i>' );
        assertSafe( h.html[ 0 ] );
    } );
    for ( const name of [ 'updatesoverview_upgrade', 'updatesoverview_wordpress_upgrade_int' ] ) {
        test( name + ' error tooltip: ' + label, () => {
            const h = harness( raw );
            load( h.context, updates, name );
            h.context[ name ]( 7, name === 'updatesoverview_upgrade' ? h.node : true );
            if ( name === 'updatesoverview_upgrade' ) h.context.updatesoverviewContinueAfterBackup();
            h.respond( { error: { extra: raw } } );
            const html = h.html.at( -1 );
            assert.ok( html.includes( 'data-tooltip="' + escapeHtml( decodeEntities( String( raw ).replace( /<[^>]*>/g, '' ) ) ) + ' "' ), html );
            assertSafe( html );
        } );
    }
    for ( const field of [ 'date', 'event', 'author_name', 'source', 'old_version', 'version', 'at_hour' ] ) {
        test( 'history ' + field + ': ' + label, () => {
            const h = harness( raw );
            load( h.context, mainwp, 'mainwp_item_changes_load' );
            h.context.mainwp_item_changes_load();
            const record = { date: 'Today', details: { log_id: 1, event: 'Updated', author_name: 'Admin', source: 'Child', old_version: '1', version: '2', at_hour: '12:00' } };
            ( field === 'date' ? record : record.details )[ field ] = raw;
            h.respond( { list: { today: [ record ] } } );
            const html = h.html.find( value => typeof value === 'string' && value.includes( 'ui accordion' ) );
            assert.ok( html.includes( escapeHtml( raw ) ), html );
            assertSafe( html );
        } );
    }
}

test( 'Child names retain plus-to-space display', () => {
    const h = harness( 'Site' );
    load( h.context, updates, 'updatesoverview_plugins_upgrade_all' );
    h.context.updatesoverview_plugins_upgrade_all( 'fixture', 'A+B%2BC' );
    h.context.confirmCallback();
    assert.equal( h.titles[ 0 ], 'Updating A B C' );
} );

for ( const response of [ { notices: { fixture: 'A notice' } }, { error: { message: 'Child error' } } ] ) {
    test( 'plugin response handles ' + ( response.error ? 'error' : 'notices without error' ), () => {
        const h = harness( 'Site' );
        h.context.mainwp_slug_list_to_array = value => [ value ];
        let calls = 0;
        h.context.getErrorMessageInfo = error => {
            calls++;
            assert.ok( error );
            return 'Child error';
        };
        load( h.context, updates, 'updatesoverview_plugins_upgrade_int_after_backup' );
        h.context.updatesoverview_plugins_upgrade_int_after_backup( 'fixture', 7, true )();
        h.ajax().success( response );
        assert.equal( calls, response.error ? 1 : 0 );
        assert.equal( h.html.at( -1 ), response.error ? 'Child error' : '<i class="red times icon"></i>' );
    } );
}
