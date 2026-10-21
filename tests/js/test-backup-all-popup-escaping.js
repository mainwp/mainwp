'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const { document, loadSiteNameHelper } = require( './fixtures/site-name-dom' );
const test = require( 'node:test' );

const backups = fs.readFileSync( require.resolve( '../../assets/js/mainwp-backups.js' ), 'utf8' );
const managesites = fs.readFileSync( require.resolve( '../../assets/js/mainwp-managesites-update.js' ), 'utf8' );
const mainwp = fs.readFileSync( require.resolve( '../../assets/js/mainwp.js' ), 'utf8' );
const attackName = '<img src=x onerror=alert(1)>';
const escapedName = '&lt;img src=x onerror=alert(1)&gt;';

function sliceFunction( source, marker ) {
    const start = source.indexOf( marker );
    assert.ok( start >= 0, marker );
    const end = source.indexOf( '\n};', start );
    assert.ok( end > start, marker + ' ends' );
    return source.slice( start, end + 3 );
}

function harness( entry, siteName ) {
    const appended = [];
    const requests = [];
    const content = {};
    const context = {
        document,
        ajaxurl: '/admin-ajax.php',
        MainWP: { I18n: { t: text => text } },
        __: text => text,
        mainwp_secure_data: data => data,
        mainwpPopup: () => ( { getContentEl: () => content } ),
        appendToDiv: ( target, html ) => {
            assert.equal( target, content );
            appended.push( html );
        },
        setInterval: () => 1,
        clearInterval: id => assert.equal( id, 1 ),
        jQuery: selector => {
            let text = '';
            let cssClass = '';
            const html = () => text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
            return {
                text( value ) { text = String( value ); return this; },
                addClass( value ) { cssClass = value; return this; },
                html,
                prop( key ) {
                    if ( key === 'outerHTML' ) {
                        assert.equal( selector, '<span>' );
                        return '<span' + ( cssClass ? ' class="' + cssClass + '"' : '' ) + '>' + html() + '</span>';
                    }
                    return this;
                },
                progress() { return this; }
            };
        }
    };
    context[ entry + 'BackupSites' ] = [ { id: 7, name: siteName } ];
    context[ entry + 'BackupError' ] = false;
    context[ entry + 'BackupDownloadRunning' ] = false;
    context.jQuery.post = ( url, data, callback, dataType ) => {
        assert.equal( url, context.ajaxurl );
        assert.equal( dataType, 'json' );
        requests.push( { data, callback } );
    };
    vm.createContext( context );
    loadSiteNameHelper( vm, mainwp, context );
    for ( const name of [ 'getErrorMessage', 'mainwp_backup_error_severity', 'mainwp_backup_error_html' ] ) {
        vm.runInContext( sliceFunction( mainwp, 'globalThis.' + name + ' = ' ), context );
    }
    const source = entry === 'updatesoverview' ? backups : managesites;
    const declaration = entry === 'updatesoverview' ? '' : 'let ';
    for ( const suffix of [ '_backup_run_next', '_backupnow_download_file' ] ) {
        vm.runInContext( sliceFunction( source, declaration + entry + suffix + ' = ' ), context );
    }
    vm.runInContext( 'globalThis.runNext = ' + entry + '_backup_run_next;', context );
    return {
        appended,
        context,
        run: () => context.runNext(),
        respond( action, response ) {
            const request = requests.shift();
            assert.ok( request, action + ' request exists' );
            assert.equal( request.data.action, action );
            assert.equal( request.data.site_id, 7 );
            request.callback( response );
        }
    };
}

function complete( h, outcome, error = 'fixture error' ) {
    h.run();
    if ( outcome === 'creation error' ) {
        h.respond( 'mainwp_backup_run_site', { error } );
        return;
    }
    h.respond( 'mainwp_backup_run_site', {
        result: { type: 'full', url: '/backup.zip', local: 'backup.zip', regexfile: 'backup', size: 100, subfolder: '' }
    } );
    h.respond( 'mainwp_backup_download_file', outcome === 'download error' ? { error } : { result: true } );
    if ( outcome === 'success' ) {
        h.respond( 'mainwp_backup_delete_file', {} );
    }
}

for ( const entry of [ 'updatesoverview', 'managesites' ] ) {
    for ( const [ siteName, escaped ] of [ [ attackName, escapedName ], [ 'Site & Co', 'Site &amp; Co' ], [ 'A &lt; B', 'A &lt; B' ] ] ) {
        for ( const outcome of [ 'success', 'creation error', 'download error' ] ) {
            test( entry + ': ' + outcome + ' escapes every prefix for ' + siteName, () => {
                const h = harness( entry, siteName );
                complete( h, outcome );
                const siteLines = h.appended.filter( html => html.startsWith( '[' ) );
                const expectedCount = outcome === 'creation error' ? 2 : 5;
                assert.equal( siteLines.length, expectedCount );
                assert.equal( h.appended.length, expectedCount + 1 );
                for ( const html of siteLines ) {
                    assert.ok( html.startsWith( '[' + escaped + '] ' ), html );
                    assert.doesNotMatch( html, /<img\b|<b>/i );
                    assert.ok( ! html.includes( '&amp;lt;' ), html );
                }
                assert.ok( siteLines[ 0 ].endsWith( 'Creating backup file...' ) );
                if ( outcome !== 'creation error' ) {
                    assert.ok( siteLines[ 1 ].endsWith( 'Backup file created successfully!' ) );
                    assert.ok( siteLines[ 2 ].includes( '<div id="' + entry + '-backupnow-status-progress" siteId="7" class="ui green progress">' ) );
                }
                if ( outcome === 'success' ) {
                    assert.ok( siteLines[ 3 ].endsWith( 'Download from the child site completed.' ) );
                    assert.ok( siteLines[ 4 ].endsWith( 'Backup completed.' ) );
                    assert.equal( h.context[ entry + 'BackupError' ], false );
                } else {
                    assert.ok( siteLines.some( html => html.includes( 'fixture error</span>' ) ) );
                    assert.equal( h.context[ entry + 'BackupError' ], true );
                }
            } );
        }
    }
}

for ( const outcome of [ 'creation error', 'download error' ] ) {
    for ( const [ error, escaped ] of [
        [ { message: 'WPERROR', extra: '<img src=x onerror=alert(2)>' }, '&lt;img src=x onerror=alert(2)&gt;' ],
        [ { error: '<b>x</b>' }, '&lt;b&gt;x&lt;/b&gt;' ]
    ] ) {
        test( 'managesites: ' + outcome + ' escapes Child error ' + JSON.stringify( error ), () => {
            const h = harness( 'managesites', 'Site & Co' );
            complete( h, outcome, error );
            for ( const html of h.appended ) {
                assert.doesNotMatch( html, /<img\b|<b>/i );
            }
            const errorLine = h.appended.find( html => html.includes( escaped ) );
            assert.ok( errorLine, h.appended.join( '\n' ) );
            assert.ok( errorLine.startsWith( '[Site &amp; Co] ' ), errorLine );
            assert.ok( errorLine.endsWith( '</span>' ), errorLine );
            assert.ok( errorLine.includes( outcome === 'creation error' ? '<span class="mainwp-red">Error: ' : '<span class="error">' ), errorLine );
        } );
    }
}
