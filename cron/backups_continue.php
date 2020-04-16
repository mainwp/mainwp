<?php
/**
 * MainWP Backups Continue Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronbackups_continue_action.
 *
 * @package MainWP/Backups_Continue
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

// fire off mainWP->mainwp_cronbackups_continue_action.
$mainWP->mainwp_cronbackups_continue_action();
