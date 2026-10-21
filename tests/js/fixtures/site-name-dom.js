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
        return {
            set innerHTML( html ) { value = decodeEntities( html ); },
            get innerHTML() { return escapeText( value ); },
            set textContent( text ) { value = String( text ?? '' ); },
            get value() { return value; }
        };
    }
};

// Inert parse to text, as the browser's DOMParser does for a text/html document body.
class DOMParser {
    parseFromString( html ) {
        return { body: { textContent: decodeEntities( String( html ).replace( /<[^>]*>/g, '' ) ) } };
    }
}

function loadSiteNameHelper( vm, source, context ) {
    const start = source.indexOf( 'globalThis.mainwp_site_name_html = ' );
    if ( start >= 0 ) vm.runInContext( source.slice( start, source.indexOf( '\n};', start ) + 3 ), context );
}

module.exports = { document, DOMParser, decodeEntities, escapeHtml, loadSiteNameHelper };
