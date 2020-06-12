<?php
/**
 * MainWP Stats Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronstats_action.
 *
 * @package MainWP/Stats
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

// fire off mainWP->mainwp_cronstats_action.
$mainWP->mainwp_cronstats_action();
