<?php
/**
* MainWP Backups Cron.
* 
* Include cron/bootstrap.php & run mainwp_cronbackups_action
* 
*/

// include cron/bootstrap.php
include_once('bootstrap.php');

// fire off mainWP->mainwp_cronbackups_action
$mainWP->mainwp_cronbackups_action();
