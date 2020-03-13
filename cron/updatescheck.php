<?php
/**
 * MainWP Updates Check Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronupdatescheck_action.
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

// fire off mainWP->mainwp_cronupdatescheck_action.
$mainWP->mainwp_cronupdatescheck_action();
