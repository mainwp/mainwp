'use strict';

const entities = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const decodeEntities = value => String( value ).replace( /&(#x[\da-f]+|#\d+|amp|lt|gt|quot|apos);/gi, ( match, entity ) => {
    if ( entity.startsWith( '#' ) ) {
        const hex = entity[ 1 ].toLowerCase() === 'x';
        return String.fromCodePoint( parseInt( entity.slice( hex ? 2 : 1 ), hex ? 16 : 10 ) );
    }
    return entities[ entity.toLowerCase() ];
} );
const escapeText = value => String( value ?? '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
const escapeHtml = value => escapeText( value ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );

const document = {
    createElement( tag ) {
        let value = '';
        if ( tag === 'textarea' ) {
            return {
                set innerHTML( html ) { value = decodeEntities( html ); },
                get value() { return value; }
            };
        }
        if ( tag === 'div' || tag === 'span' ) {
            return {
                set innerHTML( html ) { throw new Error( 'HTML parsing requires a textarea fixture' ); },
                get innerHTML() { return escapeText( value ); },
                set textContent( text ) { value = String( text ?? '' ); }
            };
        }
        throw new Error( 'Unsupported fixture element: ' + tag );
    }
};

// Inert parse to text, as the browser's DOMParser does for a text/html document body.
class DOMParser {
    parseFromString( html ) {
        return { body: { textContent: decodeEntities( String( html ).replace( /<[^>]*>/g, '' ) ) } };
    }
}

function loadSiteNameHelper( vm, source, context ) {
    for ( const name of [ 'mainwp_percent_decode', 'mainwp_entities_text', 'mainwp_html_escape', 'mainwp_text_html', 'mainwp_site_name_html', 'mainwp_site_name_text' ] ) {
        const start = source.indexOf( 'globalThis.' + name + ' = ' );
        if ( start >= 0 ) vm.runInContext( source.slice( start, source.indexOf( '\n};', start ) + 3 ), context );
    }
}

module.exports = { document, DOMParser, decodeEntities, escapeHtml, loadSiteNameHelper };
