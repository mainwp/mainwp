<?php
/**
 * MainWP Backups Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronbackups_action.
 *
 * @package MainWP/Backups
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

// fire off mainWP->mainwp_cronbackups_action.
$mainWP->mainwp_cronbackups_action();
