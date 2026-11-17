'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( 'assets/js/mainwp-mintlify-chat.js', 'utf8' );
const start = source.indexOf( '    function parseMarkdown(' );
const end = source.indexOf( '\n    }', start ) + 6;
assert.ok( start > 0 && end > start, 'Production markdown parser exists' );
const escapeStart = source.indexOf( '    function escapeHtml(' );
const escapeEnd = source.indexOf( '\n    }', escapeStart ) + 6;
assert.ok( escapeStart > 0 && escapeEnd > escapeStart, 'Production escaping helper exists' );
const context = {};
vm.createContext( context );
vm.runInContext( source.slice( escapeStart, escapeEnd ), context );
vm.runInContext( source.slice( start, end ), context );
const render = text => context.parseMarkdown( text );
const expected = '<ol><li>First</li><li>Second</li><li>Third</li></ol>';
for ( const separator of [ '\n', '\n\n', '\n \t\n\n', '\r\n', '\r\n\r\n' ] ) {
    for ( const markers of [ [ 1, 2, 3 ], [ 1, 1, 1 ] ] ) {
        const input = [ 'First', 'Second', 'Third' ].map( ( item, index ) => markers[ index ] + '. ' + item ).join( separator );
        assert.equal( render( input ), expected, JSON.stringify( input ) );
    }
}
assert.equal( render( '1. First\n\n2. Second' ), '<ol><li>First</li><li>Second</li></ol>' );
for ( const detail of [ 'Paragraph explaining the step.', '- Activity logs\n- Update history', '   - Activity logs\n   - Update history' ] ) {
    const html = render( '1. First\n' + detail + '\n\n2. Second\n' + detail + '\n\n3. Third' );
    assert.deepEqual( Array.from( html.matchAll( /<ol(?: start="(\d+)")?>/g ), match => Number( match[ 1 ] || 1 ) ), [ 1, 2, 3 ], detail );
    assert.ok( ! html.includes( '2. Second' ), 'Second item is rendered as a list item' );
}
assert.equal( render( '4. First\n5. Second' ), '<ol start="4"><li>First</li><li>Second</li></ol>' );
assert.equal( render( '1. First\n2. Second\n\n1. Third\n2. Fourth' ), '<ol><li>First</li><li>Second</li><li>Third</li><li>Fourth</li></ol>' );
assert.equal( render( '```\n1. a\n\n2. b\n```' ), '<pre><code><br>1. a<br><br>2. b<br></code></pre>' );
for ( const boundary of [ 'Paragraph', '## Heading', '- Bullet' ] ) {
    const html = render( '1. First\n\n' + boundary + '\n\n1. Second' );
    assert.equal( ( html.match( /<ol>/g ) || [] ).length, 2, boundary + ' separates lists' );
    assert.equal( ( html.match( /<li>/g ) || [] ).length, boundary === '- Bullet' ? 3 : 2 );
}
assert.equal( render( '1. **First**\n\n2. <script>alert(1)</script>' ), '<ol><li><strong>First</strong></li><li>&lt;script&gt;alert(1)&lt;/script&gt;</li></ol>' );
assert.equal( render( '- First\n- Second' ), '<ul><li>First</li><li>Second</li></ul>' );
assert.ok( render( '1. First\n- Bullet\n- Second' ).includes( '<ul><li>Bullet</li><li>Second</li></ul>' ), 'Following bullet list keeps both items' );
assert.equal( render( '1. First\n\nParagraph' ), '<ol><li>First</li></ol><br>Paragraph' );
console.log( 'Ordered list rendering assertions passed' );
