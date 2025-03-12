<?php
/**
 * MainWP Check Childs Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cron_uptime_monitoring_check_action.
 *
 * @package MainWP/Dashboard
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php'; // NOSONAR - WP compatible.

if ( isset( $mainWP ) ) {
    // fire off.
    $mainWP->mainwp_cron_uptime_monitoring_check_action();
}
