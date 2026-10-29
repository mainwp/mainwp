/**
 * Cancel responses must preserve polling until the run's final result arrives.
 *
 * @package MainWP/Dashboard
 */
( function ( root ) {
    'use strict';

    function describeCancelResult( result ) {
        const known = [ 'cancelled', 'already_finished', 'already_failed', 'busy', 'no_active_on_site', 'not_running' ];
        const recognized = known.indexOf( result ) !== -1;
        const messages = root.mainwpBackupCancelMessages || {};
        return {
            keepPolling: recognized && result !== 'cancelled',
            message: messages[ recognized ? result : 'failed' ] || ''
        };
    }

    const api = { describeCancelResult: describeCancelResult };
    if ( typeof module === 'object' && module.exports ) {
        module.exports = api;
    } else {
        root.MainWPBackupCancelResult = api;
    }
}( typeof globalThis !== 'undefined' ? globalThis : this ) );
