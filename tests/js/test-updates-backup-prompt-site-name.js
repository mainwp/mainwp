'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const updates = fs.readFileSync( require.resolve( '../../assets/js/mainwp-updates.js' ), 'utf8' );

// The per-site Updates view renders each site as a table row, so a lookup
// bound to div elements left the backup prompt without the site's name.
for ( const name of [ 'updatesoverview_translations_upgrade_int', 'updatesoverview_plugins_upgrade_int' ] ) {
    test( name + ' reads the site name from any element carrying it', () => {
        const start = updates.indexOf( 'let ' + name + ' = function' );
        assert.ok( start >= 0, name );
        const body = updates.slice( start, updates.indexOf( '\n};', start ) );
        assert.match( body, /siteNames\[websiteId\] = jQuery\('\[site_id="' \+ websiteId \+ '"\]\[site_name\]'\)\.first\(\)\.attr\('site_name'\)/ );
        assert.doesNotMatch( body, /jQuery\('div\[site_id=/ );
    } );
}
