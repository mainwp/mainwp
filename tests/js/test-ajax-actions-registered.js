'use strict';

// A JS caller posting to an action with no PHP handler gets admin-ajax's "0" and
// shows it as an error or as page content, so every literal action name must be
// registered somewhere in the plugin's PHP.

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const root = path.join( __dirname, '../..' );

function files( dir, ext ) {
    const out = [];
    for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
        // nosemgrep: AIK_ts_generic_path_traversal -- paths come from readdir under the repository root, not from input.
        const full = path.join( dir, entry.name );
        if ( entry.isDirectory() ) {
            if ( ! [ 'node_modules', 'vendor', 'libs' ].includes( entry.name ) ) {
                out.push( ...files( full, ext ) );
            }
        } else if ( entry.name.endsWith( ext ) && ! entry.name.endsWith( '.min.js' ) ) {
            out.push( full );
        }
    }
    return out;
}

test( 'every literal AJAX action posted from assets/js has a PHP registration', () => {
    // nosemgrep: AIK_ts_generic_path_traversal -- paths come from readdir under the repository root, not from input.
    const php = [ 'class', 'pages', 'modules', 'widgets', 'includes' ]
        .flatMap( ( dir ) => files( path.join( root, dir ), '.php' ) )
        // nosemgrep: AIK_ts_generic_path_traversal -- paths come from readdir under the repository root, not from input.
        .concat( path.join( root, 'mainwp.php' ) )
        .map( ( file ) => fs.readFileSync( file, 'utf8' ) )
        .join( '\n' );

    const orphans = [];
    // nosemgrep: AIK_ts_generic_path_traversal -- paths come from readdir under the repository root, not from input.
    for ( const file of fs.readdirSync( path.join( root, 'assets/js' ) ).filter( ( name ) => name.endsWith( '.js' ) && ! name.endsWith( '.min.js' ) ) ) {
        // nosemgrep: AIK_ts_generic_path_traversal -- paths come from readdir under the repository root, not from input.
        const source = fs.readFileSync( path.join( root, 'assets/js', file ), 'utf8' );
        // A literal followed by "+" is a prefix completed at runtime, not an action name.
        for ( const match of source.matchAll( /['"]?action['"]?\s*:\s*(['"])(mainwp_[a-z0-9_]+)\1(?!\s*\+)/g ) ) {
            const name = match[ 2 ];
            if ( ! php.includes( `'${ name }'` ) && ! php.includes( `'wp_ajax_${ name }'` ) ) {
                orphans.push( `${ file }: ${ name }` );
            }
        }
    }
    assert.deepEqual( [ ...new Set( orphans ) ], [] );
} );
