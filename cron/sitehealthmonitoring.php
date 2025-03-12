<?php
/**
 * MainWP Check Childs Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronchecksitehealth_action.
 *
 * @package MainWP/Dashboard
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php'; // NOSONAR - WP compatible.

if ( isset( $mainWP ) ) {
    // fire off mainWP->mainwp_cronchecksitehealth_action.
    $mainWP->mainwp_cronchecksitehealth_action();
}
