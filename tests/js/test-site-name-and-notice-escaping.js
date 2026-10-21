'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const test = require( 'node:test' );

const managesites = fs.readFileSync( require.resolve( '../../assets/js/mainwp-managesites-update.js' ), 'utf8' );
const mainwp = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );

function sliceFunction( source, marker ) {
    const start = source.indexOf( marker );
    assert.ok( start >= 0, marker );
    const end = source.indexOf( '\n}', start );
    assert.ok( end > start, marker + ' ends' );
    return source.slice( start, end + 2 );
}

function escapeText( value ) {
    return String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
}

function decodeEntities( value ) {
    const entities = { amp: '&', lt: '<', gt: '>', quot: '"' };
    return value.replace( /&(amp|lt|gt|quot);/g, ( match, entity ) => entities[ entity ] );
}

function harness() {
    let output = '';
    const context = {
        MainWP: { I18n: { t: text => text } },
        mainwp_get_primaryBackup_link: method => '/backup/' + method,
        mainwpPopup: () => ( { getContentEl: () => ( { html: value => { output = value; } } ) } ),
        jQuery: selector => {
            let text = '';
            let icon = '';
            const attributes = new Map();
            const stored = new Map();
            return {
                text( value ) { text = value; return this; },
                html() { return escapeText( text ); },
                attr( key, value ) { attributes.set( key, value ); return this; },
                append( value ) { icon += value; return this; },
                prop( key ) {
                    assert.equal( key, 'outerHTML' );
                    assert.equal( selector, '<span data-inverted="" data-position="left center">' );
                    const attrs = [ ...attributes ].map( ( [ name, value ] ) => ' ' + name + '="' + escapeText( value ).replace( /"/g, '&quot;' ) + '"' ).join( '' );
                    return selector.slice( 0, -1 ) + attrs + '>' + escapeText( text ) + icon + '</span>';
                },
                data( key, ...values ) {
                    if ( values.length === 0 ) return stored.get( key );
                    stored.set( key, values[ 0 ] );
                    return this;
                },
                val( ...values ) { return values.length === 0 ? 'Ignore' : this; },
                hide() { return this; },
                show() { return this; }
            };
        }
    };
    vm.createContext( context );
    vm.runInContext( sliceFunction( managesites, 'globalThis.mainwp_managesites_prepare_backup_popup = ' ), context );
    for ( const name of [ 'getErrorMessage', 'getErrorMessageInfo' ] ) {
        vm.runInContext( sliceFunction( mainwp, 'globalThis.' + name + ' = ' ), context );
    }
    return { context, output: () => output };
}

for ( const [ branch, result, native ] of [
    [ 'native-missing', { gate_verdicts: { 7: 'missing' } }, true ],
    [ 'plugin-backup', { primary_backup: 'example' }, false ],
    [ 'pending-offsite', { gate_verdicts: { 7: 'pending_offsite' } }, false ]
] ) {
    for ( const [ name, escaped ] of [
        [ '<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;' ],
        [ 'Site & Co', 'Site &amp; Co' ]
    ] ) {
        test( branch + ': popup renders the decoded site name as text: ' + name, () => {
            const h = harness();
            h.context.mainwp_managesites_prepare_backup_popup( { result }, { 7: encodeURIComponent( name ) }, [ 7 ] );
            const html = h.output();
            assert.ok( html.includes( escaped ), html );
            assert.doesNotMatch( html, /<img\b/i );
            const opening = native ? '<span class="managesites-backup-site" siteid="7">' : '<span>';
            assert.ok( html.includes( opening + escaped + '</span>' ), html );
            const content = html.slice( html.indexOf( opening ) + opening.length ).split( '</span>' )[ 0 ];
            assert.equal( decodeEntities( content ), name );
        } );
    }
}

for ( const [ branch, error, icon ] of [
    [ 'MAINWP_NOTICE', { errorCode: 'MAINWP_NOTICE', message: '"><img src=x onerror=alert(2)>' }, 'pause circular yellow inverted icon' ],
    [ 'generic', { message: '"><b>x</b>' }, 'red times icon' ],
    [ 'MAINWP_NOTICE entities', { errorCode: 'MAINWP_NOTICE', message: '&quot; " < > &' }, 'pause circular yellow inverted icon' ],
    [ 'generic entities', { message: '&quot; " < > &' }, 'red times icon' ]
] ) {
    test( branch + ': tooltip keeps the message inside its attribute and plain output unchanged', () => {
        const h = harness();
        assert.equal( h.context.getErrorMessageInfo( error ), error.message );
        const html = h.context.getErrorMessageInfo( error, 'ui' );
        assert.doesNotMatch( html, /<img\b|<b\b/i );
        const span = html.match( /^<span data-inverted="" data-position="left center" data-tooltip="([^"]*)"><i class="([^"]*)"><\/i><\/span>$/ );
        assert.ok( span, html );
        assert.equal( decodeEntities( span[ 1 ] ), error.message );
        assert.equal( span[ 2 ], icon );
    } );
}
