'use strict';
const assert = require( 'node:assert/strict' );
const test = require( 'node:test' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const source = fs.readFileSync( require.resolve( '../../pages/backup/page-mainwp-backup-snapshots-view.php' ), 'utf8' );

function slice( start, end ) {
    const from = source.indexOf( start );
    assert.ok( from >= 0, 'Missing ' + start );
    return source.slice( from, source.indexOf( end, from ) + end.length );
}

function render( uploads ) {
    let content = '';
    let handler;
    const rowNode = {};
    const row = { node: () => rowNode, data: () => ( { uploads } ) };
    row.child = value => { content = value; return { show() {} }; };
    row.child.isShown = () => false;
    const context = {
        uploadLabels: { failed_permanent: [ 'red', 'Failed' ], complete: [ 'green', 'Uploaded' ] },
        snapshotsTable: { row: () => row }
    };
    context.$ = selector => ( {
        attr() { return this; },
        closest() { return rowNode; },
        on( event, name, callback ) { handler = callback; return this; }
    } );
    vm.createContext( context );
    vm.runInContext( slice( 'function escAttr( text ) {', '\n            }' ), context );
    vm.runInContext( slice( "$( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-snapshot-uploads-toggle'", '\n            } );' ), context );
    handler.call( rowNode, { preventDefault() {} } );
    return content;
}

const sftp = 'Could not upload the backup to the SFTP server. Check the SFTP credentials, that the server is reachable, and that the remote folder is writable, then try again.';

test( 'a failed upload shows the friendly text, then its detail and code on a muted line', () => {
    const html = render( [ {
        name: 'Offsite SFTP', provider: 'sftp', status: 'failed_permanent',
        last_error: '[mainwp_backup_sftp_failed] Permission denied',
        error_code: 'mainwp_backup_sftp_failed', error_message: sftp, error_detail: 'Permission denied'
    } ] );
    assert.ok( html.includes( '<span class="ui small basic red label">Failed</span> ' + sftp + '<div><span class="ui small grey text">Permission denied (mainwp_backup_sftp_failed)</span></div>' ), html );
    assert.ok( ! html.includes( '[mainwp_backup_' ), html );
} );

test( 'provider text and codes are escaped as text', () => {
    const html = render( [ {
        name: 'Local', provider: 'local', status: 'failed_permanent', last_error: 'x',
        error_code: 'mainwp_backup_local_failed', error_message: 'Write <b>failed</b>', error_detail: '"><img src=x onerror=alert(1)>'
    } ] );
    assert.ok( html.includes( 'Write &lt;b&gt;failed&lt;/b&gt;' ), html );
    assert.ok( html.includes( '&quot;&gt;&lt;img src=x onerror=alert(1)&gt; (mainwp_backup_local_failed)' ), html );
    assert.ok( ! html.includes( '<img' ), html );
} );

test( 'an error stored without a code shows as before with no muted line', () => {
    const html = render( [ {
        name: 'Gone', provider: 's3', status: 'failed_permanent', last_error: 'Storage destination was deleted.',
        error_code: '', error_message: 'Storage destination was deleted.', error_detail: ''
    } ] );
    assert.ok( html.includes( '</span> Storage destination was deleted.</div>' ), html );
    assert.ok( ! html.includes( 'grey text' ), html );
} );

test( 'a completed upload shows no error text', () => {
    const html = render( [ {
        name: 'Done', provider: 'gcs', status: 'complete', last_error: '[mainwp_backup_gcs_failed] old',
        error_code: 'mainwp_backup_gcs_failed', error_message: 'old text', error_detail: 'old'
    } ] );
    assert.ok( ! html.includes( 'old' ), html );
} );
