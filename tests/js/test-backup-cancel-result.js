'use strict';
const assert = require( 'node:assert/strict' );
global.mainwpBackupCancelMessages = {
    cancelled: 'Backup cancelled.',
    already_finished: 'Backup finished. Collecting files...',
    already_failed: 'Backup failed. Checking the result...',
    busy: 'Backup is busy. Try again shortly.',
    no_active_on_site: 'No active backup found. Checking the run...',
    not_running: 'No backup is running.',
    failed: 'Could not cancel the backup. Try again.'
};
const { describeCancelResult } = require( '../../assets/js/mainwp-backup-cancel-result.js' );
for ( const result of [ 'cancelled', 'already_finished', 'already_failed', 'busy', 'no_active_on_site', 'not_running' ] ) {
    assert.deepEqual( describeCancelResult( result ), {
        keepPolling: result !== 'cancelled', message: global.mainwpBackupCancelMessages[ result ]
    } );
}
for ( const result of [ undefined, null, '', 'ok', 'unknown', '__proto__' ] ) {
    assert.deepEqual( describeCancelResult( result ), { keepPolling: false, message: global.mainwpBackupCancelMessages.failed } );
}
console.log( 'OK (12 cancel result cases)' );
