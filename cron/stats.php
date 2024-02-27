<?php
/**
 * MainWP Stats Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronreconnect_action.
 *
 * @package MainWP/Stats
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

// fire off mainWP->mainwp_cronreconnect_action.
$mainWP->mainwp_cronreconnect_action();
