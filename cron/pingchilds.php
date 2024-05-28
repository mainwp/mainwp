<?php
/**
 * MainWP Ping Childs Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronpingchilds_action.
 *
 * @package MainWP/PingChilds
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php'; // NOSONAR - WP compatible.

// fire off mainWP->cronpingchilds_action.
$mainWP->mainwp_cronpingchilds_action();
