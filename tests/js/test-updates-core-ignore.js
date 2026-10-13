'use strict';

// Runs the core-ignore actions from mainwp-updates.js against a recording
// jQuery stub, so the row markup after a server reply can be checked without a browser.

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/js/mainwp-updates.js' ), 'utf8' );

function load() {
    const calls = [];
    const posts = [];
    const chain = ( sel ) => new Proxy( function () {}, {
        get( target, prop ) {
            if ( 'then' === prop || 'symbol' === typeof prop ) {
                return undefined;
            }
            return ( ...args ) => {
                calls.push( { sel, prop, args } );
                return chain( sel );
            };
        },
        apply() {
            return chain( sel );
        },
        set() {
            return true;
        },
    } );
    const jQuery = new Proxy( function () {}, {
        get( target, prop ) {
            if ( 'post' === prop ) {
                return ( url, data, callback ) => posts.push( { data, callback } );
            }
            if ( 'symbol' === typeof prop ) {
                return undefined;
            }
            return chain( 'jQuery.' + prop );
        },
        apply( target, thisArg, args ) {
            return chain( 'string' === typeof args[ 0 ] ? args[ 0 ] : 'row' );
        },
        set() {
            return true;
        },
    } );
    const context = vm.createContext( {
        jQuery,
        ajaxurl: '/wp-admin/admin-ajax.php',
        mainwp_secure_data: ( data ) => data,
        getErrorMessage: ( error ) => 'ERROR: ' + error,
        MainWP: { I18n: { t: ( text ) => text } },
        console,
    } );
    context.globalThis = context;
    context.window = context;
    vm.runInContext( source, context );
    return { context, calls, posts };
}

const refusal = { error: 'You do not have sufficient permissions to access this page (Ignore/Unignore Updates).' };

const cases = [
    { name: 'per site, this version', call: 'updatesoverview_upgrade_ignore( 5, {}, "6.8" )' },
    { name: 'per site, all versions', call: 'updatesoverview_upgrade_ignore_all_version( 5, {} )' },
    { name: 'global, this version', call: 'updatesoverview_upgrade_ignore_this_version_globally( "6.8" )' },
];

for ( const item of cases ) {
    for ( const [ label, response, ignored ] of [ [ 'refused', refusal, false ], [ 'saved', { success: 1 }, true ] ] ) {
        const { context, calls, posts } = load();
        calls.length = 0;
        vm.runInContext( item.call, context );
        assert.equal( posts.length, 1, item.name );
        assert.equal( posts[ 0 ].data.action, 'mainwp_updates_ignore_upgrades', item.name );
        posts[ 0 ].callback( response );

        const html = calls.filter( ( c ) => 'html' === c.prop ).map( ( c ) => c.args[ 0 ] ).join( '\n' );
        const skipped = calls.some( ( c ) => 'attr' === c.prop && 'updated' === c.args[ 0 ] && '-1' === c.args[ 1 ] );
        if ( ignored ) {
            assert.match( html, /check green icon/, `${ item.name } ${ label }: shows the ignored mark` );
        } else {
            assert.doesNotMatch( html, /check green icon/, `${ item.name } ${ label }: a refusal must not show the ignored mark` );
            assert.ok( html.includes( refusal.error ), `${ item.name } ${ label }: shows the refusal message` );
            assert.equal( skipped, false, `${ item.name } ${ label }: a refusal must not drop the row from bulk updates` );
        }
    }
}

console.log( 'test-updates-core-ignore: ok' );
