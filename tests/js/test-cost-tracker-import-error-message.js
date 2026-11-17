'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const source = fs.readFileSync( require.resolve( '../../modules/cost-tracker/ui/js/cost-tracker-import.js' ), 'utf8' );

function harness() {
    const log = [];
    let callback = null;
    const row = {
        'encoded-data': JSON.stringify( { 'cost.name': 'Hosting', 'cost.price': '10' } ),
        original: 'Hosting,10',
    };
    const element = selector => ( {
        ready() {},
        on() {},
        hide() { return this; },
        show() { return this; },
        val() { return '1'; },
        attr( name ) { return selector.startsWith( '#mainwp_managecosts_import_csv_line_' ) ? row[ name ] : this; },
        append( html ) {
            if ( selector === '#mainwp_cost_tracker_import_logging .log' ) {
                log.push( html );
            }
            return this;
        },
        html() { return ''; },
        height() { return 0; },
        scrollTop() { return this; },
    } );
    const jQuery = selector => {
        if ( selector === '<span>' ) {
            let text = '';
            return {
                text( value ) { text = String( value ); return this; },
                html() { return text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); },
            };
        }
        return element( typeof selector === 'string' ? selector : '' );
    };
    jQuery.post = ( url, data, cb ) => { callback = cb; };
    const context = { jQuery, document: {}, ajaxurl: '/admin-ajax.php', mainwp_secure_data: data => data, location: {}, setTimeout() {} };
    vm.createContext( context );
    vm.runInContext( source, context );
    vm.runInContext( 'import_cost_total = 1; mainwp_cost_tracker_import_cost();', context );
    return { log, respond: response => callback( response ) };
}

test( 'a failed import row logs the server message, not [object Object]', () => {
    const { log, respond } = harness();
    respond( { success: false, data: { message: 'Cost name is required' } } );
    const error = log.find( line => line.includes( 'ERROR:' ) );
    assert.ok( error, 'an error line is logged' );
    assert.ok( error.includes( 'Cost name is required' ), error );
    assert.ok( ! error.includes( '[object Object]' ), error );
} );

test( 'the server message is escaped before it reaches the log', () => {
    const { log, respond } = harness();
    respond( { success: false, data: { message: '<img src=x onerror=alert(1)>' } } );
    const error = log.find( line => line.includes( 'ERROR:' ) );
    assert.ok( error.includes( '&lt;img src=x onerror=alert(1)&gt;' ), error );
} );

test( 'a response without a message falls back to the generic text', () => {
    const { log, respond } = harness();
    respond( { success: false } );
    const error = log.find( line => line.includes( 'ERROR:' ) );
    assert.ok( error.includes( 'Unknown error occurred.' ), error );
} );
