'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );
const load = name => {
    const start = source.indexOf( name + ' = function' );
    assert.ok( start >= 0, name + ' must exist in mainwp-backups.js.' );
    const end = source.indexOf( '\n};', start ) + '\n};'.length;
    vm.runInContext( source.slice( start, end ), context );
};
const t = ( text, ...args ) => args.reduce( ( out, value, i ) => out.replace( '%' + ( i + 1 ), String( value ) ), text );
const posts = [];
const shown = [];
const timers = [];
let confirmed = null;
const context = {
    MainWP: { I18n: { t } },
    ajaxurl: '/wp-admin/admin-ajax.php',
    location: { reloads: 0, reload() { this.reloads++; } },
    setTimeout: ( fn, ms ) => timers.push( { fn, ms } ),
    mainwp_confirm: ( msg, cb ) => { confirmed = { msg, cb }; },
    mainwp_secure_data: data => Object.assign( { security: 'nonce' }, data ),
    managebackups_show_message: ( text, color ) => shown.push( { text, color } ),
    jQuery: Object.assign( el => ( { attr: name => el[ name ] } ), {
        post: ( url, data, cb, type ) => posts.push( { url, data, cb, type } )
    } )
};
vm.createContext( context );
load( 'managebackups_cancel_run_message' );
load( 'managebackups_cancel_run' );

const message = response => JSON.parse( JSON.stringify( context.managebackups_cancel_run_message( response ) ) );
assert.deepEqual( message( { result: 'cancelled' } ), { text: 'Run cancelled.', color: 'green' } );
assert.deepEqual( message( { result: 'cancelling', waiting: 2 } ), { text: 'Cancel requested. Waiting for 2 site(s) to confirm they stopped.', color: 'yellow' } );
assert.deepEqual( message( { result: 'cancelling' } ), { text: 'Cancel requested. Waiting for the run to close.', color: 'yellow' }, 'A missing count never prints zero sites.' );
assert.deepEqual( message( { result: 'cancelling', waiting: 0 } ), { text: 'Cancel requested. Waiting for the run to close.', color: 'yellow' }, 'Zero waiting sites means the close is held elsewhere.' );
assert.equal( message( { result: 'not_confirmed' } ).color, 'yellow', 'A deadline close is never shown as a clean cancel.' );
assert.match( message( { result: 'not_confirmed' } ).text, /did not confirm/ );
assert.deepEqual( message( { error: 'This run already ended.' } ), { text: 'This run already ended.', color: 'red' } );
assert.equal( message( { error: 'x', result: 'cancelled' } ).color, 'red', 'An error wins over any result.' );
console.log( 'OK (8 cancel run message cases)' );

assert.equal( context.managebackups_cancel_run( { task_id: '12', run: '1700000000' } ), false, 'The link never navigates.' );
assert.equal( posts.length, 0, 'Nothing is sent before the confirmation.' );
assert.match( confirmed.msg, /^Cancel this backup run\?/ );
confirmed.cb();
assert.equal( posts.length, 1 );
assert.deepEqual( { ...posts[ 0 ].data }, { security: 'nonce', action: 'mainwp_native_backup_task_cancel_run', task_id: '12', run: '1700000000' }, 'The request carries the run the page showed.' );
assert.equal( posts[ 0 ].type, 'json' );
posts[ 0 ].cb( { error: 'This task is not currently running.' } );
assert.deepEqual( { ...shown.pop() }, { text: 'This task is not currently running.', color: 'red' } );
assert.equal( timers.length, 0, 'A refusal keeps the page so the message stays readable.' );
posts[ 0 ].cb( { result: 'cancelled' } );
assert.equal( timers.pop().ms, 1500 );
posts[ 0 ].cb( { result: 'cancelling', waiting: 1 } );
const pending = timers.pop();
assert.equal( pending.ms, 5000 );
pending.fn();
assert.equal( context.location.reloads, 1 );
console.log( 'OK (9 cancel run request cases)' );
