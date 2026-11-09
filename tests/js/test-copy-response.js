'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( process.env.COPY_SOURCE || 'assets/js/mainwp-mintlify-chat.js', 'utf8' );
const start = source.indexOf( "$messages.off( 'click.mintlify-copy' )" );
const end = source.indexOf( "$( '#mainwp-mintlify-disclosure-dismiss' )", start );

async function scenario( clipboard, command, success ) {
    let handler, copied = false, disabled = false, removed = false, status = '', written;
    const attributes = {};
    const content = { clone() { return this; }, find() { return { remove() {}, replaceWith() {}, append() {} }; }, text() { return ' Target answer '; } };
    const message = { find: () => content };
    const feedback = { text( value ) { status = value; return this; } };
    const temp = {
        css() { return this; }, val( value ) { written = value; return this; },
        appendTo() { return this; }, select() { return this; }, trigger() { return this; },
        remove() { removed = true; }
    };
    const button = {
        data() { return this; },
        closest: () => message,
        addClass() { copied = true; return this; }, removeClass() { copied = false; return this; },
        prop( key, value ) { if ( value === undefined ) { return disabled; } disabled = value; return this; },
        attr( key, value ) { attributes[ key ] = value; return this; },
        siblings: () => ( { length: 0 } ), after() {},
        trigger() { assert.equal( disabled, false, 'Restore focus only after reenabling' ); return this; }
    };
    const doc = { body: {}, execCommand() { assert.equal( written, 'Target answer' ); return command(); } };
    button[0] = { focus() { assert.equal( disabled, false, 'Restore focus after reenabling' ); doc.activeElement = this; } };
    doc.activeElement = button[0];
    let reset;
    const context = {
        $messages: { off() { return this; }, on( event, selector, callback ) { handler = callback; } },
        $: value => value === button ? button : value.includes( 'textarea' ) ? temp : feedback,
        navigator: { clipboard: clipboard && { writeText( text ) { written = text; return clipboard(); } } },
        document: doc,
        l10n: ( key, fallback ) => fallback, setTimeout( callback ) { reset = callback; }, clearTimeout() {},
        Promise
    };
    vm.runInNewContext( source.slice( start, end ), context );
    handler.call( button, { preventDefault() {} } );
    doc.activeElement = doc.body;
    assert.equal( copied, false, 'No success before write settles' );
    await new Promise( resolve => setImmediate( resolve ) );
    assert.equal( written, 'Target answer' );
    assert.equal( copied, success );
    assert.equal( disabled, false );
    assert.equal( doc.activeElement, button[0], 'Restore focus after the asynchronous write' );
    assert.equal( status, success ? 'Copied!' : 'Copy failed. Select the response text and copy it manually.' );
    if ( success ) { reset(); assert.equal( copied, false ); assert.equal( status, '' ); }
    if ( ! clipboard ) { assert.equal( removed, true, 'Temporary field is removed' ); }
    return context;
}

( async () => {
    await scenario( undefined, () => true, true );
    await scenario( () => Promise.resolve(), () => { throw Error( 'Fallback must not run' ); }, true );
    await scenario( () => Promise.reject( Error( 'Denied' ) ), () => true, true );
    await scenario( undefined, () => false, false );
    await scenario( undefined, () => { throw Error( 'Denied' ); }, false );
    await scenario( () => { throw Error( 'Sync denial' ); }, () => false, false );
    console.log( 'Assistant copy: 6 scenarios passed' );
} )().catch( error => { console.error( error ); process.exitCode = 1; } );
