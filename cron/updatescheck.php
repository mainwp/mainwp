<?php
/**
 * MainWP Updates Check Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronupdatescheck_action.
 *
 * @package MainWP/Updates_Check
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php'; // NOSONAR - WP compatible.

if ( isset( $mainWP ) ) {
    // fire off mainWP->mainwp_cronupdatescheck_action.
    $mainWP->mainwp_cronupdatescheck_action();
}
